#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
COMPOSE_FILE="${COMPOSE_FILE:-$ROOT_DIR/compose.yaml}"
DRILL_DB="${DRILL_DB:-ravn_restore_drill}"
DRILL_TABLE="restore_drill_marker"

if [[ ! "$DRILL_DB" =~ ^[A-Za-z0-9_]+$ ]]; then
    echo "Invalid drill database name: $DRILL_DB" >&2
    exit 1
fi

if [[ "$DRILL_DB" == "ravn" || "$DRILL_DB" == "ravn_test" ]]; then
    echo "Refusing to use $DRILL_DB as the drill database." >&2
    exit 1
fi

tmp_dir="$(mktemp -d "${TMPDIR:-/tmp}/ravn-restore-drill.XXXXXX")"

cleanup() {
    docker compose -f "$COMPOSE_FILE" exec -T mysql sh -c \
        'exec mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -e "DROP DATABASE IF EXISTS $1"' \
        sh "$DRILL_DB" >/dev/null 2>&1 || true
    rm -rf "$tmp_dir"
}

trap cleanup EXIT

mysql_root() {
    local sql="$1"
    docker compose -f "$COMPOSE_FILE" exec -T mysql sh -c \
        'exec mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -e "$1"' sh "$sql"
}

mysql_scalar() {
    local sql="$1"
    docker compose -f "$COMPOSE_FILE" exec -T mysql sh -c \
        'exec mysql -N -B -uroot -p"$MYSQL_ROOT_PASSWORD" -e "$1"' sh "$sql"
}

mysql_root "DROP DATABASE IF EXISTS $DRILL_DB; CREATE DATABASE $DRILL_DB; CREATE TABLE $DRILL_DB.$DRILL_TABLE (id INT PRIMARY KEY, label VARCHAR(80) NOT NULL); INSERT INTO $DRILL_DB.$DRILL_TABLE VALUES (1, 'alpha'), (2, 'beta'), (3, 'gamma');"

backup_file="$(BACKUP_DIR="$tmp_dir" "$ROOT_DIR/scripts/db-backup.sh" "$DRILL_DB")"

mysql_root "DELETE FROM $DRILL_DB.$DRILL_TABLE"

RESTORE_CONFIRM=yes RESTORE_CREATE_DATABASE=0 "$ROOT_DIR/scripts/db-restore.sh" "$DRILL_DB" "$backup_file"

rows="$(mysql_scalar "SELECT COUNT(*) FROM $DRILL_DB.$DRILL_TABLE")"
labels="$(mysql_scalar "SELECT GROUP_CONCAT(label ORDER BY id SEPARATOR ',') FROM $DRILL_DB.$DRILL_TABLE")"

if [[ "$rows" != "3" || "$labels" != "alpha,beta,gamma" ]]; then
    echo "Restore verification failed: rows=$rows labels=$labels" >&2
    exit 1
fi

echo "Restore drill passed: database=$DRILL_DB rows=$rows labels=$labels backup=$(basename "$backup_file")"
