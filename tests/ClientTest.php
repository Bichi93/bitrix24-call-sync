<?php

declare(strict_types=1);

namespace CallSync\Tests;

use CallSync\Bitrix24\Bitrix24Exception;
use CallSync\Bitrix24\Client;
use PHPUnit\Framework\TestCase;

final class ClientTest extends TestCase
{
    private const URL = 'https://demo.bitrix24.com/rest/1/token/';

    public function testBuildsMethodUrlAndReturnsResult(): void
    {
        $t = (new FakeTransport())->push(['result' => ['ID' => 7]]);
        $client = new Client(self::URL, $t);

        self::assertSame(['ID' => 7], $client->result('crm.deal.get', ['id' => 7]));
        self::assertSame(self::URL . 'crm.deal.get.json', $t->requests[0]['url']);
        self::assertSame(['id' => 7], $t->requests[0]['payload']);
    }

    public function testRetriesOnRateLimitWithBackoff(): void
    {
        $t = (new FakeTransport())
            ->push(['error' => 'QUERY_LIMIT_EXCEEDED', 'error_description' => 'Too many requests'])
            ->pushRaw('Service Unavailable', 503)
            ->push(['result' => true]);
        $sleeps = [];
        $client = new Client(self::URL, $t, sleeper: function (int $ms) use (&$sleeps) { $sleeps[] = $ms; });

        self::assertTrue($client->result('crm.deal.update', ['id' => 1]));
        self::assertSame([500, 1000], $sleeps);
        self::assertCount(3, $t->requests);
    }

    public function testDoesNotRetryBusinessErrors(): void
    {
        $t = (new FakeTransport())->push(['error' => 'ACCESS_DENIED', 'error_description' => 'No scope']);
        $client = new Client(self::URL, $t, sleeper: fn () => null);

        $this->expectException(Bitrix24Exception::class);
        $this->expectExceptionMessage('crm.deal.get: ACCESS_DENIED (No scope)');
        $client->call('crm.deal.get', ['id' => 1]);
    }

    public function testGivesUpAfterMaxRetries(): void
    {
        $t = new FakeTransport();
        for ($i = 0; $i < 4; $i++) {
            $t->push(['error' => 'QUERY_LIMIT_EXCEEDED']);
        }
        $client = new Client(self::URL, $t, maxRetries: 3, sleeper: fn () => null);

        try {
            $client->call('crm.deal.list');
            self::fail('Expected exception');
        } catch (Bitrix24Exception $e) {
            self::assertSame('QUERY_LIMIT_EXCEEDED', $e->errorCode);
            self::assertCount(4, $t->requests);
        }
    }

    public function testListAllFollowsNextCursor(): void
    {
        $t = (new FakeTransport())
            ->push(['result' => [['ID' => 1], ['ID' => 2]], 'next' => 50, 'total' => 3])
            ->push(['result' => [['ID' => 3]], 'total' => 3]);
        $client = new Client(self::URL, $t);

        $rows = $client->listAll('crm.contact.list', ['select' => ['ID']]);

        self::assertSame([1, 2, 3], array_column($rows, 'ID'));
        self::assertSame(0, $t->requests[0]['payload']['start']);
        self::assertSame(50, $t->requests[1]['payload']['start']);
    }

    public function testBatchSplitsIntoChunksOf50AndKeepsKeys(): void
    {
        $commands = [];
        for ($i = 1; $i <= 51; $i++) {
            $commands["deal_{$i}"] = ['crm.deal.get', ['id' => $i]];
        }
        $first = [];
        for ($i = 1; $i <= 50; $i++) {
            $first["deal_{$i}"] = ['ID' => $i];
        }
        $t = (new FakeTransport())
            ->push(['result' => ['result' => $first, 'result_error' => []]])
            ->push(['result' => ['result' => [], 'result_error' => ['deal_51' => ['error' => 'NOT_FOUND']]]]);
        $client = new Client(self::URL, $t);

        $out = $client->batch($commands);

        self::assertCount(2, $t->requests);
        self::assertSame('crm.deal.get?id=1', $t->requests[0]['payload']['cmd']['deal_1']);
        self::assertCount(50, $out['result']);
        self::assertArrayHasKey('deal_51', $out['errors']);
    }
}
