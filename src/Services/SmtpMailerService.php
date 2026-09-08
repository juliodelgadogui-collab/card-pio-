<?php

declare(strict_types=1);

namespace EventMenu\Services;

use RuntimeException;

final class SmtpMailerService
{
    public function send(?int $tenantId, string $toEmail, string $toName, string $subject, string $html, string $text = ''): void
    {
        $toEmail = mb_strtolower(trim($toEmail));
        if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Destinatário de e-mail inválido.');
        $settings = (new MailSettingsService())->effective($tenantId);
        if (!$settings) throw new RuntimeException('O envio de e-mail ainda não foi configurado.');

        $host = trim((string)$settings['host']);
        $port = (int)$settings['port'];
        $encryption = (string)$settings['encryption'];
        $transport = $encryption === 'ssl' ? 'ssl://' : 'tcp://';
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false,
                'peer_name' => $host,
                'SNI_enabled' => true,
            ],
        ]);
        $errno = 0; $errstr = '';
        $socket = @stream_socket_client($transport.$host.':'.$port, $errno, $errstr, 12, STREAM_CLIENT_CONNECT, $context);
        if (!is_resource($socket)) throw new RuntimeException('Não foi possível conectar ao servidor SMTP.');
        stream_set_timeout($socket, 12);

        try {
            $this->expect($socket, [220]);
            $this->command($socket, 'EHLO '.($this->clientName()), [250]);
            if ($encryption === 'tls') {
                $this->command($socket, 'STARTTLS', [220]);
                if (!@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) throw new RuntimeException('O servidor SMTP não aceitou a conexão TLS segura.');
                $this->command($socket, 'EHLO '.($this->clientName()), [250]);
            }

            $username = trim((string)($settings['username'] ?? ''));
            $password = (string)($settings['password'] ?? '');
            if ($username !== '') {
                $this->command($socket, 'AUTH LOGIN', [334]);
                $this->command($socket, base64_encode($username), [334]);
                $this->command($socket, base64_encode($password), [235]);
            }

            $fromEmail = (string)$settings['from_email'];
            $fromName = trim((string)($settings['from_name'] ?? 'EventMenu')) ?: 'EventMenu';
            $this->command($socket, 'MAIL FROM:<'.$fromEmail.'>', [250]);
            $this->command($socket, 'RCPT TO:<'.$toEmail.'>', [250,251]);
            $this->command($socket, 'DATA', [354]);

            $boundary = '=_eventmenu_'.bin2hex(random_bytes(12));
            $subjectEncoded = mb_encode_mimeheader($this->cleanHeader($subject), 'UTF-8', 'B', "\r\n");
            $headers = [
                'Date: '.date(DATE_RFC2822),
                'Message-ID: <'.bin2hex(random_bytes(16)).'@'.$this->clientName().'>',
                'From: '.$this->formatAddress($fromEmail, $fromName),
                'To: '.$this->formatAddress($toEmail, $toName),
                'Subject: '.$subjectEncoded,
                'MIME-Version: 1.0',
                'Content-Type: multipart/alternative; boundary="'.$boundary.'"',
            ];
            $replyTo = trim((string)($settings['reply_to_email'] ?? ''));
            if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) $headers[] = 'Reply-To: <'.$replyTo.'>';

            if ($text === '') $text = trim(html_entity_decode(strip_tags(preg_replace('/<br\s*\/?>/i', "\n", $html) ?? $html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $body = implode("\r\n", $headers)."\r\n\r\n";
            $body .= '--'.$boundary."\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n".quoted_printable_encode($text)."\r\n";
            $body .= '--'.$boundary."\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n".quoted_printable_encode($html)."\r\n";
            $body .= '--'.$boundary."--\r\n";
            $body = preg_replace('/(?m)^\./', '..', $body) ?? $body;
            fwrite($socket, $body.".\r\n");
            $this->expect($socket, [250]);
            $this->command($socket, 'QUIT', [221]);
        } finally {
            fclose($socket);
        }
    }

    /** @param resource $socket @param list<int> $codes */
    private function command($socket, string $command, array $codes): string
    {
        if (fwrite($socket, $command."\r\n") === false) throw new RuntimeException('Falha ao comunicar com o servidor SMTP.');
        return $this->expect($socket, $codes);
    }

    /** @param resource $socket @param list<int> $codes */
    private function expect($socket, array $codes): string
    {
        $response = '';
        while (($line = fgets($socket, 8192)) !== false) {
            $response .= $line;
            if (strlen($line) >= 4 && $line[3] === ' ') break;
        }
        $meta = stream_get_meta_data($socket);
        if (!empty($meta['timed_out'])) throw new RuntimeException('Tempo esgotado ao falar com o servidor SMTP.');
        $code = (int)substr($response, 0, 3);
        if (!in_array($code, $codes, true)) {
            $safe = trim(preg_replace('/[\r\n]+/', ' ', $response) ?? '');
            throw new RuntimeException('Servidor SMTP recusou a operação ('.$code.'). '.mb_substr($safe, 0, 180));
        }
        return $response;
    }

    private function clientName(): string
    {
        $host = parse_url((string)env('APP_URL', ''), PHP_URL_HOST);
        $host = is_string($host) ? preg_replace('/[^a-z0-9.-]/i', '', $host) : '';
        return $host ?: 'eventmenu.local';
    }

    private function cleanHeader(string $value): string
    {
        return trim(str_replace(["\r","\n"], '', $value));
    }

    private function formatAddress(string $email, string $name): string
    {
        $name = $this->cleanHeader($name);
        if ($name === '') return '<'.$email.'>';
        return mb_encode_mimeheader($name, 'UTF-8', 'B', "\r\n").' <'.$email.'>';
    }
}
