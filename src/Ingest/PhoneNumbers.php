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
     * The library comes with the site's Composer install, or the plugin's own vendor/ for git/zip installs.
     */
    public static function available(): bool
    {
        return class_exists(PhoneNumberUtil::class);
    }

    /**
     * '0437 793 226' → '+61437793226', '9464 7800' → '+61894647800', '' → ''. Null if it isn't a valid number.
     */
    public static function format(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (preg_match('/^[\d\s()-]+$/', $value) && strlen(preg_replace('/\D/', '', $value)) === 8) {
            $value = self::DEFAULT_AREA_CODE . preg_replace('/\D/', '', $value);
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
