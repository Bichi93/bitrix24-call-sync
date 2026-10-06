<?php

declare(strict_types=1);

namespace CallSync\Telephony;

/**
 * One finished call as reported by the PBX (Asterisk CDR, FreePBX,
 * a cloud SIP provider's webhook, ...). The webhook adapter maps the
 * provider's JSON into this object, so the rest of the code is PBX-agnostic.
 */
final class CallEvent
{
    public const INBOUND = 'inbound';
    public const OUTBOUND = 'outbound';

    public function __construct(
        public readonly string $pbxCallId,
        public readonly string $direction,
        public readonly string $externalNumber,
        public readonly ?string $extension,
        public readonly \DateTimeImmutable $startedAt,
        public readonly int $durationSeconds,
        public readonly string $disposition,
        public readonly ?string $recordingUrl = null,
    ) {
        if (!in_array($direction, [self::INBOUND, self::OUTBOUND], true)) {
            throw new \InvalidArgumentException("Unknown direction: {$direction}");
        }
        if ($durationSeconds < 0) {
            throw new \InvalidArgumentException('Duration cannot be negative');
        }
    }

    /**
     * Expected JSON:
     * {"call_id":"1696588812.42","direction":"inbound","from":"+995 599 12 34 56",
     *  "to":"101","started_at":"2026-10-06T12:40:12+04:00","duration":74,
     *  "disposition":"ANSWERED","recording_url":"https://pbx.example.com/rec/1696588812.42.mp3"}
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        foreach (['call_id', 'direction', 'from', 'to', 'started_at', 'disposition'] as $field) {
            if (!isset($data[$field]) || $data[$field] === '') {
                throw new \InvalidArgumentException("Missing field: {$field}");
            }
        }

        $direction = (string) $data['direction'];
        // For inbound calls the client is "from" and our extension is "to"; outbound is the reverse.
        [$external, $extension] = $direction === self::INBOUND
            ? [(string) $data['from'], $data['to'] ?? null]
            : [(string) $data['to'], $data['from'] ?? null];

        return new self(
            pbxCallId: (string) $data['call_id'],
            direction: $direction,
            externalNumber: $external,
            extension: $extension !== null ? (string) $extension : null,
            startedAt: new \DateTimeImmutable((string) $data['started_at']),
            durationSeconds: (int) ($data['duration'] ?? 0),
            disposition: strtoupper((string) $data['disposition']),
            recordingUrl: isset($data['recording_url']) ? (string) $data['recording_url'] : null,
        );
    }

    public function wasAnswered(): bool
    {
        return $this->disposition === 'ANSWERED' && $this->durationSeconds > 0;
    }
}
