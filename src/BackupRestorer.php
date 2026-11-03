<?php

namespace Abduns\DbBackup;

use Abduns\DbBackup\Enums\BackupStatus;
use Abduns\DbBackup\Events\BackupRestored;
use Abduns\DbBackup\Exceptions\DumpFailed;
use Abduns\DbBackup\Exceptions\RestoreFailed;
use Abduns\DbBackup\Models\DatabaseBackup;
use Abduns\DbBackup\Restorers\Restorer;
use Abduns\DbBackup\Support\TemporaryDirectory;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Loads a backup from the disk back into a database connection.
 *
 * Backups are found by listing the disk rather than reading the history
 * table, so a brand-new server with an empty database can restore. The
 * history comes back with the restore, because it is part of the dump.
 */
class BackupRestorer
{
    public function __construct(
        private Container $container,
        private BackupManager $backups,
    ) {}

    /**
     * Backups of the connection on the disk, newest first.
     *
     * @return Collection<int, RestorableBackup>
     */
    public function available(?string $connection = null): Collection
    {
        $connection = $this->backups->connectionName($connection);

        return collect(Storage::disk($this->disk())->files($this->directory()))
            ->map(fn (string $path): ?RestorableBackup => RestorableBackup::fromPath($path))
            ->filter(fn (?RestorableBackup $backup): bool => $backup?->connection === $connection)
            ->sortByDesc(fn (RestorableBackup $backup): int => $backup->takenAt->getTimestamp())
            ->values();
    }

    /**
     * The newest regular backup of the connection. Labelled backups, such as
     * pre-restore safety copies, are skipped.
     */
    public function latest(?string $connection = null): ?RestorableBackup
    {
        return $this->available($connection)->first(fn (RestorableBackup $backup): bool => $backup->label === null);
    }

    /**
     * Find a backup by file name, or by its path on the disk. Only files in
     * the configured backup folder are restorable: a path anywhere else on
     * the disk, or one that climbs out of the folder, is not found.
     */
    public function find(string $fileNameOrPath): ?RestorableBackup
    {
        $path = str_contains($fileNameOrPath, '/')
            ? ltrim($fileNameOrPath, '/')
            : ltrim($this->directory().'/'.$fileNameOrPath, '/');

        if (dirname($path) !== ($this->directory() === '' ? '.' : $this->directory())) {
            return null;
        }

        $backup = RestorableBackup::fromPath($path);

        return $backup !== null && Storage::disk($this->disk())->exists($path) ? $backup : null;
    }

    /**
     * Replace everything in the connection's database with the backup.
     *
     * The file is downloaded and checked against the checksum recorded when
     * it was taken, when the history still has it: a restore loads whatever
     * the file says into the database, so a file changed on the disk is
     * refused. When the database has tables, a safety backup is taken next
     * and the restore stops if it fails, so nothing is lost by restoring the
     * wrong file. Returns that safety backup, or null when none was taken.
     *
     * @throws RestoreFailed
     */
    public function restore(RestorableBackup $backup, ?string $connection = null, bool $takeSafetyBackup = true): ?DatabaseBackup
    {
        $connection = $this->backups->connectionName($connection);
        $config = DB::connection($connection)->getConfig();
        $driver = (string) ($config['driver'] ?? '');
        $restorer = $this->restorerFor($connection, $driver);

        $this->ensureFormatMatches($backup, $connection, $driver);

        $localPath = $this->download($backup);

        try {
            $checksum = $this->verifiedChecksum($backup, $localPath);

            $safetyBackup = null;

            if ($takeSafetyBackup && $this->hasTables($connection)) {
                $safetyBackup = $this->backups->run($connection, label: 'pre-restore');

                if (! $safetyBackup->isSucceeded()) {
                    throw new RestoreFailed('The safety backup failed, so nothing was restored: '.$safetyBackup->error);
                }
            }

            try {
                if ($restorer->needsEmptyDatabase()) {
                    $this->emptyDatabase($connection, $driver);
                }

                $restorer->restore($connection, $config, $localPath);
            } catch (RestoreFailed $exception) {
                throw $exception;
            } catch (Throwable $exception) {
                throw new RestoreFailed($exception->getMessage(), previous: $exception);
            } finally {
                DB::purge($connection);
            }
        } finally {
            @unlink($localPath);
        }

        $this->repairHistory($backup, $safetyBackup, $checksum);

        event(new BackupRestored($backup, $connection, $safetyBackup));

        return $safetyBackup;
    }

    /**
     * The connection to use: the one given, db-backup.connection, or the default.
     */
    public function connectionName(?string $connection = null): string
    {
        return $this->backups->connectionName($connection);
    }

    private function restorerFor(string $connection, string $driver): Restorer
    {
        $class = config('db-backup.restorers.'.$driver);

        if (! is_string($class) || $class === '') {
            throw new RestoreFailed(sprintf('No restorer is configured for the "%s" driver of the "%s" connection.', $driver, $connection));
        }

        return $this->container->make($class);
    }

    /**
     * A pg_dump file cannot be loaded into MySQL and so on: the file's
     * extension must be what the connection's own dumper writes.
     */
    private function ensureFormatMatches(RestorableBackup $backup, string $connection, string $driver): void
    {
        try {
            $expected = $this->backups->dumperFor($connection, $driver)->extension();
        } catch (DumpFailed $exception) {
            throw new RestoreFailed($exception->getMessage(), previous: $exception);
        }

        if ($backup->extension !== $expected) {
            throw new RestoreFailed(sprintf(
                '%s is a .%s backup, but the "%s" connection (%s) needs a .%s backup.',
                $backup->fileName,
                $backup->extension,
                $connection,
                $driver,
                $expected,
            ));
        }
    }

    private function hasTables(string $connection): bool
    {
        return Schema::connection($connection)->getTableListing() !== [];
    }

    private function download(RestorableBackup $backup): string
    {
        $localPath = TemporaryDirectory::prepare().'/restore-'.Str::random(12).'-'.$backup->fileName;
        TemporaryDirectory::createPrivateFile($localPath);

        $input = Storage::disk($this->disk())->readStream($backup->path);
        $output = @fopen($localPath, 'wb');

        if (! is_resource($input) || $output === false) {
            throw new RestoreFailed(sprintf('Could not download %s from the "%s" disk.', $backup->path, $this->disk()));
        }

        try {
            if (stream_copy_to_stream($input, $output) === false) {
                throw new RestoreFailed(sprintf('Could not download %s from the "%s" disk.', $backup->path, $this->disk()));
            }
        } finally {
            fclose($input);
            fclose($output);
        }

        return $localPath;
    }

    /**
     * The SHA-256 of the downloaded file, after checking it against the one
     * recorded when the backup was taken. Nothing is checked when the history
     * does not know the file, as on a new server with an empty database.
     *
     * @throws RestoreFailed
     */
    private function verifiedChecksum(RestorableBackup $backup, string $localPath): ?string
    {
        $checksum = hash_file('sha256', $localPath) ?: null;
        $recorded = $this->recordedChecksum($backup);

        if ($recorded !== null && ! hash_equals($recorded, (string) $checksum)) {
            throw new RestoreFailed(sprintf(
                '%s does not match the checksum recorded when it was taken, so it was not restored. The file was changed on the "%s" disk.',
                $backup->fileName,
                $this->disk(),
            ));
        }

        return $checksum;
    }

    private function recordedChecksum(RestorableBackup $backup): ?string
    {
        if (! $this->hasHistoryTable()) {
            return null;
        }

        $checksum = DatabaseBackup::query()
            ->succeeded()
            ->where('disk', $this->disk())
            ->where('path', $backup->path)
            ->whereNotNull('checksum')
            ->value('checksum');

        return is_string($checksum) && $checksum !== '' ? $checksum : null;
    }

    private function hasHistoryTable(): bool
    {
        $model = new DatabaseBackup;

        return Schema::connection($model->getConnectionName())->hasTable($model->getTable());
    }

    /**
     * Drop every table, view and (on PostgreSQL) type, so tables the backup
     * does not have, such as ones added by newer migrations, do not linger.
     */
    private function emptyDatabase(string $connection, string $driver): void
    {
        $schema = Schema::connection($connection);
        $schema->dropAllViews();
        $schema->dropAllTables();

        if ($driver === 'pgsql') {
            $schema->dropAllTypes();
        }
    }

    /**
     * The dump was taken while its own history row still said "running", and
     * the safety backup's row was wiped by the restore. Put both right so the
     * history matches the files on the disk and retention can prune them.
     */
    private function repairHistory(RestorableBackup $backup, ?DatabaseBackup $safetyBackup, ?string $checksum): void
    {
        if (! $this->hasHistoryTable()) {
            return;
        }

        $restoredRow = DatabaseBackup::query()
            ->where('connection', $backup->connection)
            ->unfinished()
            ->get()
            ->first(fn (DatabaseBackup $row): bool => $row->started_at?->format('Y-m-d-His') === $backup->takenAt->format('Y-m-d-His'));

        $restoredRow?->forceFill([
            'status' => BackupStatus::Succeeded,
            'path' => $backup->path,
            'file_name' => $backup->fileName,
            'size' => Storage::disk($this->disk())->size($backup->path),
            'checksum' => $checksum,
            'finished_at' => $restoredRow->started_at,
        ])->save();

        if ($safetyBackup !== null && ! DatabaseBackup::query()->where('uuid', $safetyBackup->uuid)->exists()) {
            (new DatabaseBackup)->forceFill(Arr::except($safetyBackup->getAttributes(), ['id']))->save();
        }
    }

    private function disk(): string
    {
        return (string) config('db-backup.disk');
    }

    private function directory(): string
    {
        return trim((string) config('db-backup.path'), '/');
    }
}
