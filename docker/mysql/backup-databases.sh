#!/bin/bash
# Dump the platform database and every tenant database that has not been dropped.
# The password stays in a mode 600 client file. The script prints the backup directory name only.
set -euo pipefail

umask 077

if [ -z "${MYSQL_ROOT_PASSWORD:-}" ]; then
    echo "MYSQL_ROOT_PASSWORD is required." >&2
    exit 1
fi

out="${BACKUP_DIR:-/backups}"
stamp="$(date -u +%Y%m%dT%H%M%SZ)"
dest="${out}/${stamp}"
mkdir -p "$dest"

cnf="$(mktemp)"
trap 'rm -f "$cnf"' EXIT
cat > "$cnf" <<EOF
[client]
user=root
password=${MYSQL_ROOT_PASSWORD}
host=127.0.0.1
protocol=TCP
EOF
chmod 600 "$cnf"

platform="${MYSQL_DATABASE:-platform}"

dump_one() {
    name="$1"
    case "$name" in
        *[!A-Za-z0-9_]*)
            echo "Refusing a database name that is not a simple identifier." >&2
            exit 1
            ;;
    esac
    mysqldump --defaults-extra-file="$cnf" \
        --single-transaction \
        --routines \
        --triggers \
        --hex-blob \
        --databases "$name" | gzip -c > "${dest}/${name}.sql.gz"
}

dump_one "$platform"

mysql --defaults-extra-file="$cnf" --batch --skip-column-names "$platform" \
    --execute "SELECT database_name FROM tenant_database_connections WHERE dropped_at IS NULL" \
    | while IFS= read -r name; do
        if [ -n "$name" ]; then
            dump_one "$name"
        fi
    done

printf '%s\n' "$stamp"
