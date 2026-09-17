# GMJS Tour Manager — FINAL RELEASE

Production-oriented PHP + MySQL tour management system.

## Backup & Disaster Recovery

Production backups are created by `bin/backup.sh`. The scheduled workflow `.github/workflows/backup.yml` runs daily at 18:30 UTC (00:30 Bangladesh time) and can also be started manually.

Each backup set contains:
- `database.sql.gz` — MySQL dump with routines, triggers and events
- `application.tar.gz` — application plus persistent storage, excluding transient cache/tmp and PHP error logs
- `SHA256SUMS` — integrity checks
- `BACKUP_ID` — backup timestamp

Backups are written outside the application directory by default to `../backups/tour-manager` with restrictive permissions and 14-day local retention.

### Recovery
1. Stop or isolate public traffic.
2. Select a known-good backup directory.
3. Verify `SHA256SUMS`.
4. Run `CONFIRM_RESTORE=YES bin/restore.sh /absolute/path/to/backup/TIMESTAMP`.
5. Verify `config.php` and runtime secrets were preserved.
6. Run migrations/smoke tests and verify critical tour, passenger, payment and ticket data.
7. Re-enable traffic only after checks pass.

The restore script requires explicit `CONFIRM_RESTORE=YES` because database restore is destructive and deliberately does not restore `config.php`.

### DR policy

A local backup alone is not sufficient if the production VPS is lost. Copy backup sets to an independent off-server/object-storage location and periodically test restoration on a separate environment. Recommended baseline: daily backups, 14+ days retention, independent copy, and quarterly restore drills.

## CI/CD

The `saas-rebuild` branch runs automated regression tests through GitHub Actions. Production deployment is gated behind successful tests on `main` and uses SSH with pinned `known_hosts`.
