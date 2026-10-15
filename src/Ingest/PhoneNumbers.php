<?php

namespace ConstructiveMedia\IntranetPhonelist\Ingest;

use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;

/**
 * Phone numbers in one format, international E.164 ('+61437793226', '+61894647800'),
 * using giggsey/libphonenumber-for-php-lite.
 */
final class PhoneNumbers
{
    private const REGION = 'AU';
    /** Every JWH site is in WA, so 8-digit numbers with no area code get 08. Change here if that stops being true. */
    private const DEFAULT_AREA_CODE = '08';

    /**
     * '0437 793 226' → '+61437793226', '9464 7800' → '+61894647800'. Null if it isn't a valid number.
     * Without the library (a git or zip install without its vendor/), numbers are returned as they are.
     */
    public static function format(string $value): ?string
    {
        if (!class_exists(PhoneNumberUtil::class)) {
            return $value;
        }
        $digits = preg_replace('/\D/', '', $value);
        if (preg_match('/^[\d\s()-]+$/', $value) && strlen($digits) === 8) {
            $value = self::DEFAULT_AREA_CODE . $digits;
        }

        $util = PhoneNumberUtil::getInstance();
        try {
            $number = $util->parse($value, self::REGION);
        } catch (NumberParseException) {
            return null;
        }

        return $util->isValidNumber($number) ? $util->format($number, PhoneNumberFormat::E164) : null;
    }
}
