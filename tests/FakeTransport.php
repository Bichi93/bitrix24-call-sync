<?php

declare(strict_types=1);

namespace CallSync\Tests;

use CallSync\Bitrix24\Transport;

/** Returns queued responses and records every request. */
final class FakeTransport implements Transport
{
    /** @var list<array{url: string, payload: array}> */
    public array $requests = [];

    /** @var list<array{status: int, body: string}> */
    private array $queue = [];

    public function push(array $json, int $status = 200): self
    {
        $this->queue[] = ['status' => $status, 'body' => json_encode($json)];
        return $this;
    }

    public function pushRaw(string $body, int $status): self
    {
        $this->queue[] = ['status' => $status, 'body' => $body];
        return $this;
    }

    public function post(string $url, array $payload): array
    {
        $this->requests[] = ['url' => $url, 'payload' => $payload];
        if ($this->queue === []) {
            throw new \LogicException("Unexpected request to {$url}");
        }
        return array_shift($this->queue);
    }

    public function methods(): array
    {
        return array_map(
            static fn (array $r) => basename($r['url'], '.json'),
            $this->requests,
        );
    }
}
