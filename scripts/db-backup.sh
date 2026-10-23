#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
COMPOSE_FILE="${COMPOSE_FILE:-$ROOT_DIR/compose.yaml}"
SERVICE="${DB_SERVICE:-mysql}"
DATABASE="${1:-${DB_DATABASE:-ravn}}"
OUTPUT_DIR="${BACKUP_DIR:-$ROOT_DIR/storage/backups}"

if [[ ! "$DATABASE" =~ ^[A-Za-z0-9_]+$ ]]; then
    echo "Invalid database name: $DATABASE" >&2
    exit 1
fi

mkdir -p "$OUTPUT_DIR"

stamp="$(date -u +%Y%m%dT%H%M%SZ)"
output="$OUTPUT_DIR/${DATABASE}-${stamp}.sql.gz"
partial="${output}.partial"

trap 'rm -f "$partial"' EXIT

docker compose -f "$COMPOSE_FILE" exec -T "$SERVICE" sh -c \
    'exec mysqldump --single-transaction --quick --lock-tables=false --routines --events --triggers --set-gtid-purged=OFF -uroot -p"$MYSQL_ROOT_PASSWORD" "$1"' \
    sh "$DATABASE" | gzip -9 > "$partial"

mv "$partial" "$output"

if command -v sha256sum >/dev/null 2>&1; then
    checksum="$(sha256sum "$output" | awk '{print $1}')"
else
    checksum="$(shasum -a 256 "$output" | awk '{print $1}')"
fi

printf '%s  %s\n' "$checksum" "$(basename "$output")" > "${output}.sha256"

{
    printf 'database=%s\n' "$DATABASE"
    printf 'created_at=%s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
    printf 'format=mysql-dump-gzip\n'
    printf 'sha256=%s\n' "$checksum"
    printf 'source_service=%s\n' "$SERVICE"
    if git -C "$ROOT_DIR" rev-parse --short HEAD >/dev/null 2>&1; then
        printf 'git_commit=%s\n' "$(git -C "$ROOT_DIR" rev-parse --short HEAD)"
    fi
} > "${output}.meta"

echo "$output"
