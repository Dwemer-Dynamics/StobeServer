#!/usr/bin/env bash

set -Eeuo pipefail

database="${STOBE_DB_NAME:-stobe}"
owner="${STOBE_DB_USER:-dwemer}"
backup_dir="${STOBE_BACKUP_DIR:-/var/backups/stobe}"
timestamp="$(date -u +%Y%m%d%H%M%S)"
staging_database="${database}_utf8_${timestamp}"
backup_database="${database}_sql_ascii_${timestamp}"
backup_file="${backup_dir}/${database}-${timestamp}-sql-ascii.dump"

validate_identifier() {
    local value="$1"
    local label="$2"
    if [[ ! "$value" =~ ^[A-Za-z_][A-Za-z0-9_]*$ ]]; then
        echo "Invalid ${label}: ${value}" >&2
        exit 2
    fi
}

validate_identifier "$database" "database name"
validate_identifier "$owner" "database owner"
validate_identifier "$staging_database" "staging database name"
validate_identifier "$backup_database" "backup database name"

if [[ "$(id -u)" -ne 0 && "$(id -un)" != "postgres" ]]; then
    echo "Run this migration as root or postgres, for example:" >&2
    echo "  sudo bash /var/www/html/StobeServer/tools/migrate-stobe-db-utf8-wsl.sh" >&2
    exit 2
fi

run_postgres() {
    if [[ "$(id -un)" == "postgres" ]]; then
        "$@"
    else
        sudo -n -u postgres "$@"
    fi
}

database_exists() {
    run_postgres psql -d postgres -X -Atqc "SELECT 1 FROM pg_database WHERE datname = '$1'" | grep -qx 1
}

database_encoding() {
    run_postgres psql -d postgres -X -Atqc "SELECT pg_encoding_to_char(encoding) FROM pg_database WHERE datname = '$1'"
}

set_database_read_only() {
    run_postgres psql -d postgres -X -v ON_ERROR_STOP=1 -v db="$1" <<'SQL'
ALTER DATABASE :"db" SET default_transaction_read_only = on;
SELECT pg_terminate_backend(pid)
  FROM pg_stat_activity
 WHERE datname = :'db'
   AND pid <> pg_backend_pid();
SQL
}

restore_database_writes() {
    run_postgres psql -d postgres -X -v ON_ERROR_STOP=1 -v db="$1" <<'SQL'
ALTER DATABASE :"db" WITH ALLOW_CONNECTIONS true;
ALTER DATABASE :"db" RESET default_transaction_read_only;
SQL
}

application_schemas() {
    run_postgres psql -d "$1" -X -At -v ON_ERROR_STOP=1 <<'SQL'
SELECT nspname
  FROM pg_namespace
 WHERE nspname IN ('public', 'stobe_meta')
    OR nspname LIKE 'stobe\_profile\_%' ESCAPE '\'
 ORDER BY nspname;
SQL
}

table_counts() {
    run_postgres psql -d "$1" -X -At -F '|' -v ON_ERROR_STOP=1 <<'SQL'
SELECT format(
           'SELECT %L, count(*) FROM %I.%I;',
           schemaname || '.' || tablename,
           schemaname,
           tablename
       )
  FROM pg_tables
 WHERE schemaname IN ('public', 'stobe_meta')
    OR schemaname LIKE 'stobe\_profile\_%' ESCAPE '\'
 ORDER BY schemaname, tablename
\gexec
SQL
}

sequence_state() {
    run_postgres psql -d "$1" -X -At -F '|' -v ON_ERROR_STOP=1 <<'SQL'
SELECT schemaname || '.' || sequencename,
       COALESCE(last_value::text, 'NULL'),
       start_value,
       increment_by,
       cycle
  FROM pg_sequences
 WHERE schemaname IN ('public', 'stobe_meta')
    OR schemaname LIKE 'stobe\_profile\_%' ESCAPE '\'
 ORDER BY schemaname, sequencename;
SQL
}

migration_complete=0
cleanup() {
    local status=$?
    if [[ "$migration_complete" -eq 1 ]]; then
        return
    fi

    set +e
    if database_exists "$staging_database"; then
        run_postgres dropdb --force "$staging_database" >/dev/null 2>&1
    fi

    if ! database_exists "$database" && database_exists "$backup_database"; then
        run_postgres psql -d postgres -X -v ON_ERROR_STOP=1 \
            -v backup_db="$backup_database" -v db="$database" >/dev/null 2>&1 <<'SQL'
ALTER DATABASE :"backup_db" RENAME TO :"db";
SQL
    fi

    if database_exists "$database"; then
        restore_database_writes "$database" >/dev/null 2>&1
    fi

    echo "Stobe UTF-8 migration failed. The original database was restored when possible." >&2
    echo "The safety dump remains at: ${backup_file}" >&2
    exit "$status"
}
trap cleanup ERR INT TERM

if ! database_exists "$database"; then
    echo "Stobe database '${database}' does not exist." >&2
    exit 1
fi

current_encoding="$(database_encoding "$database")"
if [[ "$current_encoding" == "UTF8" ]]; then
    echo "Stobe database '${database}' already uses UTF8; no migration is required."
    migration_complete=1
    exit 0
fi

if [[ "$current_encoding" != "SQL_ASCII" ]]; then
    echo "Unsupported source encoding '${current_encoding}'. This tool migrates SQL_ASCII to UTF8 only." >&2
    exit 1
fi

mkdir -p "$backup_dir"
if [[ "$(id -un)" != "postgres" ]]; then
    chown postgres:postgres "$backup_dir"
fi

echo "Putting '${database}' into read-only mode and closing active sessions."
set_database_read_only "$database"

echo "Creating safety dump: ${backup_file}"
run_postgres pg_dump --format=custom --encoding=UTF8 --file="$backup_file" "$database"

echo "Creating UTF-8 staging database '${staging_database}'."
run_postgres createdb --owner="$owner" --encoding=UTF8 --locale=C --template=template0 "$staging_database"

echo "Restoring the safety dump into the UTF-8 staging database."
run_postgres pg_restore --exit-on-error --dbname="$staging_database" "$backup_file"

source_schemas="$(application_schemas "$database")"
target_schemas="$(application_schemas "$staging_database")"
if [[ "$source_schemas" != "$target_schemas" ]]; then
    echo "Application-schema verification failed; the original database will remain active." >&2
    diff -u <(printf '%s\n' "$source_schemas") <(printf '%s\n' "$target_schemas") >&2 || true
    false
fi

source_counts="$(table_counts "$database")"
target_counts="$(table_counts "$staging_database")"
if [[ "$source_counts" != "$target_counts" ]]; then
    echo "Row-count verification failed; the original database will remain active." >&2
    diff -u <(printf '%s\n' "$source_counts") <(printf '%s\n' "$target_counts") >&2 || true
    false
fi

source_sequences="$(sequence_state "$database")"
target_sequences="$(sequence_state "$staging_database")"
if [[ "$source_sequences" != "$target_sequences" ]]; then
    echo "Sequence verification failed; the original database will remain active." >&2
    diff -u <(printf '%s\n' "$source_sequences") <(printf '%s\n' "$target_sequences") >&2 || true
    false
fi

target_encoding="$(database_encoding "$staging_database")"
if [[ "$target_encoding" != "UTF8" ]]; then
    echo "Staging database encoding verification failed: ${target_encoding}" >&2
    false
fi

echo "Swapping the verified UTF-8 database into place."
run_postgres psql -d postgres -X -v ON_ERROR_STOP=1 -v db="$database" <<'SQL'
ALTER DATABASE :"db" WITH ALLOW_CONNECTIONS false;
SELECT pg_terminate_backend(pid)
  FROM pg_stat_activity
 WHERE datname = :'db'
   AND pid <> pg_backend_pid();
SQL
run_postgres psql -d postgres -X -v ON_ERROR_STOP=1 \
    -v db="$database" -v backup_db="$backup_database" <<'SQL'
ALTER DATABASE :"db" RENAME TO :"backup_db";
SQL
run_postgres psql -d postgres -X -v ON_ERROR_STOP=1 \
    -v staging_db="$staging_database" -v db="$database" <<'SQL'
ALTER DATABASE :"staging_db" RENAME TO :"db";
SQL
run_postgres psql -d postgres -X -v ON_ERROR_STOP=1 \
    -v db="$database" -v owner="$owner" <<'SQL'
ALTER DATABASE :"db" OWNER TO :"owner";
ALTER DATABASE :"db" WITH ALLOW_CONNECTIONS true;
ALTER DATABASE :"db" RESET default_transaction_read_only;
SQL

migration_complete=1
trap - ERR INT TERM

echo "Stobe database migration completed successfully."
echo "Active database: ${database} (UTF8)"
echo "Rollback database: ${backup_database} (${current_encoding}, connections disabled)"
echo "Safety dump: ${backup_file}"
