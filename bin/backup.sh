#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CONFIG_FILE="${ROOT_DIR}/config.php"
BACKUP_ROOT="${BACKUP_ROOT:-${ROOT_DIR}/../backups/tour-manager}"
RETENTION_DAYS="${RETENTION_DAYS:-14}"
TIMESTAMP="$(date '+%Y%m%d_%H%M%S')"
HOST="${DB_HOST:-}"
USER="${DB_USER:-}"
PASS="${DB_PASS:-}"
NAME="${DB_NAME:-}"

if [[ -f "$CONFIG_FILE" ]]; then
  eval "$(php -r '$c=require $argv[1]; echo "DB_HOST=".escapeshellarg($c["db"]["host"]??"")."\\nDB_USER=".escapeshellarg($c["db"]["user"]??"")."\\nDB_PASS=".escapeshellarg($c["db"]["pass"]??"")."\\nDB_NAME=".escapeshellarg($c["db"]["name"]??"")."\\n";' -- "$CONFIG_FILE")"
fi

: "${HOST:?DB host is required}"
: "${USER:?DB user is required}"
: "${NAME:?DB name is required}"

DEST="${BACKUP_ROOT}/${TIMESTAMP}"
mkdir -p "$DEST"
chmod 700 "$DEST"

printf '%s\\n' "Creating database backup..."
MYSQL_PWD="$PASS" mysqldump --single-transaction --quick --routines --triggers --events --hex-blob --default-character-set=utf8mb4 -h "$HOST" -u "$USER" "$NAME" | gzip -9 > "${DEST}/database.sql.gz"

printf '%s\\n' "Creating application/storage backup..."
tar --exclude='storage/php-error.log' --exclude='storage/cache' --exclude='storage/tmp' -czf "${DEST}/application.tar.gz" -C "$ROOT_DIR" .

sha256sum "$DEST/database.sql.gz" "$DEST/application.tar.gz" > "${DEST}/SHA256SUMS"
printf '%s\\n' "${TIMESTAMP}" > "${DEST}/BACKUP_ID"

echo "Backup created: ${DEST}"

find "$BACKUP_ROOT" -mindepth 1 -maxdepth 1 -type d -mtime "+${RETENTION_DAYS}" -exec rm -rf -- {} +
