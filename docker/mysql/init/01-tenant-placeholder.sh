#!/bin/bash
set -euo pipefail

umask 077
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

case "${MYSQL_USER}" in
    *[!A-Za-z0-9_]*)
        echo "MYSQL_USER must be a simple identifier." >&2
        exit 1
        ;;
esac

mysql --defaults-extra-file="$cnf" <<SQL
CREATE DATABASE IF NOT EXISTS tenant_placeholder CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON tenant_placeholder.* TO '${MYSQL_USER}'@'%';
FLUSH PRIVILEGES;
SQL
