<?php

declare(strict_types=1);

namespace CodexMultitenant\TransportTelegram\Router;

use CodexMultitenant\TransportTelegram\RouterClientInterface;
use RuntimeException;

final class ApiClient implements RouterClientInterface
{
    public function __construct(
        private string $base_url,
        private string $token,
        private HttpClientInterface $http
    ) {
        $this->base_url = rtrim(trim($this->base_url), '/');
        $this->token = trim($this->token);

        if ($this->base_url === '') {
            throw new RuntimeException('Router base URL cannot be empty');
        }

        if ($this->token === '') {
            throw new RuntimeException('Router token cannot be empty');
        }
    }

    public function postIngress(array $payload): array
    {
        return $this->postJson('/api/v2/transport/ingress', $payload);
    }

    public function getOutboundEvents(int $after_id, int $wait, int $limit): array
    {
        $response = $this->getJson('/api/v1/transport/outbound', [
            'after_id' => max(0, $after_id),
            'wait' => max(0, $wait),
            'limit' => max(1, $limit),
        ]);

        return is_array($response['events'] ?? null) ? array_values($response['events']) : [];
    }

    /**
     * @param array<string, scalar|null> $query
     * @return array<string, mixed>
     */
    private function getJson(string $path, array $query = []): array
    {
        $url = $this->base_url . $path;
        $query_string = http_build_query(array_filter($query, static fn (mixed $value): bool => $value !== null));
        if ($query_string !== '') {
            $url .= '?' . $query_string;
        }

        try {
            return $this->decodeJsonResponse(
                $this->http->request('GET', $url, $this->headers(), null),
                'GET',
                $path
            );
        } catch (RouterAuthException | RouterUnavailableException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            throw new RouterUnavailableException($e->getMessage(), 0, $e);
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function postJson(string $path, array $payload): array
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            throw new RuntimeException("Cannot encode Router request body for {$path}");
        }

        try {
            return $this->decodeJsonResponse(
                $this->http->request('POST', $this->base_url . $path, $this->headers(), $body),
                'POST',
                $path
            );
        } catch (RouterAuthException | RouterUnavailableException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            throw new RouterUnavailableException($e->getMessage(), 0, $e);
        }
    }

    /**
     * @param array{status_code:int,body:string} $response
     * @return array<string, mixed>
     */
    private function decodeJsonResponse(array $response, string $method, string $path): array
    {
        $status_code = (int) ($response['status_code'] ?? 0);
        $raw_body = (string) ($response['body'] ?? '');
        $decoded = json_decode($raw_body, true);
        if (!is_array($decoded)) {
            if ($status_code === 401 || $status_code === 403) {
                throw new RouterAuthException("Router {$method} {$path} rejected credentials");
            }

            throw new RouterUnavailableException("Router {$method} {$path} returned invalid JSON");
        }

        if ($status_code < 200 || $status_code >= 300) {
            $message = trim((string) ($decoded['error'] ?? $decoded['message'] ?? ''));
            if ($message === '') {
                $message = "Router {$method} {$path} failed with HTTP {$status_code}";
            }

            if ($status_code === 401 || $status_code === 403) {
                throw new RouterAuthException($message);
            }

            throw new RouterUnavailableException($message);
        }

        return $decoded;
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->token,
            'Content-Type' => 'application/json',
        ];
    }
}
