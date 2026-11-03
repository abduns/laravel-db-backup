<?php

namespace Abduns\DbBackup\Dumpers;

use Abduns\DbBackup\Exceptions\DumpFailed;
use Abduns\DbBackup\Support\ConnectsToPostgres;
use Abduns\DbBackup\Support\TemporaryDirectory;
use Illuminate\Support\Facades\Process;

/**
 * pg_dump in its custom format: compressed already, and pg_restore can bring
 * back the whole database or single tables from it.
 */
class PostgresDumper implements Dumper
{
    use ConnectsToPostgres;

    public function dump(string $connection, array $config, string $path): void
    {
        $binary = (string) config('db-backup.binaries.pg_dump', 'pg_dump');
        $passwordFile = $this->writePostgresPasswordFile((string) ($config['password'] ?? ''));

        $command = [
            $binary,
            '--format=custom',
            ...array_values((array) config('db-backup.options.pgsql', [])),
            '--file='.$path,
            ...$this->postgresArguments($config),
        ];

        try {
            // pg_dump writes into the file as it is, so create it private first.
            TemporaryDirectory::createPrivateFile($path);

            $result = Process::timeout((int) config('db-backup.timeout', 3600))
                ->env($this->postgresEnvironment($config, $passwordFile))
                ->run($command);

            if ($result->failed()) {
                throw DumpFailed::fromProcess($binary, $result);
            }
        } finally {
            if ($passwordFile !== null) {
                @unlink($passwordFile);
            }
        }
    }

    public function extension(): string
    {
        return 'dump';
    }
}
