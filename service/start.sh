#!/bin/bash

set -euo pipefail

ENGINE_DIR="$(cd "$(dirname "$0")/.." && pwd)"
PORT=12346
PHP_BIN="${PHP_BIN:-php}"
SERVICE_LOG="${ENGINE_DIR}/log/service.log"
FALLBACK_SERVICE_LOG="/tmp/stobe_service.log"
MANAGER_SCRIPT="${ENGINE_DIR}/service/manager.php"
LOCK_FILE="/tmp/stobe_background_processor.lock"
MANAGER_TIMEOUT_SECONDS="${STOBE_BACKGROUND_MANAGER_TIMEOUT_SECONDS:-600}"

umask 0002

case "${MANAGER_TIMEOUT_SECONDS}" in
    ''|*[!0-9]*) MANAGER_TIMEOUT_SECONDS=600 ;;
esac
if [ "${MANAGER_TIMEOUT_SECONDS}" -lt 60 ]; then
    MANAGER_TIMEOUT_SECONDS=60
fi

# Hold the lock for the daemon lifetime so concurrent web requests cannot
# create duplicate manager loops before the listener binds.
if ! command -v flock >/dev/null 2>&1; then
    echo "Cannot start Stobe background processor: flock is unavailable."
    exit 1
fi
touch "${LOCK_FILE}" 2>/dev/null || {
    echo "Cannot create Stobe background processor lock file: ${LOCK_FILE}"
    exit 1
}
chmod 0666 "${LOCK_FILE}" 2>/dev/null || true
exec 9>"${LOCK_FILE}" || {
    echo "Cannot open Stobe background processor lock file: ${LOCK_FILE}"
    exit 1
}
if ! flock -n 9; then
    echo "An instance of the Stobe background processor is already running."
    exit 0
fi

# Ensure a writable service log is available.
mkdir -p "${ENGINE_DIR}/log" 2>/dev/null
if ! touch "${SERVICE_LOG}" 2>/dev/null; then
    SERVICE_LOG="${FALLBACK_SERVICE_LOG}"
    touch "${SERVICE_LOG}" 2>/dev/null || true
fi

# Refuse to claim a port already owned by another process.
if nc -z 127.0.0.1 "${PORT}" 2>/dev/null; then
    echo "Cannot start Stobe background processor: port ${PORT} is already in use."
    exit 1
fi

# Keep the health socket continuously bound for the manager lifetime.
nc -lk -p "${PORT}" </dev/null >/dev/null 2>&1 &
LISTENER_PID=$!

trap "kill ${LISTENER_PID} 2>/dev/null || true" EXIT

sleep 0.1
if ! kill -0 "${LISTENER_PID}" 2>/dev/null || ! nc -z 127.0.0.1 "${PORT}" 2>/dev/null; then
    echo "Cannot start Stobe background processor: listener failed to bind port ${PORT}."
    exit 1
fi

while true; do
    if ! kill -0 "${LISTENER_PID}" 2>/dev/null || ! nc -z 127.0.0.1 "${PORT}" 2>/dev/null; then
        printf '%s Background listener stopped; exiting for guarded restart.\n' \
            "$(date -u +'%Y-%m-%dT%H:%M:%SZ')" >> "${SERVICE_LOG}"
        exit 1
    fi

    manager_status=0
    if command -v timeout >/dev/null 2>&1; then
        timeout --signal=TERM --kill-after=10s "${MANAGER_TIMEOUT_SECONDS}s" \
            "${PHP_BIN}" "${MANAGER_SCRIPT}" >> "${SERVICE_LOG}" 2>&1 || manager_status=$?
    else
        "${PHP_BIN}" "${MANAGER_SCRIPT}" >> "${SERVICE_LOG}" 2>&1 || manager_status=$?
    fi

    if [ "${manager_status}" -eq 124 ] || [ "${manager_status}" -eq 137 ]; then
        printf '%s Background manager exceeded %ss timeout; restarting loop.\n' \
            "$(date -u +'%Y-%m-%dT%H:%M:%SZ')" "${MANAGER_TIMEOUT_SECONDS}" >> "${SERVICE_LOG}"
    elif [ "${manager_status}" -ne 0 ]; then
        printf '%s Background manager exited with status %s; restarting loop.\n' \
            "$(date -u +'%Y-%m-%dT%H:%M:%SZ')" "${manager_status}" >> "${SERVICE_LOG}"
    fi
    sleep 5
done
