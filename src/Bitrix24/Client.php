<?php

declare(strict_types=1);

namespace CallSync\Bitrix24;

/**
 * Minimal Bitrix24 REST client for an inbound webhook URL
 * (https://portal.bitrix24.com/rest/{user_id}/{token}/).
 *
 * - retries on QUERY_LIMIT_EXCEEDED and 502/503 with exponential backoff
 * - listAll() follows the "next" cursor of list methods (50 items per page)
 * - batch() sends up to 50 commands in one request
 */
final class Client
{
    private const BATCH_LIMIT = 50;

    /** @var callable(int): void */
    private $sleeper;

    public function __construct(
        private readonly string $webhookUrl,
        private readonly Transport $transport = new CurlTransport(),
        private readonly int $maxRetries = 3,
        ?callable $sleeper = null,
    ) {
        $this->sleeper = $sleeper ?? static fn (int $ms) => usleep($ms * 1000);
    }

    /**
     * Calls one REST method and returns the full decoded response
     * (result, next, total, time).
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function call(string $method, array $params = []): array
    {
        $url = rtrim($this->webhookUrl, '/') . '/' . $method . '.json';
        $attempt = 0;

        while (true) {
            try {
                return $this->send($url, $method, $params);
            } catch (Bitrix24Exception $e) {
                if (!$e->isRetryable() || $attempt >= $this->maxRetries) {
                    throw $e;
                }
                ($this->sleeper)(500 * (2 ** $attempt)); // 0.5s, 1s, 2s
                $attempt++;
            }
        }
    }

    /** Shortcut: returns only the "result" part of the response. */
    public function result(string $method, array $params = []): mixed
    {
        return $this->call($method, $params)['result'] ?? null;
    }

    /**
     * Reads every page of a list method (crm.deal.list, crm.contact.list, ...).
     *
     * @param array<string, mixed> $params
     * @return list<array<string, mixed>>
     */
    public function listAll(string $method, array $params = []): array
    {
        $items = [];
        $start = 0;

        do {
            $response = $this->call($method, $params + ['start' => $start]);
            foreach ($response['result'] ?? [] as $row) {
                $items[] = $row;
            }
            $start = $response['next'] ?? null;
        } while ($start !== null);

        return $items;
    }

    /**
     * Runs several commands in one request. Keys of $commands are kept,
     * so results can be matched back to the caller's own IDs.
     *
     * @param array<string, array{0: string, 1?: array<string, mixed>}> $commands
     * @return array{result: array<string, mixed>, errors: array<string, mixed>}
     */
    public function batch(array $commands, bool $haltOnError = false): array
    {
        $results = [];
        $errors = [];

        foreach (array_chunk($commands, self::BATCH_LIMIT, true) as $chunk) {
            $cmd = [];
            foreach ($chunk as $key => [$method, $params]) {
                $cmd[$key] = $method . '?' . http_build_query($params ?? []);
            }

            $response = $this->result('batch', ['halt' => $haltOnError ? 1 : 0, 'cmd' => $cmd]);
            $results += $response['result'] ?? [];
            $errors += $response['result_error'] ?? [];
        }

        return ['result' => $results, 'errors' => $errors];
    }

    /** @return array<string, mixed> */
    private function send(string $url, string $method, array $params): array
    {
        $response = $this->transport->post($url, $params);
        $status = $response['status'];

        if ($status === 502 || $status === 503) {
            throw new Bitrix24Exception('HTTP_' . $status, '', $method);
        }

        $data = json_decode($response['body'], true);
        if (!is_array($data)) {
            throw new Bitrix24Exception('INVALID_RESPONSE', "HTTP {$status}", $method);
        }

        if (isset($data['error'])) {
            throw new Bitrix24Exception((string) $data['error'], (string) ($data['error_description'] ?? ''), $method);
        }

        return $data;
    }
}
