<?php

declare(strict_types=1);

namespace CallSync\Tests;

use CallSync\Bitrix24\Client;
use CallSync\Storage\InMemoryCallMap;
use CallSync\Telephony\CallEvent;
use CallSync\Telephony\CallSyncService;
use CallSync\Telephony\PhoneNormalizer;
use CallSync\Telephony\StatusMapper;
use PHPUnit\Framework\TestCase;

final class CallSyncServiceTest extends TestCase
{
    private FakeTransport $transport;
    private InMemoryCallMap $map;
    private CallSyncService $service;

    protected function setUp(): void
    {
        $this->transport = new FakeTransport();
        $this->map = new InMemoryCallMap();
        $this->service = new CallSyncService(
            new Client('https://demo.bitrix24.com/rest/1/token/', $this->transport, sleeper: fn () => null),
            $this->map,
            new PhoneNormalizer('995'),
            new StatusMapper(),
            fallbackUserId: 9,
        );
    }

    public function testAnsweredInboundCallIsRegisteredAndFinishedWithRecording(): void
    {
        $this->transport
            ->push(['result' => ['CALL_ID' => 'externalCall.abc', 'CRM_ENTITY_TYPE' => 'CONTACT', 'CRM_ENTITY_ID' => 15]])
            ->push(['result' => ['CALL_ID' => 'externalCall.abc']]);

        $result = $this->service->sync(CallEvent::fromArray([
            'call_id' => '1696588812.42',
            'direction' => 'inbound',
            'from' => '599 12 34 56',
            'to' => '101',
            'started_at' => '2026-10-06T12:40:12+04:00',
            'duration' => 74,
            'disposition' => 'ANSWERED',
            'recording_url' => 'https://pbx.example.com/rec/1696588812.42.mp3',
        ]));

        self::assertSame(['status' => 'synced', 'bitrixCallId' => 'externalCall.abc'], $result);
        self::assertSame(['telephony.externalcall.register', 'telephony.externalcall.finish'], $this->transport->methods());

        $register = $this->transport->requests[0]['payload'];
        self::assertSame('+995599123456', $register['PHONE_NUMBER']);
        self::assertSame(2, $register['TYPE']);
        self::assertSame('101', $register['USER_PHONE_INNER']);
        self::assertSame(1, $register['CRM_CREATE']);
        self::assertSame(0, $register['SHOW']);

        $finish = $this->transport->requests[1]['payload'];
        self::assertSame('200', $finish['STATUS_CODE']);
        self::assertSame(74, $finish['DURATION']);
        self::assertSame('https://pbx.example.com/rec/1696588812.42.mp3', $finish['RECORD_URL']);
    }

    public function testMissedCallWithoutExtensionGoesToFallbackUserWithoutRecording(): void
    {
        $this->transport
            ->push(['result' => ['CALL_ID' => 'externalCall.m1']])
            ->push(['result' => ['CALL_ID' => 'externalCall.m1']]);

        $this->service->sync(CallEvent::fromArray([
            'call_id' => 'q-77',
            'direction' => 'inbound',
            'from' => '+995 32 2 00 00 00',
            'to' => 'queue-sales',
            'started_at' => '2026-10-06T19:02:00+04:00',
            'duration' => 0,
            'disposition' => 'NO ANSWER',
            'recording_url' => 'https://pbx.example.com/rec/q-77.mp3',
        ]));

        self::assertSame(9, $this->transport->requests[0]['payload']['USER_ID']);
        $finish = $this->transport->requests[1]['payload'];
        self::assertSame('304', $finish['STATUS_CODE']);
        self::assertArrayNotHasKey('RECORD_URL', $finish);
    }

    public function testRepeatedWebhookDoesNotCreateSecondCall(): void
    {
        $this->map->save('dup-1', 'externalCall.old');

        $result = $this->service->sync(new CallEvent(
            'dup-1', CallEvent::OUTBOUND, '+995599000000', '102',
            new \DateTimeImmutable('2026-10-06 10:00:00'), 30, 'ANSWERED',
        ));

        self::assertSame('duplicate', $result['status']);
        self::assertSame([], $this->transport->requests);
    }

    public function testOutboundCallDoesNotCreateLead(): void
    {
        $this->transport
            ->push(['result' => ['CALL_ID' => 'externalCall.o1']])
            ->push(['result' => ['CALL_ID' => 'externalCall.o1']]);

        $this->service->sync(CallEvent::fromArray([
            'call_id' => 'o-1',
            'direction' => 'outbound',
            'from' => '103',
            'to' => '00995599111222',
            'started_at' => '2026-10-06T11:00:00+04:00',
            'duration' => 15,
            'disposition' => 'BUSY',
        ]));

        $register = $this->transport->requests[0]['payload'];
        self::assertSame(1, $register['TYPE']);
        self::assertSame(0, $register['CRM_CREATE']);
        self::assertSame('+995599111222', $register['PHONE_NUMBER']);
        self::assertSame('103', $register['USER_PHONE_INNER']);
        self::assertSame('486', $this->transport->requests[1]['payload']['STATUS_CODE']);
    }

    public function testCallIsNotMarkedSyncedWhenFinishFails(): void
    {
        $this->transport
            ->push(['result' => ['CALL_ID' => 'externalCall.f1']])
            ->push(['error' => 'ACCESS_DENIED']);

        try {
            $this->service->sync(new CallEvent(
                'f-1', CallEvent::INBOUND, '599123456', '101',
                new \DateTimeImmutable(), 20, 'ANSWERED',
            ));
            self::fail('Expected exception');
        } catch (\RuntimeException) {
            self::assertNull($this->map->get('f-1'));
        }
    }
}
