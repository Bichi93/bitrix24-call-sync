<?php

declare(strict_types=1);

namespace CallSync\Storage;

/**
 * Remembers which PBX call was already written to Bitrix24.
 * PBX webhooks are often delivered more than once; without this,
 * every retry would create a second call record in the CRM.
 */
interface CallMap
{
    public function get(string $pbxCallId): ?string;

    public function save(string $pbxCallId, string $bitrixCallId): void;
}
