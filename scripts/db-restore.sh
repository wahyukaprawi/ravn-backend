#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
COMPOSE_FILE="${COMPOSE_FILE:-$ROOT_DIR/compose.yaml}"
SERVICE="${DB_SERVICE:-mysql}"
DATABASE="${1:-}"
BACKUP_FILE="${2:-}"

if [[ -z "$DATABASE" || -z "$BACKUP_FILE" ]]; then
    echo "Usage: $0 <database> <backup.sql.gz>" >&2
    exit 1
fi

if [[ ! "$DATABASE" =~ ^[A-Za-z0-9_]+$ ]]; then
    echo "Invalid database name: $DATABASE" >&2
    exit 1
fi

if [[ "${RESTORE_CONFIRM:-}" != "yes" ]]; then
    echo "Set RESTORE_CONFIRM=yes to restore into $DATABASE." >&2
    exit 1
fi

if [[ ! -f "$BACKUP_FILE" ]]; then
    echo "Backup not found: $BACKUP_FILE" >&2
    exit 1
fi

if [[ -f "${BACKUP_FILE}.sha256" ]]; then
    (
        cd "$(dirname "$BACKUP_FILE")"
        if command -v sha256sum >/dev/null 2>&1; then
            sha256sum -c "$(basename "${BACKUP_FILE}.sha256")"
        else
            shasum -a 256 -c "$(basename "${BACKUP_FILE}.sha256")"
        fi
    )
fi

if [[ "${RESTORE_CREATE_DATABASE:-0}" == "1" ]]; then
    docker compose -f "$COMPOSE_FILE" exec -T "$SERVICE" sh -c \
        'exec mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -e "CREATE DATABASE IF NOT EXISTS $1"' \
        sh "$DATABASE"
fi

gzip -dc "$BACKUP_FILE" | docker compose -f "$COMPOSE_FILE" exec -T "$SERVICE" sh -c \
    'exec mysql -uroot -p"$MYSQL_ROOT_PASSWORD" "$1"' sh "$DATABASE"

echo "Restored $BACKUP_FILE into $DATABASE"
