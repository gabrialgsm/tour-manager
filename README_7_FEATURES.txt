GMJS – 7 Priority Features

Included:
1. Database backup
   - backup.php: manual downloadable SQL backup
   - cron_backup.php: CLI automatic backup, keeps 14 days
   - Example cron: 0 3 * * * /usr/bin/php /path/to/gmjs/cron_backup.php
   - Make sure the backups/ directory is outside public web access if possible.

2. Audit log
   - audit_log.php
   - Existing project already has audit_log and audit() helper.

3. Tour-day check-in dashboard
   - checkin_dashboard.php
   - Shows outbound/return counts and passenger status.
   - Reset Return Check-in creates CHECK_IN_RETURN_UNDO entries; it does NOT delete passenger data.

4. Return-trip reset
   - Included in checkin_dashboard.php.

5. Bulk passenger tools
   - bulk_passengers.php
   - Print selected tickets / export selected CSV.

6. Passenger Excel-compatible export
   - passenger_export.php
   - CSV opens directly in Excel.
   - Supports departure filter and selected IDs.

7. Tour Close / Archive
   - tour_status.php
   - ACTIVE -> CLOSED -> ARCHIVED.
   - Existing records are preserved.

IMPORTANT:
- No database migration is included.
- Existing passenger/payment/room/bus data is not rewritten by these tools.
- Upload the files beside the existing project PHP files.
- Add one menu link to admin_tools.php if desired.
- Test on a backup/staging copy first because this is a live production project.
- Existing project already uses tour status ACTIVE/CLOSED/ARCHIVED and audit_log.
