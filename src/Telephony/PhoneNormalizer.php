<?php

declare(strict_types=1);

namespace CallSync\Telephony;

/**
 * Brings numbers to E.164 (+995599123456) so Bitrix24 matches the caller
 * to an existing contact instead of creating a duplicate lead.
 */
final class PhoneNormalizer
{
    public function __construct(private readonly string $defaultCountryCode = '995')
    {
    }

    public function normalize(string $raw): string
    {
        $digits = preg_replace('/\D+/', '', $raw) ?? '';

        if ($digits === '') {
            throw new \InvalidArgumentException("Not a phone number: {$raw}");
        }

        if (str_starts_with($digits, '00')) {
            // 00 international prefix: 00995599... -> 995599...
            $digits = substr($digits, 2);
        } elseif (str_starts_with(ltrim($raw), '+')) {
            // already international
        } elseif (str_starts_with($digits, $this->defaultCountryCode)) {
            // country code without "+"
        } else {
            // local format: drop a trunk "0" if present, then add the country code
            $digits = $this->defaultCountryCode . ltrim($digits, '0');
        }

        return '+' . $digits;
    }
}
