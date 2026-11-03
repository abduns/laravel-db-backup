# Changelog

All notable changes to this package are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the package uses [Semantic Versioning](https://semver.org/).

## [1.1.0] - 2026-11-03

Security hardening. Run `php artisan vendor:publish --tag=db-backup-migrations` and `php artisan migrate` after updating: a `checksum` column was added to `database_backups`.

### Added

- Every successful backup records the SHA-256 of its file in `database_backups.checksum`. Before a restore, the downloaded file is compared with the recorded checksum and refused when it differs, so a file changed on the disk is never loaded.
- `pg_dump` and `pg_restore` are given the connection's `sslcert`, `sslkey` and `sslrootcert` settings, next to `sslmode`.
- A "Security" section in the README on the trust boundary of the backup disk, credentials, temporary files and authorization.

### Changed

- `pg_dump` and `pg_restore` read the password from a private password file (`PGPASSFILE`) that is deleted afterwards, instead of the `PGPASSWORD` environment variable.
- The temporary directory is created for the running user only, and every dump, intermediate file and download in it is readable by that user only.
- Backups written to a local disk are stored readable by the running user only.
- A backup to a disk whose `visibility` is `public` fails with a clear error and fires `BackupFailed`, instead of publishing the database.
- `BackupRestorer::find()` and `db-backup:restore` only accept files inside the configured backup folder; a path elsewhere on the disk, or one that climbs out of the folder, is reported as not found.
- The MySQL option file escapes line breaks in the password, so a password cannot add options.
- A restored SQLite database keeps the permissions of the file it replaces.

## [1.0.0] - 2026-11-03

First public release.

- Back up PostgreSQL (`pg_dump` custom format), MySQL/MariaDB (`mysqldump`, gzipped) and SQLite (`VACUUM INTO`, gzipped) to any filesystem disk.
- `database_backups` history table with status, size, error and who started each run.
- Retention by count and age with `db-backup:prune`; the newest successful backup is never pruned.
- `db-backup:monitor` and the `BackupIsStale` event for overdue backups.
- `db-backup:restore` with a safety backup of the current database, driver checks and history repair.
- Optional daily schedule for the backup, prune and monitor commands.

[1.1.0]: https://github.com/abduns/laravel-db-backup/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/abduns/laravel-db-backup/releases/tag/v1.0.0
