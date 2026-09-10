<?php

declare(strict_types=1);

namespace CodexMultitenant\TransportTelegram;

use RuntimeException;

final class FileExchangeApiClient implements FileExchangeClientInterface
{
    public function __construct(
        private string $base_url,
        private string $token
    ) {
        $this->base_url = rtrim(trim($this->base_url), '/');
        $this->token = trim($this->token);

        if ($this->base_url === '') {
            throw new RuntimeException('File exchange base URL cannot be empty');
        }

        if ($this->token === '') {
            throw new RuntimeException('File exchange token cannot be empty');
        }
    }

    public function uploadFile(string $file_path, string $filename, ?string $mime = null): array
    {
        $file_path = trim($file_path);
        $filename = trim($filename);
        if ($file_path === '' || !is_file($file_path)) {
            throw new RuntimeException('File exchange upload file is missing');
        }

        if ($filename === '') {
            throw new RuntimeException('File exchange upload filename is required');
        }

        $ch = curl_init($this->base_url . '/up');
        if ($ch === false) {
            throw new RuntimeException('Cannot initialize file exchange upload request');
        }

        $file = new \CURLFile($file_path, $mime ?? 'application/octet-stream', $filename);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->token,
                'Accept: application/json',
            ],
            CURLOPT_POSTFIELDS => [
                'file' => $file,
            ],
            CURLOPT_TIMEOUT => 60,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);

        $raw = curl_exec($ch);
        if (!is_string($raw)) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('File exchange upload failed: ' . $error);
        }

        $status_code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        $response = json_decode($raw, true);
        if (!is_array($response)) {
            throw new RuntimeException('File exchange upload returned invalid JSON');
        }

        if ($status_code < 200 || $status_code >= 300) {
            $message = trim((string) ($response['error'] ?? $response['message'] ?? ''));
            if ($message === '') {
                $message = 'HTTP ' . $status_code;
            }

            throw new RuntimeException('File exchange upload failed: ' . $message);
        }

        return $response;
    }

    public function downloadFile(string $file_id, string $filename): string
    {
        $file_id = trim($file_id);
        $filename = basename(trim($filename));
        if ($file_id === '' || preg_match('/^[A-Za-z0-9_-]+$/', $file_id) !== 1) {
            throw new RuntimeException('File exchange download file_id is invalid');
        }
        if ($filename === '') {
            throw new RuntimeException('File exchange download filename is required');
        }

        $temp_path = tempnam(sys_get_temp_dir(), 'telegram-document-');
        if ($temp_path === false) {
            throw new RuntimeException('Cannot create temporary document file');
        }
        $target_path = $temp_path . '-' . $filename;
        if (!rename($temp_path, $target_path)) {
            @unlink($temp_path);
            throw new RuntimeException('Cannot name temporary document file');
        }

        $output = fopen($target_path, 'wb');
        if ($output === false) {
            @unlink($target_path);
            throw new RuntimeException('Cannot open temporary document file');
        }

        $ch = curl_init($this->base_url . '/' . rawurlencode($file_id));
        if ($ch === false) {
            fclose($output);
            @unlink($target_path);
            throw new RuntimeException('Cannot initialize file exchange download request');
        }

        curl_setopt_array($ch, [
            CURLOPT_FILE => $output,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $this->token],
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 120,
        ]);
        $ok = curl_exec($ch);
        $status_code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        fclose($output);

        if ($ok !== true || $status_code < 200 || $status_code >= 300) {
            @unlink($target_path);
            $detail = $error !== '' ? $error : "HTTP {$status_code}";
            throw new RuntimeException('File exchange download failed: ' . $detail);
        }

        return $target_path;
    }
}
