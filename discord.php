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

    // Temporary debug helper: writes to a file inside the plugin's own
    // directory so it can be inspected via FTP/file manager even when the
    // server's PHP/webserver error log isn't accessible. Remove once the
    // "no webhook fires" issue is diagnosed.
    protected function debugLog($msg) {
        @file_put_contents(
            __DIR__ . '/discord_debug.log',
            '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n",
            FILE_APPEND
        );
    }

    function onTicketCreated($ticket) {
        $this->dispatch('notify_new_ticket', 'tpl_new_ticket', $ticket, []);
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

                $content = $this->renderTemplate($cfg->get($tplKey), $ticket, $vars + ['mention' => $mention]);
                $this->debugLog("  instance {$instance->getId()}: posting to Discord, content length "
                    . mb_strlen($content));
                $this->postToDiscord($url, $username, $avatar_url, $content);
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
            '{subject}'    => $ticket->getSubject(),
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
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8');
        $text = trim(preg_replace('/\s+/', ' ', $text));
        if (mb_strlen($text) > 500)
            $text = mb_substr($text, 0, 500) . '…';

        return $text;
    }

    protected function postToDiscord($webhookUrl, $username, $avatarUrl, $content) {
        if ($content === '')
            return;

        $payload = [
            'username'   => $username,
            'avatar_url' => $avatarUrl,
            'content'    => $content,
        ];

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
?>
