<?php

declare(strict_types=1);

namespace MySQLDumpScheduler;

use RuntimeException;

class SlackNotifier
{
    public function __construct(private readonly array $settings)
    {
    }

    public function __invoke(string $message): bool
    {
        if ($this->settings['slack-url'] === '') {
            return true;
        }
        if (!extension_loaded('curl')) {
            throw new RuntimeException('Slack notifications require the PHP curl extension.');
        }
        $curl = curl_init($this->settings['slack-url']);
        if ($curl === false) {
            throw new RuntimeException('Cannot initialize Slack notifications.');
        }
        try {
            $options = [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode(['text' => $message], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_CONNECTTIMEOUT => min(5, $this->settings['slack-timeout']),
                CURLOPT_TIMEOUT => $this->settings['slack-timeout'],
            ];
            if ($this->settings['slack-ca-bundle'] !== '') {
                $options[CURLOPT_CAINFO] = $this->settings['slack-ca-bundle'];
            }
            curl_setopt_array($curl, $options);
            $response = curl_exec($curl);
            $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            if ($response === false || $status < 200 || $status >= 300 || trim($response) !== 'ok') {
                throw new RuntimeException(sprintf('Slack delivery failed (HTTP %d, curl %d).', $status, curl_errno($curl)));
            }
            return true;
        } finally {
            curl_close($curl);
        }
    }
}
