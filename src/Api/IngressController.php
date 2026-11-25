<?php

namespace ConstructiveMedia\IntranetPhonelist\Api;

use ConstructiveMedia\IntranetPhonelist\Ingest\Ingestor;
use ConstructiveMedia\IntranetPhonelist\Ingest\Log;
use ConstructiveMedia\IntranetPhonelist\Ingest\Outcome;
use WP_HTTP_Response;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Data Hub's way in (bearer token, see Token):
 *
 *     PUT    /phonelist/v2/{type}/{id}   create or replace one record
 *     PATCH  /phonelist/v2/{type}/{id}   change the fields sent
 *     DELETE /phonelist/v2/{type}/{id}   remove (updated_at in the body or query)
 *     PUT    /phonelist/v2/{type}        snapshot: every record of that type ({"records": [...]}), the main way in
 *     POST   /phonelist/v2/batch         up to 500 operations ({"operations": [...]})
 *
 * {type} is companies, divisions or staff; {id} is the Data Hub ID. Snapshots and batches are processed in the
 * request and answered with counts (see Ingestor::report()). Every request under /phonelist/v2 is logged, and every
 * error is returned as Problem Details.
 */
final class IngressController
{
    public const NAMESPACE = 'phonelist/v2';
    public const TYPE_PATTERN = '(?P<type>companies|divisions|staff)';
    public const ID_PATTERN = '(?P<id>[^/]{1,64})';

    private const MAX_BODY_BYTES = 5 * MB_IN_BYTES;
    private const MAX_BATCH = 500;
    private const MAX_SNAPSHOT = 5000;

    /** Groups this request's log entries. */
    private ?string $runId = null;

    public function __construct(private readonly Ingestor $ingestor, private readonly Log $log)
    {
    }

    public function register(): void
    {
        add_action('rest_api_init', function (): void {
            $token = [Token::class, 'check'];
            register_rest_route(self::NAMESPACE, '/' . self::TYPE_PATTERN . '/' . self::ID_PATTERN, [
                ['methods' => 'PUT, PATCH, DELETE', 'callback' => [$this, 'write'], 'permission_callback' => $token],
            ]);
            register_rest_route(self::NAMESPACE, '/' . self::TYPE_PATTERN, [
                ['methods' => 'PUT', 'callback' => [$this, 'snapshot'], 'permission_callback' => $token],
            ]);
            register_rest_route(self::NAMESPACE, '/batch', [
                ['methods' => WP_REST_Server::CREATABLE, 'callback' => [$this, 'batch'], 'permission_callback' => $token],
            ]);
        });

        add_filter('rest_pre_dispatch', [$this, 'checkSize'], 10, 3);
        add_filter('rest_post_dispatch', [$this, 'finish'], 10, 3);
    }

    /**
     * PUT, PATCH or DELETE one record. The type and id come from the URL only (never from the body or query string).
     * A DELETE takes updated_at from its body or the query string.
     */
    public function write(WP_REST_Request $request): WP_REST_Response
    {
        $delete = $request->get_method() === 'DELETE';
        $body = $delete && $request->get_body() === '' ? [] : $this->body($request);
        if ($body instanceof WP_REST_Response) {
            return $body;
        }
        if ($delete) {
            $body = ['updated_at' => $body['updated_at'] ?? $request->get_query_params()['updated_at'] ?? null];
        }
        ['type' => $type, 'id' => $id] = $request->get_url_params();

        return $this->respond($this->ingestor->apply(['op' => strtolower($request->get_method()), 'type' => $type, 'id' => $id, 'data' => $body], $this->runId(), 'api'));
    }

    public function snapshot(WP_REST_Request $request): WP_REST_Response
    {
        $records = $this->items($request, 'records', self::MAX_SNAPSHOT);

        return $records instanceof WP_REST_Response ? $records
            : new WP_REST_Response(Ingestor::report($this->ingestor->snapshot($request->get_url_params()['type'], $records, $this->runId(), 'api')));
    }

    public function batch(WP_REST_Request $request): WP_REST_Response
    {
        $operations = $this->items($request, 'operations', self::MAX_BATCH);

        return $operations instanceof WP_REST_Response ? $operations
            : new WP_REST_Response(Ingestor::report($this->ingestor->batch($operations, $this->runId(), 'api')));
    }

    /**
     * Refuse oversized bodies before anything parses them.
     */
    public function checkSize(mixed $result, WP_REST_Server $server, WP_REST_Request $request): mixed
    {
        if ($result === null && self::ours($request) && strlen($request->get_body()) > self::MAX_BODY_BYTES) {
            return Problem::response(413, 'Request too large', 'The body may be at most ' . size_format(self::MAX_BODY_BYTES) . '.');
        }

        return $result;
    }

    /**
     * For every request under /phonelist/v2: turn WordPress's own errors (no route, failed permission) into
     * Problem Details, and log the request.
     */
    public function finish(WP_HTTP_Response $response, WP_REST_Server $server, WP_REST_Request $request): WP_HTTP_Response
    {
        if (!self::ours($request)) {
            return $response;
        }

        $data = $response->get_data();
        $status = $response->get_status();
        if ($status >= 400 && is_array($data) && isset($data['code'], $data['message']) && !isset($data['type'])) {
            $response = Problem::response($status, match ($status) {
                401 => 'Unauthorized',
                403 => 'Forbidden',
                404 => 'Not found',
                default => 'Request failed',
            }, (string) $data['message']);
        }

        $counts = is_array($data) && isset($data['problems']) ? array_diff_key($data, ['ids' => 0, 'problems' => 0]) : null; // a snapshot or batch
        $this->log->write($this->runId(), 'api', $status >= 500 ? 'error' : ($status >= 400 ? 'warning' : 'info'), 'request', null, null, $request->get_method() . ' ' . $request->get_route(), null, array_filter([
            'status' => $status,
            'duration_ms' => (int) round((microtime(true) - ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true))) * 1000),
            'results' => $counts,
            'auth' => Token::result($request),
            'query' => $request->get_query_params() ?: null,
        ], fn ($value): bool => $value !== null));

        return $response;
    }

    private static function ours(WP_REST_Request $request): bool
    {
        return str_starts_with($request->get_route(), '/' . self::NAMESPACE);
    }

    /**
     * The request body as a JSON object, or a 400 problem.
     */
    private function body(WP_REST_Request $request): array|WP_REST_Response
    {
        $body = json_decode($request->get_body(), true);
        if (!is_array($body) || ($body !== [] && array_is_list($body))) {
            return Problem::response(400, 'Bad request', 'The body must be a JSON object.');
        }

        return $body;
    }

    /**
     * The list under $key in the body: 1 to $max entries.
     */
    private function items(WP_REST_Request $request, string $key, int $max): array|WP_REST_Response
    {
        $body = $this->body($request);
        if ($body instanceof WP_REST_Response) {
            return $body;
        }
        $items = $body[$key] ?? null;
        if (!is_array($items) || !array_is_list($items)) {
            return Problem::response(422, 'Invalid request', "The body needs a \"$key\" array.", [['field' => $key, 'message' => 'Required: an array.']]);
        }
        if ($items === []) {
            return Problem::response(422, 'Invalid request', "The \"$key\" array must not be empty.", [['field' => $key, 'message' => 'Must not be empty.']]);
        }
        if (count($items) > $max) {
            return Problem::response(413, 'Request too large', "At most $max $key per request.");
        }

        return $items;
    }

    private function respond(Outcome $outcome): WP_REST_Response
    {
        if ($outcome->status < 400) {
            return new WP_REST_Response($outcome->status === 204 ? null : $outcome->toArray(), $outcome->status);
        }

        return Problem::response($outcome->status, match ($outcome->result) {
            'invalid' => 'Invalid record',
            'stale' => 'Older than the stored record',
            'not_found' => 'Not found',
            default => 'Could not be saved',
        }, (string) $outcome->message, $outcome->errors);
    }

    private function runId(): string
    {
        return $this->runId ??= wp_generate_uuid4();
    }
}
