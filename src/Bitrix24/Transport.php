<?php

declare(strict_types=1);

namespace CallSync\Bitrix24;

/**
 * Sends one HTTP POST and returns the status code and raw body.
 * Kept as an interface so the client can be tested without a network.
 */
interface Transport
{
    /**
     * @param array<string, mixed> $payload
     * @return array{status: int, body: string}
     */
    public function post(string $url, array $payload): array;
}
