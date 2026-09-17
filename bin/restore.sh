#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CONFIG_FILE="${ROOT_DIR}/config.php"
BACKUP_DIR="${1:-}"

if [[ -z "$BACKUP_DIR" || ! -d "$BACKUP_DIR" ]]; then
  echo "Usage: $0 /absolute/path/to/backup/TIMESTAMP" >&2
  exit 2
fi

[[ -f "$BACKUP_DIR/database.sql.gz" && -f "$BACKUP_DIR/application.tar.gz" && -f "$BACKUP_DIR/SHA256SUMS" ]] || { echo "Incomplete backup set." >&2; exit 1; }
( cd "$BACKUP_DIR" && sha256sum -c SHA256SUMS )

if [[ "${CONFIRM_RESTORE:-}" != "YES" ]]; then
  echo "RESTORE IS DESTRUCTIVE. Set CONFIRM_RESTORE=YES to continue." >&2
  exit 3
fi

eval "$(php -r '$c=require $argv[1]; echo "DB_HOST=".escapeshellarg($c["db"]["host"]??"")."\\nDB_USER=".escapeshellarg($c["db"]["user"]??"")."\\nDB_PASS=".escapeshellarg($c["db"]["pass"]??"")."\\nDB_NAME=".escapeshellarg($c["db"]["name"]??"")."\\n";' -- "$CONFIG_FILE")"
: "${DB_HOST:?DB host is required}"; : "${DB_USER:?DB user is required}"; : "${DB_NAME:?DB name is required}"

printf '%s\\n' "Restoring database..."
MYSQL_PWD="${DB_PASS:-}" gunzip -c "$BACKUP_DIR/database.sql.gz" | mysql -h "$DB_HOST" -u "$DB_USER" "$DB_NAME"

printf '%s\\n' "Restoring application files (config.php and backup directory are preserved)..."
TMP_DIR="$(mktemp -d)"; trap 'rm -rf "$TMP_DIR"' EXIT
tar -xzf "$BACKUP_DIR/application.tar.gz" -C "$TMP_DIR"
rm -f "$TMP_DIR/config.php"
rsync -a --delete --exclude='config.php' --exclude='storage/php-error.log' "$TMP_DIR/" "$ROOT_DIR/"

php -l "$ROOT_DIR/bootstrap_saas.php" >/dev/null
printf '%s\\n' "Restore completed. Run application smoke tests and verify the latest tour/payment/ticket data before reopening traffic."
