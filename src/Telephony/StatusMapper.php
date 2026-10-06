<?php

declare(strict_types=1);

namespace CallSync\Telephony;

/**
 * Maps PBX dispositions to the SIP-style STATUS_CODE that
 * telephony.externalcall.finish expects. 200 = success, 304 = missed.
 */
final class StatusMapper
{
    private const MAP = [
        'ANSWERED' => '200',
        'NO ANSWER' => '304',
        'NOANSWER' => '304',
        'MISSED' => '304',
        'BUSY' => '486',
        'CANCEL' => '603-S',
        'CANCELLED' => '603-S',
        'DECLINED' => '603',
        'CONGESTION' => '503',
        'CHANUNAVAIL' => '480',
        'FAILED' => 'OTHER',
    ];

    public function toBitrixStatus(CallEvent $event): string
    {
        // An "answered" call with zero seconds never really connected.
        if ($event->disposition === 'ANSWERED' && $event->durationSeconds === 0) {
            return '304';
        }

        return self::MAP[$event->disposition] ?? 'OTHER';
    }
}
