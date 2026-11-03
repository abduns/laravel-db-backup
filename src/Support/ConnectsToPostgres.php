<?php

namespace Abduns\DbBackup\Support;

use Illuminate\Support\Arr;
use RuntimeException;

/**
 * Connection flags and environment shared by pg_dump and pg_restore. The
 * password goes through a private password file (PGPASSFILE), so it is never
 * on the command line or in the process environment; the file must be deleted
 * afterwards.
 */
trait ConnectsToPostgres
{
    /**
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    protected function postgresArguments(array $config): array
    {
        return [
            '--host='.Arr::first(Arr::wrap($config['host'] ?? '127.0.0.1')),
            '--port='.($config['port'] ?? 5432),
            '--username='.($config['username'] ?? ''),
            '--dbname='.($config['database'] ?? ''),
        ];
    }

    /**
     * The environment for the binary: where the password file is, and the
     * same SSL settings the application connects with.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, string>
     */
    protected function postgresEnvironment(array $config, ?string $passwordFile): array
    {
        return array_filter([
            'PGPASSFILE' => (string) $passwordFile,
            'PGSSLMODE' => (string) ($config['sslmode'] ?? ''),
            'PGSSLCERT' => (string) ($config['sslcert'] ?? ''),
            'PGSSLKEY' => (string) ($config['sslkey'] ?? ''),
            'PGSSLROOTCERT' => (string) ($config['sslrootcert'] ?? ''),
        ], fn (string $value): bool => $value !== '');
    }

    /**
     * Write the password to a file only this user can read, in the format
     * libpq expects, and return its path. Null when there is no password.
     */
    protected function writePostgresPasswordFile(string $password): ?string
    {
        if ($password === '') {
            return null;
        }

        $file = tempnam(sys_get_temp_dir(), 'db-backup-');

        if ($file === false) {
            throw new RuntimeException('Could not create the PostgreSQL password file.');
        }

        // libpq ignores the file unless only its owner can read it.
        chmod($file, 0600);
        file_put_contents($file, '*:*:*:*:'.addcslashes($password, '\\:')."\n");

        return $file;
    }
}
