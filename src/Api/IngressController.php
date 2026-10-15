<?php

namespace ConstructiveMedia\IntranetPhonelist\Api;

use ConstructiveMedia\IntranetPhonelist\Ingest\Ingestor;
use ConstructiveMedia\IntranetPhonelist\Ingest\Jobs;
use ConstructiveMedia\IntranetPhonelist\Ingest\Log;
use ConstructiveMedia\IntranetPhonelist\Ingest\Outcome;
use RuntimeException;
use WP_HTTP_Response;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Data Hub's way in (all signed, see Signature):
 *
 *     PUT    /phonelist/v2/{type}/{id}   create or replace one record
 *     PATCH  /phonelist/v2/{type}/{id}   change the fields sent
 *     DELETE /phonelist/v2/{type}/{id}   remove (updated_at in the body or query)
 *     PUT    /phonelist/v2/{type}        snapshot: every record of that type ({"records": [...]})
 *     POST   /phonelist/v2/batch         up to 500 operations ({"operations": [...]})
 *     GET    /phonelist/v2/jobs/{id}     a batch or snapshot's progress and results
 *
 * {type} is companies, divisions or staff; {id} is the Data Hub ID. Every request under /phonelist/v2 is logged,
 * and every error is returned as Problem Details.
 */
final class IngressController
{
    public const NAMESPACE = 'phonelist/v2';
    public const TYPE_PATTERN = '(?P<type>companies|divisions|staff)';
    public const ID_PATTERN = '(?P<id>[^/]{1,64})';

    private const MAX_BODY_BYTES = 5 * MB_IN_BYTES;
    private const MAX_BATCH = 500;
    private const MAX_SNAPSHOT = 5000;

    /** Groups this request's log entries: a job's ID for batches and snapshots, else one per request. */
    private ?string $runId = null;

    public function __construct(private readonly Ingestor $ingestor, private readonly Jobs $jobs, private readonly Log $log)
    {
    }

    public function register(): void
    {
        add_action('rest_api_init', function (): void {
            $signed = [Signature::class, 'check'];
            register_rest_route(self::NAMESPACE, '/' . self::TYPE_PATTERN . '/' . self::ID_PATTERN, [
                ['methods' => 'PUT, PATCH, DELETE', 'callback' => [$this, 'write'], 'permission_callback' => $signed],
            ]);
            register_rest_route(self::NAMESPACE, '/' . self::TYPE_PATTERN, [
                ['methods' => 'PUT', 'callback' => [$this, 'snapshot'], 'permission_callback' => $signed],
            ]);
            register_rest_route(self::NAMESPACE, '/batch', [
                ['methods' => WP_REST_Server::CREATABLE, 'callback' => [$this, 'batch'], 'permission_callback' => $signed],
            ]);
            register_rest_route(self::NAMESPACE, '/jobs/(?P<id>[0-9a-f-]{36})', [
                ['methods' => WP_REST_Server::READABLE, 'callback' => [$this, 'job'], 'permission_callback' => $signed],
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

        return $this->respond($this->ingestor->apply(['op' => strtolower($request->get_method()), 'type' => $type, 'id' => $id, 'data' => $body], $this->runId(), $this->trigger($request)));
    }

    public function snapshot(WP_REST_Request $request): WP_REST_Response
    {
        $records = $this->items($request, 'records', self::MAX_SNAPSHOT);

        return $records instanceof WP_REST_Response ? $records : $this->queue($request, 'snapshot', $request->get_url_params()['type'], $records);
    }

    public function batch(WP_REST_Request $request): WP_REST_Response
    {
        $operations = $this->items($request, 'operations', self::MAX_BATCH);

        return $operations instanceof WP_REST_Response ? $operations : $this->queue($request, 'batch', null, $operations);
    }

    public function job(WP_REST_Request $request): WP_REST_Response
    {
        $job = $this->jobs->get($request->get_url_params()['id']);

        return $job ? new WP_REST_Response($job) : Problem::response(404, 'Not found', 'No job with this ID.');
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
        if ($response->get_status() >= 400 && is_array($data) && isset($data['code'], $data['message']) && !isset($data['type'])) {
            $status = $response->get_status() === 403 && !is_user_logged_in() ? 401 : $response->get_status();
            $response = Problem::response($status, match ($status) {
                401 => 'Unauthorized',
                403 => 'Forbidden',
                404 => 'Not found',
                default => 'Request failed',
            }, (string) $data['message']);
        }

        $status = $response->get_status();
        $this->log->write($this->runId(), $this->trigger($request), $status >= 500 ? 'error' : ($status >= 400 ? 'warning' : 'info'), 'request', null, null, $request->get_method() . ' ' . $request->get_route(), null, array_filter([
            'status' => $status,
            'duration_ms' => (int) round((microtime(true) - ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true))) * 1000),
            'idempotency_key' => $request->get_header('Idempotency-Key'),
            'signature' => $request->get_header(Signature::HEADER) !== null || !is_user_logged_in() ? Signature::result($request) : null,
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

    private function queue(WP_REST_Request $request, string $kind, ?string $type, array $items): WP_REST_Response
    {
        $key = $request->get_header('Idempotency-Key');
        try {
            $job = $this->jobs->create($kind, $type, $items, $key === null || $key === '' ? null : substr($key, 0, 100), $this->trigger($request));
        } catch (RuntimeException $e) {
            return Problem::response(503, 'Could not be queued', $e->getMessage() . ' Try again.');
        }
        $this->runId = $job['id'];
        $url = rest_url(self::NAMESPACE . '/jobs/' . $job['id']);

        $response = new WP_REST_Response(['job' => $job['id'], 'status' => $job['status'], 'links' => ['self' => $url]], $job['existing'] ? 200 : 202);
        if (!$job['existing']) {
            $response->header('Location', $url); // Not on a 200: PHP turns a 200 with a Location header into a 302.
            $this->jobs->runAfterResponse();
        }

        return $response;
    }

    private function respond(Outcome $outcome): WP_REST_Response
    {
        if (!$outcome->failed()) {
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

    /**
     * 'api' for Data Hub (signed, or unsigned on a local site), 'manual' for a logged-in user.
     */
    private function trigger(WP_REST_Request $request): string
    {
        return $request->get_header(Signature::HEADER) === null && is_user_logged_in() ? 'manual' : 'api';
    }
}
