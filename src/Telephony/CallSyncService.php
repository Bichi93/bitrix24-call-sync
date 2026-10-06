<?php

declare(strict_types=1);

namespace CallSync\Telephony;

use CallSync\Bitrix24\Client;
use CallSync\Storage\CallMap;

/**
 * Writes a finished PBX call into Bitrix24 CRM:
 *   1. telephony.externalcall.register - creates the call and binds it to the
 *      contact/company/lead with this phone number (or creates a lead).
 *   2. telephony.externalcall.finish   - sets duration, status and recording,
 *      which adds the call to the CRM timeline (or a missed-call entry).
 */
final class CallSyncService
{
    private const TYPE_OUTBOUND = 1;
    private const TYPE_INBOUND = 2;

    public function __construct(
        private readonly Client $bitrix,
        private readonly CallMap $callMap,
        private readonly PhoneNormalizer $phones,
        private readonly StatusMapper $statuses,
        private readonly int $fallbackUserId,
    ) {
    }

    /** @return array{status: string, bitrixCallId: string} */
    public function sync(CallEvent $event): array
    {
        $existing = $this->callMap->get($event->pbxCallId);
        if ($existing !== null) {
            return ['status' => 'duplicate', 'bitrixCallId' => $existing];
        }

        $user = $this->userParams($event);

        $registered = $this->bitrix->result('telephony.externalcall.register', $user + [
            'PHONE_NUMBER' => $this->phones->normalize($event->externalNumber),
            'TYPE' => $event->direction === CallEvent::INBOUND ? self::TYPE_INBOUND : self::TYPE_OUTBOUND,
            'CALL_START_DATE' => $event->startedAt->format(\DateTimeInterface::ATOM),
            // Unknown inbound caller -> Bitrix24 creates a lead. Outbound calls go to known contacts.
            'CRM_CREATE' => $event->direction === CallEvent::INBOUND ? 1 : 0,
            // The call is already over, so don't pop up a call card for the agent.
            'SHOW' => 0,
        ]);

        $bitrixCallId = (string) ($registered['CALL_ID'] ?? '');
        if ($bitrixCallId === '') {
            throw new \UnexpectedValueException('Bitrix24 did not return CALL_ID');
        }

        $finish = $user + [
            'CALL_ID' => $bitrixCallId,
            'DURATION' => $event->durationSeconds,
            'STATUS_CODE' => $this->statuses->toBitrixStatus($event),
        ];
        if ($event->recordingUrl !== null && $event->wasAnswered()) {
            $finish['RECORD_URL'] = $event->recordingUrl;
        }

        $this->bitrix->result('telephony.externalcall.finish', $finish);
        $this->callMap->save($event->pbxCallId, $bitrixCallId);

        return ['status' => 'synced', 'bitrixCallId' => $bitrixCallId];
    }

    /**
     * Bitrix24 can find the employee by the internal number set in their profile.
     * Calls without an extension (IVR hang-ups, queue timeouts) go to a fallback user.
     *
     * @return array<string, int|string>
     */
    private function userParams(CallEvent $event): array
    {
        if ($event->extension !== null && ctype_digit($event->extension)) {
            return ['USER_PHONE_INNER' => $event->extension];
        }

        return ['USER_ID' => $this->fallbackUserId];
    }
}
