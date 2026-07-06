<?php
require_once INCLUDE_DIR.'class.plugin.php';
require_once INCLUDE_DIR.'class.signal.php';
require_once __DIR__.'/config.php';

class DiscordPlugin extends Plugin {
    var $config_class = 'DiscordConfig';

    function bootstrap() {
        Signal::connect('ticket.created', [$this, 'onTicketCreated']);
        Signal::connect('threadentry.created', [$this, 'onAgentReply'], 'ResponseThreadEntry');
        Signal::connect('threadentry.created', [$this, 'onNewMessage'], 'MessageThreadEntry');
        // osTicket has no dedicated "status changed" signal, so status/close
        // transitions are detected off the generic ORM save signal instead.
        Signal::connect('model.updated', [$this, 'onModelUpdated'], 'Ticket');
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
        foreach ($this->getActiveInstances() as $instance) {
            $cfg = $instance->getConfig();

            $url = trim($cfg->get('discord_webhook_url'));
            if (!$url || !$cfg->get($enableKey))
                continue;

            $username   = trim($cfg->get('discord_username')) ?: 'osTicket';
            $avatar_url = trim($cfg->get('discord_avatar_url')) ?: '';
            $mention    = ($enableKey == 'notify_new_ticket') ? trim($cfg->get('discord_mention')) : '';

            $content = $this->renderTemplate($cfg->get($tplKey), $ticket, $vars + ['mention' => $mention]);
            $this->postToDiscord($url, $username, $avatar_url, $content);
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
            CURLOPT_TIMEOUT        => 10,
        ]);
        $resp     = curl_exec($ch);
        $errno    = curl_errno($ch);
        $errmsg   = curl_error($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno || $httpcode >= 300)
            error_log("DiscordPlugin webhook error: code={$httpcode} errno={$errno} err='{$errmsg}' resp='{$resp}'");
    }
}
?>
