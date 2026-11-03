# Changelog

All notable changes to this package are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the package uses [Semantic Versioning](https://semver.org/).

## [1.0.0] - 2026-11-03

First public release.

- Back up PostgreSQL (`pg_dump` custom format), MySQL/MariaDB (`mysqldump`, gzipped) and SQLite (`VACUUM INTO`, gzipped) to any filesystem disk.
- `database_backups` history table with status, size, error and who started each run.
- Retention by count and age with `db-backup:prune`; the newest successful backup is never pruned.
- `db-backup:monitor` and the `BackupIsStale` event for overdue backups.
- `db-backup:restore` with a safety backup of the current database, driver checks and history repair.
- Optional daily schedule for the backup, prune and monitor commands.

[1.0.0]: https://github.com/abduns/laravel-db-backup/releases/tag/v1.0.0
