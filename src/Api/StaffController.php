<?php

namespace ConstructiveMedia\IntranetPhonelist\Api;

use ConstructiveMedia\IntranetPhonelist\Phonelist;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * GET /wp-json/phonelist/v1/staff?name=&division=&tag=
 * GET /wp-json/phonelist/v1/club
 *
 * Any logged-in user: intranet pages via their login cookie (+ X-WP-Nonce), scripts via WordPress Application Passwords.
 */
final class StaffController
{
    public function register(): void
    {
        add_action('rest_api_init', function (): void {
            register_rest_route('phonelist/v1', '/staff', [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'index'],
                'permission_callback' => 'is_user_logged_in',
                'args' => [
                    'name' => ['type' => 'string', 'description' => 'Any word of the name starts with this (case-insensitive).', 'sanitize_callback' => 'sanitize_text_field'],
                    'division' => ['type' => 'string', 'description' => 'Exact DIV_CODE, e.g. JWH.', 'sanitize_callback' => 'sanitize_text_field'],
                    'tag' => ['type' => 'string', 'description' => 'Exact department code, e.g. 20.', 'sanitize_callback' => 'sanitize_text_field'],
                ],
            ]);
            register_rest_route('phonelist/v1', '/club', [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'club'],
                'permission_callback' => 'is_user_logged_in',
            ]);
        });
    }

    /**
     * Phonelist::club() as a list, so page order survives JSON clients.
     */
    public function club(): WP_REST_Response
    {
        $levels = [];
        foreach (Phonelist::club() as $level => $group) {
            $levels[] = ['level' => (string) $level] + $group;
        }

        return new WP_REST_Response($levels);
    }

    public function index(WP_REST_Request $request): WP_REST_Response
    {
        return new WP_REST_Response(Phonelist::staff(
            name: $request['name'],
            division: $request['division'],
            tag: $request['tag'],
        ));
    }
}
