<?php

declare(strict_types=1);

/**
 * Endpoint the PBX calls when a call ends.
 * POST JSON body (see CallEvent::fromArray), header X-Webhook-Secret.
 */

use CallSync\Bitrix24\Bitrix24Exception;
use CallSync\Bitrix24\Client;
use CallSync\Storage\FileCallMap;
use CallSync\Telephony\CallEvent;
use CallSync\Telephony\CallSyncService;
use CallSync\Telephony\PhoneNormalizer;
use CallSync\Telephony\StatusMapper;

require __DIR__ . '/../vendor/autoload.php';

header('Content-Type: application/json');

function respond(int $code, array $body): never
{
    http_response_code($code);
    echo json_encode($body);
    exit;
}

$secret = getenv('PBX_WEBHOOK_SECRET') ?: '';
if ($secret === '' || !hash_equals($secret, $_SERVER['HTTP_X_WEBHOOK_SECRET'] ?? '')) {
    respond(401, ['error' => 'unauthorized']);
}

try {
    $event = CallEvent::fromArray(json_decode((string) file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR));
} catch (\JsonException | \InvalidArgumentException $e) {
    respond(422, ['error' => $e->getMessage()]);
}

$service = new CallSyncService(
    new Client((string) getenv('B24_WEBHOOK_URL')),
    new FileCallMap(__DIR__ . '/../var/call-map.json'),
    new PhoneNormalizer(getenv('DEFAULT_COUNTRY_CODE') ?: '995'),
    new StatusMapper(),
    fallbackUserId: (int) (getenv('B24_FALLBACK_USER_ID') ?: 1),
);

try {
    respond(200, $service->sync($event));
} catch (Bitrix24Exception $e) {
    error_log('[call-sync] ' . $e->getMessage());
    // 503 tells the PBX to retry later; the call map prevents duplicates on retry.
    respond($e->isRetryable() ? 503 : 502, ['error' => $e->errorCode]);
}
