<?php
require_once INCLUDE_DIR.'class.plugin.php';
require_once INCLUDE_DIR.'class.signal.php';
require_once __DIR__.'/config.php';

class DiscordPlugin extends Plugin {
    var $config_class = 'DiscordConfig';

    function bootstrap() {
        $this->debugLog('bootstrap() called, connecting signals');
        Signal::connect('ticket.created', [$this, 'onTicketCreated']);
        Signal::connect('threadentry.created', [$this, 'onAgentReply'], 'ResponseThreadEntry');
        Signal::connect('threadentry.created', [$this, 'onNewMessage'], 'MessageThreadEntry');
        // osTicket has no dedicated "status changed" signal, so status/close
        // transitions are detected off the generic ORM save signal instead.
        Signal::connect('model.updated', [$this, 'onModelUpdated'], 'Ticket');
    }

    // Temporary debug helper: writes to osTicket's own System Logs (Admin
    // Panel > Dashboard > System Logs) so it's visible without any
    // filesystem/server-log access. Remove once the "no webhook fires"
    // issue is diagnosed. The trailing `false` disables emailing the admin
    // on every single log entry.
    protected function debugLog($msg) {
        global $ost;
        if ($ost)
            $ost->logWarning('DiscordPlugin debug', $msg, false);
    }

    function onTicketCreated($ticket) {
        $message = '';
        if ($entry = $ticket->getLastMessage())
            $message = $this->cleanMessage((string) $entry->getBody());

        $this->dispatch('notify_new_ticket', 'tpl_new_ticket', $ticket, ['message' => $message]);
    }

    function onAgentReply($entry) {
        $this->onThreadEntry($entry, 'notify_agent_reply', 'tpl_agent_reply');
    }

    function onNewMessage($entry) {
        $this->onThreadEntry($entry, 'notify_new_message', 'tpl_new_message');
    }

    protected function onThreadEntry($entry, $enableKey, $tplKey) {
        if (!($thread = $entry->getThread()) || $thread->getObjectType() != 'T')
            return;

        if (!($ticket = $thread->getObject()) || !($ticket instanceof Ticket))
            return;

        $this->dispatch($enableKey, $tplKey, $ticket, [
            'agent'   => (string) $entry->getPoster(),
            'message' => $this->cleanMessage((string) $entry->getBody()),
        ]);
    }

    function onModelUpdated($ticket, $data) {
        if (!isset($data['dirty']['status_id']))
            return;

        $oldStatusId = $data['dirty']['status_id'];
        $newStatus   = $ticket->getStatus();
        if (!$newStatus || $newStatus->getId() == $oldStatusId)
            return;

        $oldStatus = TicketStatus::lookup($oldStatusId);
        $vars = [
            'status'     => $newStatus->getName(),
            'old_status' => $oldStatus ? $oldStatus->getName() : '',
            'agent'      => ($staff = $ticket->getStaff()) ? (string) $staff->getName() : '',
        ];

        if ($newStatus->getState() == 'closed')
            $this->dispatch('notify_closed', 'tpl_closed', $ticket, $vars);
        else
            $this->dispatch('notify_status_change', 'tpl_status_change', $ticket, $vars);
    }

    protected function dispatch($enableKey, $tplKey, $ticket, $vars) {
        // Signal::send() does not catch exceptions from subscribers, so any
        // error here (missing curl/mbstring extension, network failure, bad
        // template, etc.) would otherwise bubble up and abort the ticket
        // creation/reply request that triggered it. Never let a Discord
        // notification failure break core ticket handling.
        try {
            $instances = $this->getActiveInstances();
            $this->debugLog("dispatch({$enableKey}) called for ticket #{$ticket->getNumber()}, "
                . count($instances) . ' active instance(s)');

            foreach ($instances as $instance) {
                $cfg = $instance->getConfig();

                $url = trim($cfg->get('discord_webhook_url'));
                if (!$url) {
                    $this->debugLog("  instance {$instance->getId()}: skipped, no webhook URL configured");
                    continue;
                }
                if (!$cfg->get($enableKey)) {
                    $this->debugLog("  instance {$instance->getId()}: skipped, '{$enableKey}' not enabled");
                    continue;
                }

                $username   = trim($cfg->get('discord_username')) ?: 'osTicket';
                $avatar_url = trim($cfg->get('discord_avatar_url')) ?: '';
                $mention    = ($enableKey == 'notify_new_ticket') ? trim($cfg->get('discord_mention')) : '';

                // {mention} is rendered blank in the embed description: a
                // role/user mention placed *inside* an embed does not
                // trigger a Discord notification, so it's sent separately
                // as top-level message content instead.
                $description = $this->renderTemplate($cfg->get($tplKey), $ticket, $vars + ['mention' => '']);
                $embed       = $this->buildEmbed($enableKey, $ticket, $vars, $description);

                $this->debugLog("  instance {$instance->getId()}: posting to Discord (embed)");
                $this->postToDiscord($url, $username, $avatar_url, $mention, $embed);
            }
        } catch (\Throwable $e) {
            error_log('DiscordPlugin dispatch error: ' . $e->getMessage());
            $this->debugLog('dispatch error: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
        }
    }

    protected function renderTemplate($tpl, $ticket, $vars) {
        $map = [
            '{mention}'    => $vars['mention'] ?? '',
            '{id}'         => $ticket->getId(),
            '{number}'     => $ticket->getNumber(),
            '{subject}'    => $this->stripHtml($ticket->getSubject()),
            '{name}'       => (string) $ticket->getName(),
            '{email}'      => $ticket->getEmail(),
            '{department}' => $ticket->getDeptName(),
            '{priority}'   => (string) $ticket->getPriority(),
            '{status}'     => $vars['status'] ?? '',
            '{old_status}' => $vars['old_status'] ?? '',
            '{agent}'      => $vars['agent'] ?? '',
            '{message}'    => $vars['message'] ?? '',
        ];

        $content = trim(strtr((string) $tpl, $map));
        if (mb_strlen($content) > 1900)
            $content = mb_substr($content, 0, 1900) . '…';

        return $content;
    }

    protected function cleanMessage($html) {
        // Decode entities BEFORE stripping tags: if the body has an escaped
        // tag (e.g. "&lt;p&gt;"), strip_tags() ignores it as plain text, and
        // decoding afterwards would then reveal a literal "<p>" in the
        // output. Decoding first lets strip_tags() catch it either way.
        $html = html_entity_decode((string) $html, ENT_QUOTES, 'UTF-8');
        $html = preg_replace('/<\/(p|div|li)>|<br\s*\/?>/i', "\n", $html);
        $text = trim(strip_tags($html));
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        if (mb_strlen($text) > 500)
            $text = mb_substr($text, 0, 500) . '…';

        return $text;
    }

    // Ticket subjects can come out of osTicket wrapped in rich-text markup
    // (e.g. "<p>Some subject</p>"), which must not leak into the embed as
    // literal tags. Unlike cleanMessage(), this collapses everything onto a
    // single line since a subject is never meant to wrap into paragraphs.
    protected function stripHtml($html) {
        $html = html_entity_decode((string) $html, ENT_QUOTES, 'UTF-8');
        $text = trim(strip_tags($html));
        return preg_replace('/\s+/', ' ', $text);
    }

    // Per-event display metadata for the embed: an emoji + label for the
    // title, and a Discord embed color (decimal RGB).
    protected function eventMeta($enableKey) {
        $meta = [
            'notify_new_ticket'    => ['emoji' => '🎫', 'label' => 'Nouveau ticket',           'color' => 0x57F287],
            'notify_new_message'   => ['emoji' => '💬', 'label' => 'Nouveau message',           'color' => 0x5865F2],
            'notify_agent_reply'   => ['emoji' => '🗨️', 'label' => 'Réponse',                   'color' => 0x3498DB],
            'notify_status_change' => ['emoji' => '🔄', 'label' => 'Changement de statut',       'color' => 0xE67E22],
            'notify_closed'        => ['emoji' => '✅', 'label' => 'Ticket fermé',               'color' => 0xED4245],
        ];
        return $meta[$enableKey] ?? ['emoji' => '🔔', 'label' => 'Notification', 'color' => 0x99AAB5];
    }

    protected function buildEmbed($enableKey, $ticket, $vars, $description) {
        global $cfg;

        $meta = $this->eventMeta($enableKey);

        // Discord always renders `description` above `fields`, regardless of
        // key order in the payload, so Sujet/Contenu (which must appear
        // above Demandeur/Département/Agent) are folded into the
        // description rather than added as fields.
        $subject  = $this->stripHtml($ticket->getSubject()) ?: '—';
        $sections = ["**Sujet**\n{$subject}"];
        if ($description !== '')
            $sections[] = "**Contenu du ticket**\n{$description}";

        $fields = [
            ['name' => 'Demandeur',   'value' => sprintf('%s (%s)', (string) $ticket->getName(), $ticket->getEmail()), 'inline' => true],
            ['name' => 'Département', 'value' => (string) $ticket->getDeptName() ?: '—', 'inline' => true],
        ];

        if ($enableKey == 'notify_new_ticket')
            $fields[] = ['name' => 'Priorité', 'value' => (string) $ticket->getPriority() ?: '—', 'inline' => true];

        if (!empty($vars['agent']))
            $fields[] = ['name' => 'Agent', 'value' => $vars['agent'], 'inline' => true];

        if (isset($vars['status']))
            $fields[] = ['name' => 'Statut', 'value' => sprintf('%s → %s', $vars['old_status'] ?: '—', $vars['status']), 'inline' => true];

        return [
            'title'       => sprintf('%s %s — Ticket #%s', $meta['emoji'], $meta['label'], $ticket->getNumber()),
            'url'         => rtrim($cfg->getUrl(), '/') . '/scp/tickets.php?id=' . $ticket->getId(),
            'description' => implode("\n\n", $sections),
            'color'       => $meta['color'],
            'fields'      => $fields,
            'footer'      => ['text' => 'osTicket'],
            'timestamp'   => date('c'),
        ];
    }

    protected function postToDiscord($webhookUrl, $username, $avatarUrl, $content, $embed) {
        $payload = [
            'username'   => $username,
            'avatar_url' => $avatarUrl,
            'embeds'     => [$embed],
        ];
        if ($content !== '')
            $payload['content'] = $content;

        $ch = curl_init($webhookUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            // This runs synchronously inside the ticket create/reply request
            // (osTicket signals have no async/queue option), so keep both
            // timeouts short: if Discord/network is unreachable, a slow
            // default (or no connect timeout at all) blocks a web worker for
            // the full duration on every single ticket action, which can
            // exhaust the worker pool and take the whole site down.
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT        => 5,
        ]);
        $resp     = curl_exec($ch);
        $errno    = curl_errno($ch);
        $errmsg   = curl_error($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno || $httpcode >= 300) {
            error_log("DiscordPlugin webhook error: code={$httpcode} errno={$errno} err='{$errmsg}' resp='{$resp}'");
            $this->debugLog("webhook error: code={$httpcode} errno={$errno} err='{$errmsg}' resp='{$resp}'");
        } else {
            $this->debugLog("webhook post succeeded: code={$httpcode}");
        }
    }
}
