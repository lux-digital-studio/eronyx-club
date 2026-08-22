<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Logger;
use PHPMailer\PHPMailer\PHPMailer;
use Throwable;

final class MailService
{
    /** @var list<array{to: string, to_name: string, subject: string, html: string, text: string, type: string|null}> */
    private static array $outbox = [];

    private static int $failRemaining = 0;

    /** @var array<string, mixed> */
    private array $config;

    public function __construct(?array $config = null)
    {
        $this->config = $config ?? require dirname(__DIR__, 2) . '/config/mail.php';
    }

    /**
     * @param array{type?: string, user_id?: int} $context
     */
    public function send(
        string $toEmail,
        string $toName,
        string $subject,
        string $html,
        string $text,
        array $context = []
    ): bool {
        $type = isset($context['type']) && is_string($context['type']) ? $context['type'] : null;
        $userId = isset($context['user_id']) && is_int($context['user_id']) ? $context['user_id'] : null;

        if (self::$failRemaining > 0) {
            self::$failRemaining--;
            $this->logFailure($type, $userId, 'forced_failure');

            return false;
        }

        if ($this->containsHeaderBreak($toEmail) || $this->containsHeaderBreak($toName) || $this->containsHeaderBreak($subject)) {
            $this->logFailure($type, $userId, 'header_injection');

            return false;
        }

        $toEmail = trim($toEmail);
        $toName = trim($toName);
        $subject = trim($subject);
        $text = trim($text);

        if ($toEmail === '' || filter_var($toEmail, FILTER_VALIDATE_EMAIL) === false) {
            $this->logFailure($type, $userId, 'invalid_recipient');

            return false;
        }

        if ($subject === '' || $html === '' || $text === '') {
            $this->logFailure($type, $userId, 'empty_content');

            return false;
        }

        $mailer = (string) ($this->config['mailer'] ?? 'array');

        if ($mailer === 'array') {
            self::$outbox[] = [
                'to' => $toEmail,
                'to_name' => $toName,
                'subject' => $subject,
                'html' => $html,
                'text' => $text,
                'type' => $type,
            ];

            return true;
        }

        if ($mailer !== 'smtp') {
            $this->logFailure($type, $userId, 'unsupported_mailer');

            return false;
        }

        return $this->sendSmtp($toEmail, $toName, $subject, $html, $text, $type, $userId);
    }

    /**
     * @return list<array{to: string, to_name: string, subject: string, html: string, text: string, type: string|null}>
     */
    public static function sent(): array
    {
        return self::$outbox;
    }

    public static function clear(): void
    {
        self::$outbox = [];
        self::$failRemaining = 0;
    }

    public static function failNext(int $times = 1): void
    {
        self::$failRemaining = max(0, $times);
    }

    private function sendSmtp(
        string $toEmail,
        string $toName,
        string $subject,
        string $html,
        string $text,
        ?string $type,
        ?int $userId
    ): bool {
        $fromAddress = trim((string) ($this->config['from_address'] ?? ''));
        $fromName = (string) ($this->config['from_name'] ?? 'ERONYX');
        $host = trim((string) ($this->config['host'] ?? ''));

        if ($fromAddress === '' || filter_var($fromAddress, FILTER_VALIDATE_EMAIL) === false || $host === '') {
            $this->logFailure($type, $userId, 'smtp_not_configured');

            return false;
        }

        if ($this->containsHeaderBreak($fromName) || $this->containsHeaderBreak($fromAddress) || $this->containsHeaderBreak($host)) {
            $this->logFailure($type, $userId, 'header_injection');

            return false;
        }

        try {
            $mail = new PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = $host;
            $mail->Port = (int) ($this->config['port'] ?? 587);
            $mail->Timeout = (int) ($this->config['timeout'] ?? 10);
            $mail->SMTPDebug = 0;
            $mail->Debugoutput = static function (): void {
            };
            $mail->CharSet = PHPMailer::CHARSET_UTF8;
            $mail->Encoding = PHPMailer::ENCODING_BASE64;

            $username = (string) ($this->config['username'] ?? '');
            $password = (string) ($this->config['password'] ?? '');
            $mail->SMTPAuth = $username !== '';
            $mail->Username = $username;
            $mail->Password = $password;

            $encryption = (string) ($this->config['encryption'] ?? 'tls');
            if ($encryption === 'tls') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            } elseif ($encryption === 'ssl') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } else {
                $mail->SMTPSecure = '';
                $mail->SMTPAutoTLS = false;
            }

            if ($encryption === 'tls' || $encryption === 'ssl') {
                $mail->SMTPOptions = [
                    'ssl' => [
                        'verify_peer' => true,
                        'verify_peer_name' => true,
                        'allow_self_signed' => false,
                    ],
                ];
            }

            $mail->setFrom($fromAddress, $fromName);
            $mail->addAddress($toEmail, $toName);
            $mail->Subject = $subject;
            $mail->isHTML(true);
            $mail->Body = $html;
            $mail->AltBody = $text;
            $mail->send();

            return true;
        } catch (Throwable $exception) {
            $this->logFailure($type, $userId, $exception::class);

            return false;
        }
    }

    private function containsHeaderBreak(string $value): bool
    {
        return strpbrk($value, "\r\n\0") !== false;
    }

    private function logFailure(?string $type, ?int $userId, string $reason): void
    {
        Logger::error('mail_send_failed', [
            'event' => 'mail_send_failed',
            'mail_type' => $type,
            'user_id' => $userId,
            'reason' => $reason,
        ]);
    }
}
