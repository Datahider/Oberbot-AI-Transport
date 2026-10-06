<?php

declare(strict_types=1);

use CodexMultitenant\TransportTelegram\RuntimeFactory;

require __DIR__ . '/src/bootstrap.php';

$config_path = getenv('TELEGRAM_TRANSPORT_CONFIG');
if (!is_string($config_path) || trim($config_path) === '') {
    $config_path = __DIR__ . '/etc/config.php';
}

$headers = function_exists('getallheaders') ? getallheaders() : [];
$body = file_get_contents('php://input');
if ($body === false) {
    http_response_code(500);
    exit;
}

$response = RuntimeFactory::createWebhookEndpoint($config_path)->handle(
    (string) ($_SERVER['REQUEST_METHOD'] ?? ''),
    is_array($headers) ? $headers : [],
    $body
);

http_response_code($response->status_code);
if ($response->body !== '') {
    header('Content-Type: application/json; charset=utf-8');
    echo $response->body;
}
