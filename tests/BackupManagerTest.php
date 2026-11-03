<?php

use Abduns\DbBackup\BackupManager;
use Abduns\DbBackup\Dumpers\MySqlDumper;
use Abduns\DbBackup\Dumpers\PostgresDumper;
use Abduns\DbBackup\Enums\BackupStatus;
use Abduns\DbBackup\Events\BackupFailed;
use Abduns\DbBackup\Events\BackupSucceeded;
use Abduns\DbBackup\Jobs\RunDatabaseBackup;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('backups');
});

test('a sqlite database is copied, gzipped and uploaded to the disk', function () {
    Event::fake([BackupSucceeded::class]);
    Schema::create('notes', fn ($table) => $table->string('body'));
    DB::table('notes')->insert(['body' => 'keep me']);

    $backup = app(BackupManager::class)->run();

    expect($backup->status)->toBe(BackupStatus::Succeeded)
        ->and($backup->driver)->toBe('sqlite')
        ->and($backup->path)->toStartWith('db-backups/testing-')
        ->and($backup->path)->toEndWith('.sqlite.gz')
        ->and($backup->size)->toBeGreaterThan(0)
        ->and($backup->finished_at)->not->toBeNull();

    $copy = gzdecode(Storage::disk('backups')->get($backup->path));
    expect($copy)->toStartWith('SQLite format 3')
        ->and($copy)->toContain('keep me')
        ->and(glob(config('db-backup.temporary_directory').'/*'))->toBe([]);

    Event::assertDispatched(BackupSucceeded::class, fn (BackupSucceeded $event): bool => $event->backup->is($backup));
});

test('a failed dump is recorded on the row and announced, and nothing is uploaded', function () {
    Event::fake([BackupFailed::class]);
    Process::fake(['*' => Process::result(errorOutput: 'connection to server failed', exitCode: 1)]);

    $backup = app(BackupManager::class)->run('pgsql');

    expect($backup->status)->toBe(BackupStatus::Failed)
        ->and($backup->error)->toContain('connection to server failed')
        ->and($backup->path)->toBeNull()
        ->and(Storage::disk('backups')->allFiles())->toBe([]);

    Event::assertDispatched(BackupFailed::class);
});

test('pg_dump gets the connection details and the password only through a private password file', function () {
    $passwordFile = null;
    $passwordFileContents = null;
    $passwordFileMode = null;
    $dumpFileMode = null;

    Process::fake(function ($process) use (&$passwordFile, &$passwordFileContents, &$passwordFileMode, &$dumpFileMode) {
        $passwordFile = $process->environment['PGPASSFILE'];
        $passwordFileContents = file_get_contents($passwordFile);
        $passwordFileMode = fileperms($passwordFile) & 0777;
        $dumpFileMode = fileperms('/tmp/out.dump') & 0777;

        return Process::result();
    });
    config(['db-backup.options.pgsql' => ['--no-owner']]);

    (new PostgresDumper)->dump('pgsql', [
        'host' => 'db.internal',
        'port' => 6543,
        'username' => 'app',
        'password' => 'se:c\\ret',
        'database' => 'production',
        'sslmode' => 'verify-full',
        'sslrootcert' => '/etc/ssl/db.crt',
    ], '/tmp/out.dump');

    Process::assertRan(fn ($process): bool => $process->command === [
        'pg_dump',
        '--format=custom',
        '--no-owner',
        '--file=/tmp/out.dump',
        '--host=db.internal',
        '--port=6543',
        '--username=app',
        '--dbname=production',
    ] && ! array_key_exists('PGPASSWORD', $process->environment)
        && $process->environment['PGSSLMODE'] === 'verify-full'
        && $process->environment['PGSSLROOTCERT'] === '/etc/ssl/db.crt');

    expect($passwordFileContents)->toBe("*:*:*:*:se\\:c\\\\ret\n")
        ->and($passwordFileMode)->toBe(0600)
        ->and($dumpFileMode)->toBe(0600)
        ->and(file_exists($passwordFile))->toBeFalse();

    @unlink('/tmp/out.dump');
});

test('pg_dump runs without a password file when the connection has no password', function () {
    Process::fake();

    (new PostgresDumper)->dump('pgsql', ['host' => 'db.internal', 'username' => 'app', 'database' => 'production'], '/tmp/out.dump');

    Process::assertRan(fn ($process): bool => ! array_key_exists('PGPASSFILE', $process->environment));

    @unlink('/tmp/out.dump');
});

test('mysqldump reads the password from a private option file and the dump is gzipped', function () {
    $optionFileContents = null;
    $optionFileMode = null;
    $resultFileMode = null;

    Process::fake(function ($process) use (&$optionFileContents, &$optionFileMode, &$resultFileMode) {
        $optionFile = str_replace('--defaults-extra-file=', '', $process->command[1]);
        $optionFileContents = file_get_contents($optionFile);
        $optionFileMode = fileperms($optionFile) & 0777;
        $resultFile = str_replace('--result-file=', '', collect($process->command)->first(fn (string $argument): bool => str_starts_with($argument, '--result-file=')));
        $resultFileMode = fileperms($resultFile) & 0777;
        file_put_contents($resultFile, 'CREATE TABLE notes;');

        return Process::result();
    });

    $path = sys_get_temp_dir().'/db-backup-mysql-test.sql.gz';

    (new MySqlDumper)->dump('mysql', [
        'host' => 'db.internal',
        'port' => 3307,
        'username' => 'app',
        'password' => "p\"s\ns",
        'database' => 'production',
    ], $path);

    expect($optionFileContents)->toBe("[client]\npassword=\"p\\\"s\\ns\"\n")
        ->and($optionFileMode)->toBe(0600)
        ->and($resultFileMode)->toBe(0600)
        ->and(fileperms($path) & 0777)->toBe(0600)
        ->and(gzdecode(file_get_contents($path)))->toBe('CREATE TABLE notes;');

    Process::assertRan(fn ($process): bool => ! in_array("p\"s\ns", $process->command, true)
        && in_array('--host=db.internal', $process->command, true)
        && end($process->command) === 'production');

    @unlink($path);
});

test('the uploaded file and the temporary folder are readable by this user only', function () {
    File::deleteDirectory(config('db-backup.temporary_directory'));

    $backup = app(BackupManager::class)->run();

    expect($backup->checksum)->toBe(hash('sha256', Storage::disk('backups')->get($backup->path)))
        ->and(fileperms(Storage::disk('backups')->path($backup->path)) & 0777)->toBe(0600)
        ->and(fileperms(config('db-backup.temporary_directory')) & 0777)->toBe(0700);
});

test('a backup never goes to a public disk', function () {
    Event::fake([BackupFailed::class]);
    config(['filesystems.disks.backups.visibility' => 'public']);

    $backup = app(BackupManager::class)->run();

    expect($backup->status)->toBe(BackupStatus::Failed)
        ->and($backup->error)->toBe('The "backups" disk is public. Backups hold the whole database and must go to a private disk.')
        ->and(Storage::disk('backups')->allFiles())->toBe([]);

    Event::assertDispatched(BackupFailed::class);
});

test('a driver without a dumper fails with a clear reason', function () {
    config(['db-backup.dumpers.sqlite' => null]);

    $backup = app(BackupManager::class)->run();

    expect($backup->status)->toBe(BackupStatus::Failed)
        ->and($backup->error)->toBe('No dumper is configured for the "sqlite" driver of the "testing" connection.');
});

test('a queued backup is recorded as pending and run by a job', function () {
    Queue::fake();

    $backup = app(BackupManager::class)->queue();

    expect($backup->status)->toBe(BackupStatus::Pending);
    Queue::assertPushed(RunDatabaseBackup::class, fn (RunDatabaseBackup $job): bool => $job->backup->is($backup));
});

test('a backup that already started is not run again', function () {
    $backup = backupRecord(['status' => BackupStatus::Running, 'path' => null]);

    app(BackupManager::class)->perform($backup);

    expect($backup->fresh()->status)->toBe(BackupStatus::Running);
});

test('a backup fails while another backup of the same connection holds the lock', function () {
    $lock = Cache::lock('db-backup:testing', 60);
    $lock->get();

    $backup = app(BackupManager::class)->run();

    expect($backup->status)->toBe(BackupStatus::Failed)
        ->and($backup->error)->toBe('Another backup of this connection is still running.');

    $lock->release();
});

test('runs that never finished are failed when the next backup starts', function () {
    $abandoned = backupRecord(['status' => BackupStatus::Running, 'path' => null, 'created_at' => now()->subDay()]);
    $recent = backupRecord(['status' => BackupStatus::Pending, 'path' => null, 'created_at' => now()->subMinutes(5)]);

    app(BackupManager::class)->run();

    expect($abandoned->fresh()->status)->toBe(BackupStatus::Failed)
        ->and($abandoned->fresh()->error)->toBe('The backup did not finish.')
        ->and($recent->fresh()->status)->toBe(BackupStatus::Pending);
});

test('a job that dies mid-backup leaves the row failed', function () {
    $backup = backupRecord(['status' => BackupStatus::Running, 'path' => null]);

    (new RunDatabaseBackup($backup))->failed(new RuntimeException('Worker timed out.'));

    expect($backup->fresh()->status)->toBe(BackupStatus::Failed)
        ->and($backup->fresh()->error)->toBe('Worker timed out.');
});
