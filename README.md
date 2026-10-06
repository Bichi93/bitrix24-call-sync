# bitrix24-call-sync

Writes calls from any SIP PBX (Asterisk/FreePBX, or a cloud SIP provider's webhook) into Bitrix24 CRM through the REST API, so every inbound and outbound call appears on the right contact's timeline, with its recording. Missed calls show up as missed, and unknown callers become leads.

Small on purpose: plain PHP 8.1+, no framework, one dependency for tests.

## How it works

```
PBX (call ended) ──POST JSON──▶ public/pbx-webhook.php
                                   │  checks X-Webhook-Secret
                                   ▼
                              CallSyncService
                                   │ 1. skip if this PBX call ID was already synced
                                   │ 2. telephony.externalcall.register
                                   │      (PHONE_NUMBER in E.164, TYPE, USER_PHONE_INNER,
                                   │       CRM_CREATE=1 for unknown inbound callers)
                                   │ 3. telephony.externalcall.finish
                                   │      (DURATION, STATUS_CODE 200/304/486…, RECORD_URL)
                                   ▼
                              Bitrix24 CRM timeline
```

Design choices:

- **Idempotent.** PBX webhooks get retried. The PBX call ID → Bitrix24 CALL_ID map (`CallMap`) stops a retry from creating a second call record. The map is written only after `finish` succeeds, so a half-synced call is retried.
- **Rate limits handled.** `Client` retries `QUERY_LIMIT_EXCEEDED` and HTTP 502/503 with exponential backoff (0.5 s, 1 s, 2 s). Business errors such as `ACCESS_DENIED` fail at once.
- **No duplicate leads.** Numbers are normalized to E.164 (`599 12 34 56`, `0599…`, `00995…` → `+995599123456`), so Bitrix24 matches existing contacts.
- **Employee mapping.** Uses the employee's internal number (`USER_PHONE_INNER`, set in their Bitrix24 profile). Calls with no extension (IVR hang-ups, queue timeouts) go to a fallback user.
- **PBX-agnostic.** Provider-specific JSON is mapped once in `CallEvent::fromArray()`.

`Client` is a general webhook client and also gives `listAll()` (follows the `next` cursor of list methods) and `batch()` (up to 50 commands per request, keyed results).

## Setup

1. In Bitrix24: *Developer resources → Other → Inbound webhook*, scopes `telephony`, `crm`, `user`.
2. `cp .env.example .env`, fill in the webhook URL, a shared secret and the fallback user ID, then load these variables into the web server environment.
3. `composer install`
4. Point your PBX's "call ended" webhook at `https://your-host/pbx-webhook.php` and send the `X-Webhook-Secret` header.

Example payload:

```json
{"call_id":"1696588812.42","direction":"inbound","from":"+995 599 12 34 56","to":"101",
 "started_at":"2026-10-06T12:40:12+04:00","duration":74,"disposition":"ANSWERED",
 "recording_url":"https://pbx.example.com/rec/1696588812.42.mp3"}
```

## Tests

```
composer test
```

The tests run against a fake transport, with no network calls. They cover retry/backoff, pagination, batch chunking, inbound/outbound/missed calls, the fallback user, duplicate webhooks, partial failure and phone normalization.

## Structure

```
src/Bitrix24/   Client, Transport (curl), Bitrix24Exception
src/Telephony/  CallEvent, CallSyncService, PhoneNormalizer, StatusMapper
src/Storage/    CallMap (file + in-memory)
public/         pbx-webhook.php
tests/          PHPUnit tests
```

## Not included (yet)

- Live call cards (register at ring time with `SHOW=1`, finish at hang-up). This needs the PBX's ring event, not only call-end.
- Uploading recordings as files (`telephony.externalCall.attachRecord`) for PBXs whose recordings aren't reachable by URL.
- A queue, so a slow Bitrix24 response doesn't hold the PBX webhook open.

Written with Claude Code. MIT licence.
