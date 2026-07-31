#!/usr/bin/env bash

set -euo pipefail

backup_directory="${1:-./backups}"
timestamp="$(date +%Y-%m-%d-%H%M%S)"
backup_path="${backup_directory}/anbk-${timestamp}.dump"
partial_path="${backup_path}.partial"

mkdir -p "${backup_directory}"

docker compose exec -T postgres sh -c \
    'pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" -Fc' > "${partial_path}"

mv "${partial_path}" "${backup_path}"
chmod 600 "${backup_path}"

echo "Backup selesai: ${backup_path}"
