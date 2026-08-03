#!/usr/bin/env bash

set -Eeuo pipefail

if [[ "${STOBE_RUN_SQL_ASCII_INTEGRATION:-}" != "1" ]]; then
    echo "Set STOBE_RUN_SQL_ASCII_INTEGRATION=1 to run the destructive disposable-database test." >&2
    exit 2
fi

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
database="${STOBE_ASCII_TEST_DB:-stobe_ascii_upgrade_test}"
seed_database="${database}_seed"
owner="${STOBE_DB_USER:-dwemer}"
host="${STOBE_DB_ADMIN_HOST:-127.0.0.1}"
port="${STOBE_DB_ADMIN_PORT:-5432}"
admin_user="${STOBE_DB_ADMIN_USER:-postgres}"
admin_password="${STOBE_DB_ADMIN_PASSWORD:-}"
app_password="${STOBE_DB_PASSWORD:-dwemer}"
backup_dir="${STOBE_BACKUP_DIR:-${TMPDIR:-/tmp}/stobe-ascii-upgrade-test}"
seed_dump="${backup_dir}/${seed_database}.dump"
http_port="${STOBE_ASCII_TEST_HTTP_PORT:-18083}"
server_pid=""

if [[ -z "$backup_dir" || "$backup_dir" == "/" ]]; then
    echo "Refusing unsafe test backup directory: ${backup_dir:-<empty>}" >&2
    exit 2
fi

if [[ ! "$database" =~ ^[A-Za-z_][A-Za-z0-9_]*(_test|_test_[A-Za-z0-9_]*)$ ]]; then
    echo "Refusing to use non-test database name: ${database}" >&2
    exit 2
fi

admin() {
    PGPASSWORD="$admin_password" "$1" -h "$host" -p "$port" -U "$admin_user" "${@:2}"
}

assert_profile_llm_defaults() {
    local target_database="$1"
    local actual
    local expected
    actual="$(PGPASSWORD="$app_password" psql -h "$host" -p "$port" -U "$owner" -d "$target_database" \
        -X -AtF '|' -c "SELECT p.label, lp.name, ls.name, lt.name, lq.name
                       FROM core_profiles p
                       LEFT JOIN core_llm_connector lp ON lp.id = p.llm_primary_id
                       LEFT JOIN core_llm_connector ls ON ls.id = p.llm_secondary_id
                       LEFT JOIN core_llm_connector lt ON lt.id = p.llm_tertiary_id
                       LEFT JOIN core_llm_connector lq ON lq.id = p.llm_quaternary_id
                       WHERE COALESCE(p.is_default_npc, FALSE) = TRUE
                          OR COALESCE(p.is_player_faction_profile, FALSE) = TRUE
                       ORDER BY p.label")"
    expected=$'Default Profile|GLM 4.7|Gemini 2.5 Flash Lite|GLM 5.2|DeepSeek V4 Pro\nPlayer Faction|GLM 4.7|Gemini 2.5 Flash Lite|GLM 5.2|DeepSeek V4 Pro'
    [[ "$actual" == "$expected" ]] || {
        echo "Default profile LLM tiers did not match HerikaServer defaults." >&2
        printf 'Expected:\n%s\nActual:\n%s\n' "$expected" "$actual" >&2
        exit 1
    }
}

database_exists() {
    admin psql -d postgres -X -Atqc "SELECT 1 FROM pg_database WHERE datname = '$1'" | grep -qx 1
}

drop_database_if_exists() {
    local name="$1"
    if database_exists "$name"; then
        admin dropdb --force "$name"
    fi
}

cleanup() {
    set +e
    if [[ -n "$server_pid" ]]; then
        kill "$server_pid" >/dev/null 2>&1 || true
        wait "$server_pid" >/dev/null 2>&1 || true
    fi
    drop_database_if_exists "$seed_database"
    drop_database_if_exists "$database"
    while IFS= read -r leftover; do
        [[ -n "$leftover" ]] && drop_database_if_exists "$leftover"
    done < <(
        admin psql -d postgres -X -Atqc \
            "SELECT datname FROM pg_database WHERE datname LIKE '${database}_sql_ascii_%' OR datname LIKE '${database}_utf8_%'"
    )
    rm -rf "$backup_dir"
}
trap cleanup EXIT

mkdir -p "$backup_dir"
cleanup
trap cleanup EXIT
mkdir -p "$backup_dir"

echo "Creating UTF8 seed database."
admin createdb --owner="$owner" --encoding=UTF8 --locale=C --template=template0 "$seed_database"
PGPASSWORD="$app_password" psql -h "$host" -p "$port" -U "$owner" -d "$seed_database" \
    -v ON_ERROR_STOP=1 -f "$repo_root/data/schema.sql" >/dev/null
assert_profile_llm_defaults "$seed_database"

PGPASSWORD="$app_password" psql -h "$host" -p "$port" -U "$owner" -d "$seed_database" \
    -v ON_ERROR_STOP=1 <<'SQL'
DELETE FROM database_versioning
 WHERE tablename = 'minime_remote_service_url'
   AND version = 202607220101;
DELETE FROM general_settings WHERE id = 'TXTAI_URL';
DELETE FROM database_versioning
 WHERE tablename = 'bio_random'
   AND version = 202606150001;
DROP VIEW combined_bio_random;
ALTER TABLE bio_random DROP COLUMN is_enabled;
ALTER TABLE bio_random_custom DROP COLUMN is_enabled;
CREATE VIEW combined_bio_random AS
SELECT
    c.id,
    c.type,
    c.description,
    c.name,
    c.race,
    c.gender,
    c.faction,
    c.created_at,
    c.updated_at
FROM bio_random_custom c
UNION ALL
SELECT
    b.id,
    b.type,
    b.description,
    b.name,
    b.race,
    b.gender,
    b.faction,
    b.created_at,
    b.updated_at
FROM bio_random b
LEFT JOIN bio_random_custom c
  ON LOWER(b.type) = LOWER(c.type)
 AND LOWER(b.description) = LOWER(c.description)
 AND LOWER(COALESCE(b.name, '')) = LOWER(COALESCE(c.name, ''))
WHERE c.id IS NULL;
INSERT INTO conf_opts (id, value)
VALUES ('ascii_upgrade_marker', U&'Cr\00E8me br\00FBl\00E9e - UTF8 marker')
ON CONFLICT (id) DO UPDATE SET value = EXCLUDED.value;
UPDATE core_profiles
SET llm_secondary_id = NULL,
    llm_tertiary_id = NULL,
    llm_quaternary_id = NULL
WHERE COALESCE(is_default_npc, FALSE) = TRUE
   OR COALESCE(is_player_faction_profile, FALSE) = TRUE;
SQL

admin pg_dump --format=custom --file="$seed_dump" "$seed_database"
drop_database_if_exists "$seed_database"

echo "Restoring the seed into a simulated SQL_ASCII legacy database."
admin createdb --owner="$owner" --encoding=SQL_ASCII --locale=C --template=template0 "$database"
admin pg_restore --exit-on-error --dbname="$database" "$seed_dump"

encoding_before="$(admin psql -d postgres -X -Atqc \
    "SELECT pg_encoding_to_char(encoding) FROM pg_database WHERE datname = '$database'")"
[[ "$encoding_before" == "SQL_ASCII" ]] || {
    echo "Expected SQL_ASCII before upgrade; got ${encoding_before}" >&2
    exit 1
}

echo "Verifying fail-closed HTTP behavior before repair."
STOBE_DB_NAME="$database" \
STOBE_DB_HOST="$host" \
STOBE_DB_USER="$owner" \
STOBE_DB_PASSWORD="$app_password" \
php -S "127.0.0.1:${http_port}" -t "$repo_root" >"$backup_dir/php-server.log" 2>&1 &
server_pid=$!
for _ in {1..40}; do
    if curl -sS -o /dev/null "http://127.0.0.1:${http_port}/health.php"; then
        break
    fi
    sleep 0.1
done

maintenance_status="$(curl -sS -o "$backup_dir/maintenance.html" -w '%{http_code}' \
    "http://127.0.0.1:${http_port}/ui/config_hub.php?tab=bio")"
[[ "$maintenance_status" == "503" ]] || {
    echo "Expected Config Hub HTTP 503 before repair; got ${maintenance_status}" >&2
    exit 1
}
grep -q 'Database upgrade required' "$backup_dir/maintenance.html" || {
    echo "Config Hub did not render the database upgrade message." >&2
    exit 1
}

health_status="$(curl -sS -o "$backup_dir/health.json" -w '%{http_code}' \
    "http://127.0.0.1:${http_port}/health.php")"
[[ "$health_status" == "503" ]] || {
    echo "Expected health HTTP 503 before repair; got ${health_status}" >&2
    exit 1
}
php -r '
$payload = json_decode(file_get_contents($argv[1]), true);
if (!is_array($payload)
    || ($payload["database_upgrade_required"] ?? false) !== true
    || ($payload["database_encoding"] ?? "") !== "SQL_ASCII"
    || !str_contains((string)($payload["database_repair_command"] ?? ""), "run_db_updates.php")) {
    fwrite(STDERR, "Health payload did not expose the required automatic repair state.\n");
    exit(1);
}
' "$backup_dir/health.json"

kill "$server_pid"
wait "$server_pid" 2>/dev/null || true
server_pid=""

echo "Running the same automatic updater used by Stobe downloads."
STOBE_DB_NAME="$database" \
STOBE_DB_HOST="$host" \
STOBE_DB_USER="$owner" \
STOBE_DB_PASSWORD="$app_password" \
STOBE_DB_ADMIN_USER="$admin_user" \
STOBE_DB_ADMIN_PASSWORD="$admin_password" \
STOBE_DB_ADMIN_HOST="$host" \
STOBE_DB_ADMIN_PORT="$port" \
STOBE_BACKUP_DIR="$backup_dir" \
php "$repo_root/debug/run_db_updates.php"
assert_profile_llm_defaults "$database"

encoding_after="$(admin psql -d postgres -X -Atqc \
    "SELECT pg_encoding_to_char(encoding) FROM pg_database WHERE datname = '$database'")"
[[ "$encoding_after" == "UTF8" ]] || {
    echo "Expected UTF8 after upgrade; got ${encoding_after}" >&2
    exit 1
}

marker_hex="$(PGPASSWORD="$app_password" psql -h "$host" -p "$port" -U "$owner" -d "$database" \
    -X -Atqc "SELECT encode(convert_to(value, 'UTF8'), 'hex') FROM conf_opts WHERE id = 'ascii_upgrade_marker'")"
[[ "$marker_hex" == "4372c3a86d65206272c3bb6cc3a965202d2055544638206d61726b6572" ]] || {
    echo "Preserved marker data did not survive migration." >&2
    exit 1
}

setting="$(PGPASSWORD="$app_password" psql -h "$host" -p "$port" -U "$owner" -d "$database" \
    -X -Atqc "SELECT value FROM general_settings WHERE id = 'TXTAI_URL'")"
[[ "$setting" == "http://127.0.0.1:8082" ]] || {
    echo "Pending database update did not restore TXTAI_URL." >&2
    exit 1
}

version="$(PGPASSWORD="$app_password" psql -h "$host" -p "$port" -U "$owner" -d "$database" \
    -X -Atqc "SELECT version FROM database_versioning WHERE tablename = 'minime_remote_service_url'")"
[[ "$version" == "202607220101" ]] || {
    echo "Pending database update version was not recorded." >&2
    exit 1
}

bio_state_columns="$(PGPASSWORD="$app_password" psql -h "$host" -p "$port" -U "$owner" -d "$database" \
    -X -Atqc "SELECT count(*) FROM information_schema.columns
               WHERE table_schema = 'public'
                 AND table_name IN ('bio_random', 'bio_random_custom')
                 AND column_name = 'is_enabled'")"
[[ "$bio_state_columns" == "2" ]] || {
    echo "Pending biography state migration did not restore is_enabled columns." >&2
    exit 1
}

bio_view_state="$(PGPASSWORD="$app_password" psql -h "$host" -p "$port" -U "$owner" -d "$database" \
    -X -Atqc "SELECT count(*) FROM information_schema.columns
               WHERE table_schema = 'public'
                 AND table_name = 'combined_bio_random'
                 AND column_name = 'is_enabled'")"
[[ "$bio_view_state" == "1" ]] || {
    echo "Pending biography state migration did not rebuild combined_bio_random." >&2
    exit 1
}

STOBE_DB_NAME="$database" \
STOBE_DB_HOST="$host" \
STOBE_DB_USER="$owner" \
STOBE_DB_PASSWORD="$app_password" \
php "$repo_root/tests/database_upgrade_regression.php"

echo "SQL_ASCII automatic migration integration test passed."
