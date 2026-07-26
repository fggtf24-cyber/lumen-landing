<?php
/**
 * Минимальный SMTP-клиент без внешних зависимостей.
 * Умеет ровно то, что нужно форме заявки: подключиться, авторизоваться,
 * отправить одно UTF-8 письмо.
 */

class SmtpException extends RuntimeException {}

class Smtp
{
    private $socket;
    private $config;
    private $timeout = 20;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * @param string[] $to
     * @throws SmtpException
     */
    public function send(array $to, $subject, $body, $replyTo = null)
    {
        $this->connect();

        try {
            $this->handshake();
            $this->authenticate();

            $this->command('MAIL FROM:<' . $this->config['from'] . '>', [250]);
            foreach ($to as $address) {
                $this->command('RCPT TO:<' . $address . '>', [250, 251]);
            }

            $this->command('DATA', [354]);
            $this->write($this->buildMessage($to, $subject, $body, $replyTo) . "\r\n.");
            $this->expect([250]);

            $this->command('QUIT', [221]);
        } finally {
            $this->disconnect();
        }
    }

    private function connect()
    {
        $host = $this->config['host'];
        if ($this->config['secure'] === 'ssl') {
            $host = 'ssl://' . $host;
        }

        $context = stream_context_create([
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);

        $this->socket = @stream_socket_client(
            $host . ':' . $this->config['port'],
            $errno,
            $errstr,
            $this->timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if (!$this->socket) {
            throw new SmtpException("Не удалось подключиться к SMTP: $errstr ($errno)");
        }

        stream_set_timeout($this->socket, $this->timeout);
        $this->expect([220]);
    }

    private function handshake()
    {
        $ehlo = 'EHLO ' . $this->clientName();
        $this->command($ehlo, [250]);

        if ($this->config['secure'] === 'tls') {
            $this->command('STARTTLS', [220]);

            $ok = @stream_socket_enable_crypto(
                $this->socket,
                true,
                STREAM_CRYPTO_METHOD_TLS_CLIENT
            );
            if (!$ok) {
                throw new SmtpException('Не удалось включить шифрование TLS');
            }

            // после STARTTLS сессию нужно начать заново
            $this->command($ehlo, [250]);
        }
    }

    private function authenticate()
    {
        if ($this->config['user'] === '') {
            return;
        }

        $this->command('AUTH LOGIN', [334]);
        $this->command(base64_encode($this->config['user']), [334]);
        $this->command(base64_encode($this->config['password']), [235]);
    }

    private function buildMessage(array $to, $subject, $body, $replyTo)
    {
        $headers = [
            'Date: ' . date('r'),
            'From: ' . $this->encodeHeader($this->config['from_name']) . ' <' . $this->config['from'] . '>',
            'To: ' . implode(', ', $to),
            'Subject: ' . $this->encodeHeader($subject),
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $this->clientName() . '>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
        ];

        if ($replyTo !== null && $replyTo !== '') {
            $headers[] = 'Reply-To: ' . $replyTo;
        }

        $encoded = chunk_split(base64_encode($body), 76, "\r\n");

        return implode("\r\n", $headers) . "\r\n\r\n" . $encoded;
    }

    /** Заголовки с кириллицей нужно кодировать, иначе получим кракозябры. */
    private function encodeHeader($value)
    {
        if (preg_match('//u', $value) && !preg_match('/[^\x20-\x7E]/', $value)) {
            return $value;
        }
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }

    private function clientName()
    {
        $host = isset($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_NAME'] : 'localhost';
        return preg_replace('/[^A-Za-z0-9.\-]/', '', $host) ?: 'localhost';
    }

    private function command($line, array $expected)
    {
        $this->write($line);
        return $this->expect($expected);
    }

    private function write($line)
    {
        if (fwrite($this->socket, $line . "\r\n") === false) {
            throw new SmtpException('Обрыв соединения при отправке команды SMTP');
        }
    }

    private function expect(array $codes)
    {
        $response = '';

        while (($line = fgets($this->socket, 515)) !== false) {
            $response .= $line;
            // многострочный ответ: "250-..." продолжается, "250 ..." завершает
            if (!isset($line[3]) || $line[3] !== '-') {
                break;
            }
        }

        if ($response === '') {
            throw new SmtpException('Пустой ответ SMTP-сервера');
        }

        $code = (int) substr($response, 0, 3);
        if (!in_array($code, $codes, true)) {
            throw new SmtpException('SMTP ответил: ' . trim($response));
        }

        return $response;
    }

    private function disconnect()
    {
        if (is_resource($this->socket)) {
            @fclose($this->socket);
        }
        $this->socket = null;
    }
}
