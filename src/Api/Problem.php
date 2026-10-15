<?php

namespace ConstructiveMedia\IntranetPhonelist\Api;

use WP_REST_Response;

/**
 * Error responses as RFC 9457 Problem Details (application/problem+json).
 */
final class Problem
{
    /**
     * @param array<array{field: string, message: string}> $errors Field errors, for validation failures.
     */
    public static function body(int $status, string $title, string $detail = '', array $errors = []): array
    {
        return ['type' => $errors ? 'about:blank#validation' : 'about:blank', 'title' => $title, 'status' => $status, 'detail' => $detail]
            + ($errors ? ['errors' => $errors] : []);
    }

    public static function response(int $status, string $title, string $detail = '', array $errors = []): WP_REST_Response
    {
        $response = new WP_REST_Response(self::body($status, $title, $detail, $errors), $status);
        $response->header('Content-Type', 'application/problem+json; charset=' . get_option('blog_charset'));

        return $response;
    }
}
