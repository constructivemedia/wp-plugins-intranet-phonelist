<?php

namespace ConstructiveMedia\IntranetPhonelist\Sync;

use RuntimeException;

/**
 * Read-only Oracle access: only ever SELECTs from the tables in PostTypes::synced().
 */
final class OracleSource
{
    public function __construct(
        private readonly string $dsn,
        private readonly string $user,
        private readonly string $password,
    ) {
    }

    public static function fromConstants(): self
    {
        $get = fn (string $name): string => defined($name) ? (string) constant($name) : '';

        return new self(
            $get('INTRANET_PHONELIST_ORACLE_DSN'),
            $get('INTRANET_PHONELIST_ORACLE_USER'),
            $get('INTRANET_PHONELIST_ORACLE_PASSWORD'),
        );
    }

    /**
     * SELECT the given columns, optionally WHERE $key = $id. Throws on any failure.
     *
     * @return array<array<string, string>>
     */
    public function fetch(string $table, array $columns, ?string $key = null, ?string $id = null): array
    {
        if (!function_exists('oci_connect')) {
            throw new RuntimeException('The oci8 PHP extension is not loaded.');
        }
        foreach (['INTRANET_PHONELIST_ORACLE_DSN' => $this->dsn, 'INTRANET_PHONELIST_ORACLE_USER' => $this->user, 'INTRANET_PHONELIST_ORACLE_PASSWORD' => $this->password] as $name => $value) {
            if ($value === '') {
                throw new RuntimeException("$name is not set in wp-config.php.");
            }
        }

        // Same credentials within a request reuse one connection.
        $connection = @oci_connect($this->user, $this->password, $this->dsn, 'AL32UTF8');
        if (!$connection) {
            throw new RuntimeException('Oracle connection failed: ' . (oci_error()['message'] ?? 'unknown error'));
        }

        // Session-only (writes nothing): real DATE columns (e.g. ON_LEAVE) come back as ACF's Ymd, full year, no parsing.
        oci_execute(oci_parse($connection, "ALTER SESSION SET NLS_DATE_FORMAT = 'YYYYMMDD'"));

        // Table, columns and key come from PostTypes::synced(), never user input; $id is bound.
        $statement = oci_parse($connection, 'SELECT ' . implode(', ', $columns) . ' FROM ' . $table . ($key ? " WHERE $key = :id" : ''));
        if ($statement && $key) {
            oci_bind_by_name($statement, ':id', $id);
        }
        if (!$statement || !@oci_execute($statement, OCI_DEFAULT)) {
            throw new RuntimeException("Oracle query on $table failed: " . (oci_error($statement ?: $connection)['message'] ?? 'unknown error'));
        }

        $rows = [];
        oci_fetch_all($statement, $rows, 0, -1, OCI_FETCHSTATEMENT_BY_ROW | OCI_ASSOC | OCI_RETURN_NULLS);

        return array_map(fn (array $row): array => array_map(fn ($value): string => (string) $value, $row), $rows);
    }
}
