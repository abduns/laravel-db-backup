<?php

namespace Abduns\DbBackup\Support;

use RuntimeException;

/**
 * The folder dumps are written to before upload and after download. A dump
 * holds the whole database, so the folder is created for this user only and
 * every file in it is made private as soon as it exists.
 */
final class TemporaryDirectory
{
    /**
     * Create the configured directory when it is missing and return its path.
     * An existing directory keeps its permissions: it may be shared with the
     * rest of the application.
     */
    public static function prepare(): string
    {
        $directory = rtrim((string) config('db-backup.temporary_directory'), '/');

        if ($directory === '') {
            throw new RuntimeException('db-backup.temporary_directory is not set.');
        }

        if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException(sprintf('Could not create the temporary directory %s.', $directory));
        }

        return $directory;
    }

    /**
     * Create $path as an empty file only this user can read, so a tool that
     * writes into it afterwards keeps those permissions.
     */
    public static function createPrivateFile(string $path): void
    {
        if (! @touch($path) || ! @chmod($path, 0600)) {
            throw new RuntimeException(sprintf('Could not create %s.', $path));
        }
    }

    /**
     * Make an existing file readable by this user only.
     */
    public static function restrict(string $path): void
    {
        @chmod($path, 0600);
    }
}
