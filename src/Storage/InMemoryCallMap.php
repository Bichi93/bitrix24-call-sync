<?php

declare(strict_types=1);

namespace CallSync\Storage;

final class InMemoryCallMap implements CallMap
{
    /** @var array<string, string> */
    private array $map = [];

    public function get(string $pbxCallId): ?string
    {
        return $this->map[$pbxCallId] ?? null;
    }

    public function save(string $pbxCallId, string $bitrixCallId): void
    {
        $this->map[$pbxCallId] = $bitrixCallId;
    }
}
