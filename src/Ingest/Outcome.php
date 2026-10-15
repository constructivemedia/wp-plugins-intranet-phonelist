<?php

namespace ConstructiveMedia\IntranetPhonelist\Ingest;

/**
 * What happened to one pushed record, and the HTTP status that says so.
 */
final class Outcome
{
    /**
     * @param string $result created, updated, unchanged, deleted, stale, invalid, not_found or error.
     * @param string $label The record's name, for the log.
     * @param array<string, mixed> $changes Field => ['from' => …, 'to' => …], or '(changed)' for private fields. Logged, never returned.
     * @param array<array{field: string, message: string}> $errors
     * @param array<array{field: string, message: string}> $warnings Saved, but worth fixing at the source (e.g. an unreadable phone number).
     */
    public function __construct(
        public readonly string $result,
        public readonly int $status,
        public readonly string $label = '',
        public readonly array $changes = [],
        public readonly array $errors = [],
        public readonly array $warnings = [],
        public readonly ?string $message = null,
    ) {
    }

    public function failed(): bool
    {
        return $this->status >= 400;
    }

    /**
     * The API's view of it: the result, plus errors, warnings or a message when there are any.
     */
    public function toArray(): array
    {
        return ['result' => $this->result]
            + ($this->errors ? ['errors' => $this->errors] : [])
            + ($this->warnings ? ['warnings' => $this->warnings] : [])
            + ($this->message !== null ? ['message' => $this->message] : []);
    }
}
