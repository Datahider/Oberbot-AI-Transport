<?php

declare(strict_types=1);

namespace CodexMultitenant\TransportTelegram\Router;

use RuntimeException;

final class CurlHttpClient implements HttpClientInterface
{
    public function request(string $method, string $url, array $headers, ?string $body = null): array
    {
        $handle = curl_init($url);
        if ($handle === false) {
            throw new RuntimeException("Cannot initialize cURL for {$url}");
        }

        $curl_headers = [];
        foreach ($headers as $name => $value) {
            $curl_headers[] = $name . ': ' . $value;
        }

        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $curl_headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
        ]);

        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }

        $raw = curl_exec($handle);
        if (!is_string($raw)) {
            $error = curl_error($handle);
            curl_close($handle);
            throw new RuntimeException('Router HTTP request failed: ' . $error);
        }

        $status_code = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        return [
            'status_code' => $status_code,
            'body' => $raw,
        ];
    }
}
