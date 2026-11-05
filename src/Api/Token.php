<?php

namespace ConstructiveMedia\IntranetPhonelist\Api;

use WP_Error;
use WP_REST_Request;

/**
 * Every /phonelist/v2 request needs Authorization: Bearer <INTRANET_PHONELIST_API_SECRET>. WordPress logins and
 * Application Passwords don't count. Local and development sites also accept requests with no Authorization header;
 * staging and production always need the token.
 */
final class Token
{
    /**
     * Null when valid, else why not: 'missing', 'malformed' or 'invalid'.
     */
    public static function verify(?string $header, string $secret): ?string
    {
        $header = trim((string) $header);
        if ($header === '') {
            return 'missing';
        }
        if (!preg_match('/^Bearer\s+(\S+)$/i', $header, $match)) {
            return 'malformed';
        }

        return hash_equals($secret, $match[1]) ? null : 'invalid';
    }

    /**
     * How a request stands: 'valid', 'bypassed' (no Authorization header, on a local or development site),
     * 'not_configured', or verify()'s reason.
     */
    public static function result(WP_REST_Request $request): string
    {
        $secret = defined('INTRANET_PHONELIST_API_SECRET') ? (string) INTRANET_PHONELIST_API_SECRET : '';
        $header = $request->get_header('Authorization');

        if ($header === null && in_array(wp_get_environment_type(), ['local', 'development'], true)) {
            return 'bypassed';
        }
        if ($secret === '') {
            return 'not_configured';
        }

        return self::verify($header, $secret) ?? 'valid';
    }

    /**
     * REST permission callback for every route.
     */
    public static function check(WP_REST_Request $request): true|WP_Error
    {
        return match ($result = self::result($request)) {
            'valid', 'bypassed' => true,
            'not_configured' => new WP_Error('iphl_not_configured', 'The API is not configured on this site.', ['status' => 503]),
            default => new WP_Error('iphl_token', "Bearer token $result.", ['status' => 401]),
        };
    }
}
