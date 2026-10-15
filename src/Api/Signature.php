<?php

namespace ConstructiveMedia\IntranetPhonelist\Api;

use WP_Error;
use WP_REST_Request;

/**
 * Signed requests: X-Phonelist-Signature: t=<unix seconds>,v1=<hex HMAC-SHA256 of "<t>.<METHOD>.<path>.<raw body>"
 * with the shared secret>, where <path> is the path and query string exactly as requested
 * (e.g. '/wp-json/phonelist/v2/staff/10423?updated_at=…'). Covering the method and path means a captured request
 * can't be replayed against another record. The secret is INTRANET_PHONELIST_API_SECRET.
 * Local and development sites also accept unsigned requests.
 */
final class Signature
{
    public const HEADER = 'X-Phonelist-Signature';
    /** Seconds a signature stays valid either side of our clock, so a captured request can't be replayed later. */
    public const TOLERANCE = 300;

    public static function sign(string $method, string $path, string $rawBody, string $secret, int $time): string
    {
        return "t=$time,v1=" . self::hmac($time, $method, $path, $rawBody, $secret);
    }

    /**
     * Null when valid, else why not: 'missing', 'malformed', 'expired' or 'mismatch'.
     */
    public static function verify(?string $header, string $method, string $path, string $rawBody, string $secret, int $now): ?string
    {
        if ($header === null || trim($header) === '') {
            return 'missing';
        }
        if (!preg_match('/^t=(\d+),v1=([0-9a-f]{64})$/', trim($header), $match)) {
            return 'malformed';
        }
        if (abs($now - (int) $match[1]) > self::TOLERANCE) {
            return 'expired';
        }

        return hash_equals(self::hmac((int) $match[1], $method, $path, $rawBody, $secret), $match[2]) ? null : 'mismatch';
    }

    private static function hmac(int $time, string $method, string $path, string $rawBody, string $secret): string
    {
        return hash_hmac('sha256', $time . '.' . strtoupper($method) . ".$path.$rawBody", $secret);
    }

    /**
     * How a request stands: 'valid', 'bypassed' (unsigned, on a local or development site), 'not_configured',
     * or verify()'s reason. Always checked against the raw body, byte for byte.
     */
    public static function result(WP_REST_Request $request): string
    {
        $secret = defined('INTRANET_PHONELIST_API_SECRET') ? (string) INTRANET_PHONELIST_API_SECRET : '';
        $header = $request->get_header(self::HEADER);

        if ($header === null && in_array(wp_get_environment_type(), ['local', 'development'], true)) {
            return 'bypassed';
        }
        if ($secret === '') {
            return 'not_configured';
        }

        $path = (string) wp_unslash($_SERVER['REQUEST_URI'] ?? ''); // As requested, including the query string.

        return self::verify($header, $request->get_method(), $path, $request->get_body(), $secret, time()) ?? 'valid';
    }

    /**
     * REST permission callback for signed endpoints.
     */
    public static function check(WP_REST_Request $request): true|WP_Error
    {
        return match ($result = self::result($request)) {
            'valid', 'bypassed' => true,
            'not_configured' => new WP_Error('iphl_not_configured', 'Signed requests are not configured on this site.', ['status' => 503]),
            default => new WP_Error('iphl_signature', "Signature $result.", ['status' => 401]),
        };
    }
}
