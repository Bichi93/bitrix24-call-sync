<?php

declare(strict_types=1);

namespace CallSync\Storage;

/**
 * JSON file storage with an exclusive lock. Fine for one small PBX;
 * swap for MySQL/Redis behind the same interface when volume grows.
 */
final class FileCallMap implements CallMap
{
    public function __construct(private readonly string $path)
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create {$dir}");
        }
    }

    public function get(string $pbxCallId): ?string
    {
        return $this->read()[$pbxCallId] ?? null;
    }

    public function save(string $pbxCallId, string $bitrixCallId): void
    {
        $handle = fopen($this->path, 'c+');
        if ($handle === false) {
            throw new \RuntimeException("Cannot open {$this->path}");
        }

        try {
            flock($handle, LOCK_EX);
            $data = json_decode(stream_get_contents($handle) ?: '{}', true) ?: [];
            $data[$pbxCallId] = $bitrixCallId;
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            fflush($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** @return array<string, string> */
    private function read(): array
    {
        if (!is_file($this->path)) {
            return [];
        }

        return json_decode((string) file_get_contents($this->path), true) ?: [];
    }
}
