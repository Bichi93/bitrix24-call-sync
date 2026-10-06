<?php

declare(strict_types=1);

namespace CallSync\Bitrix24;

final class Bitrix24Exception extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $description = '',
        public readonly ?string $method = null,
    ) {
        $prefix = $method !== null ? "{$method}: " : '';
        parent::__construct($prefix . $errorCode . ($description !== '' ? " ({$description})" : ''));
    }

    /** Errors worth retrying: rate limit and temporary server problems. */
    public function isRetryable(): bool
    {
        return in_array($this->errorCode, ['QUERY_LIMIT_EXCEEDED', 'HTTP_503', 'HTTP_502', 'NETWORK_ERROR'], true);
    }
}
