<?php

namespace ConstructiveMedia\IntranetPhonelist\Api;

use ConstructiveMedia\IntranetPhonelist\Phonelist;
use ConstructiveMedia\IntranetPhonelist\PostTypes;
use ConstructiveMedia\IntranetPhonelist\Schema;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Reads, with the bearer token (see Token), for admin tools and Data Hub only. WordPress logins don't count:
 *
 *     GET /phonelist/v2/{type}/{id}   one record, by Data Hub ID, in the shape Data Hub sends
 *     GET /phonelist/v2/{type}        every record held, in the same shape (id is null for records WordPress made,
 *                                     e.g. a company created for a new division); staff can be filtered by name,
 *                                     division_id and department_code
 *     GET /phonelist/v2/club          the JWH Club page (Phonelist::club())
 *
 * Records include the private fields (date_of_birth, home_phone).
 */
final class ReadController
{
    public function register(): void
    {
        add_action('rest_api_init', function (): void {
            $read = [Token::class, 'check'];
            register_rest_route(IngressController::NAMESPACE, '/' . IngressController::TYPE_PATTERN . '/' . IngressController::ID_PATTERN, [
                ['methods' => WP_REST_Server::READABLE, 'callback' => [$this, 'one'], 'permission_callback' => $read],
            ]);
            register_rest_route(IngressController::NAMESPACE, '/' . IngressController::TYPE_PATTERN, [
                [
                    'methods' => WP_REST_Server::READABLE,
                    'callback' => [$this, 'all'],
                    'permission_callback' => $read,
                    'args' => [
                        'name' => ['type' => 'string', 'description' => 'Staff only: any word of the name starts with this (case-insensitive).', 'sanitize_callback' => 'sanitize_text_field'],
                        'division_id' => ['type' => 'string', 'description' => 'Staff only: exact division ID.', 'sanitize_callback' => 'sanitize_text_field'],
                        'department_code' => ['type' => 'string', 'description' => 'Staff only: exact department code.', 'sanitize_callback' => 'sanitize_text_field'],
                    ],
                ],
            ]);
            register_rest_route(IngressController::NAMESPACE, '/staff/' . IngressController::ID_PATTERN . '/team', [
                ['methods' => WP_REST_Server::READABLE, 'callback' => [$this, 'team'], 'permission_callback' => $read],
            ]);
            register_rest_route(IngressController::NAMESPACE, '/club', [
                ['methods' => WP_REST_Server::READABLE, 'callback' => [$this, 'club'], 'permission_callback' => $read],
            ]);
        });
    }

    public function one(WP_REST_Request $request): WP_REST_Response
    {
        ['type' => $type, 'id' => $id] = $request->get_url_params();
        $postId = PostTypes::postId(Schema::TYPES[$type], $id);

        return $postId
            ? new WP_REST_Response(self::record($type, get_post($postId)))
            : Problem::response(404, 'Not found', 'No record with this ID.');
    }

    public function all(WP_REST_Request $request): WP_REST_Response
    {
        $type = $request->get_url_params()['type'];
        $posts = get_posts(['post_type' => Schema::TYPES[$type], 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC']);
        update_meta_cache('post', wp_list_pluck($posts, 'ID'));
        $records = array_map(fn (WP_Post $post): array => self::record($type, $post), $posts);

        if ($type === 'staff') {
            $records = array_values(array_filter($records, fn (array $record): bool => Phonelist::nameMatches(PostTypes::displayName($record['preferred_name'], $record['first_name'], $record['last_name']), $request['name'])
                && ((string) $request['division_id'] === '' || $record['division_id'] === $request['division_id'])
                && ((string) $request['department_code'] === '' || $record['department_code'] === $request['department_code'])));
        }

        return new WP_REST_Response($records);
    }

    /**
     * The people who report to a staff member, as records sorted by name.
     */
    public function team(WP_REST_Request $request): WP_REST_Response
    {
        $id = $request->get_url_params()['id'];
        $postId = PostTypes::postId(PostTypes::STAFF, $id);
        if (!$postId) {
            return Problem::response(404, 'Not found', 'No record with this ID.');
        }
        $posts = get_posts(['post_type' => PostTypes::STAFF, 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC', 'meta_key' => 'manager', 'meta_value' => (string) $postId]);

        return new WP_REST_Response(array_map(fn (WP_Post $post): array => self::record('staff', $post), $posts));
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

    /**
     * A stored record in the contract's shape: id, updated_at, then every field (dates as YYYY-MM-DD, real booleans,
     * null when empty).
     */
    private static function record(string $type, WP_Post $post): array
    {
        $record = [
            'id' => (string) get_post_meta($post->ID, PostTypes::SOURCE_ID, true) ?: null,
            'updated_at' => (string) get_post_meta($post->ID, PostTypes::UPDATED_AT, true) ?: null,
        ];
        $fields = Schema::fields($type);
        foreach (Schema::stored($type, $post->ID) as $name => $value) {
            $record[$name] = match ($fields[$name]['kind']) {
                default => $value,
                'date' => $value === null ? null : substr($value, 0, 4) . '-' . substr($value, 4, 2) . '-' . substr($value, 6, 2),
                'bool' => $value === null ? null : $value === '1',
            };
        }

        return $record;
    }
}
