<?php

namespace App\Services\Restaurant\Sync;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * HTTPS-запросы к онлайн-серверу: GET /api/restaurant/sync/ping, POST …/push, GET …/pull,
 * заголовок Authorization: Bearer <sync-токен ресторана>.
 */
class HttpSyncTransport implements SyncTransport
{
    public function __construct(
        private readonly string $url,
        private readonly string $token,
        private readonly int $timeout,
    ) {}

    public function ping(): array
    {
        return $this->request('get', 'ping');
    }

    public function push(array $payload): array
    {
        return $this->request('post', 'push', $payload);
    }

    public function pull(): array
    {
        return $this->request('get', 'pull');
    }

    private function request(string $method, string $path, array $body = []): array
    {
        try {
            $request = Http::baseUrl(rtrim($this->url, '/').'/api/restaurant/sync')
                ->withToken($this->token)
                ->acceptJson()
                ->withUserAgent('restaurant-local-server')
                ->connectTimeout(10)
                ->timeout($this->timeout);
            /** @var Response $response */
            $response = $method === 'post' ? $request->post($path, $body) : $request->get($path);
        } catch (ConnectionException $e) {
            throw new SyncException('Нет связи с онлайн-сервером ('.$this->url.'): '.$e->getMessage());
        }

        $message = (string) ($response->json('message') ?? '');
        match (true) {
            $response->status() === 401 => throw new SyncException('Онлайн-сервер не принял sync-токен. Выпустите новый токен в онлайн-админке и введите его здесь'),
            $response->status() === 404 => throw new SyncException('По адресу '.$this->url.' нет онлайн-сервера ресторана'.($message !== '' ? ': '.$message : ' — проверьте адрес и DEPLOY_MODE=online на нём')),
            $response->failed() => throw new SyncException('Онлайн-сервер ответил ошибкой '.$response->status().($message !== '' ? ': '.$message : '')),
            default => null,
        };

        $json = $response->json();
        if (! is_array($json)) {
            throw new SyncException('Онлайн-сервер вернул неожиданный ответ — проверьте адрес');
        }

        return $json;
    }
}
