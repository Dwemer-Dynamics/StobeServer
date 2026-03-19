#!/bin/bash

set -euo pipefail

ENGINE_DIR="$(cd "$(dirname "$0")/.." && pwd)"
PORT=12346
PHP_BIN="${PHP_BIN:-php}"
SERVICE_LOG="${ENGINE_DIR}/log/service.log"
MANAGER_SCRIPT="${ENGINE_DIR}/service/manager.php"

# Ensure log directory exists.
mkdir -p "${ENGINE_DIR}/log"

# Check if an instance is already listening.
if nc -z 127.0.0.1 "${PORT}" 2>/dev/null; then
    echo "Stobe background processor already running."
    exit 0
fi

listener() {
    local parent_pid="$1"
    while true; do
        if ! kill -0 "${parent_pid}" 2>/dev/null; then
            exit 0
        fi
        echo "${parent_pid}" | nc -l -p "${PORT}" -w 1 >/dev/null 2>&1 || true
        sleep 0.1
    done
}

listener $$ &
LISTENER_PID=$!

trap "kill ${LISTENER_PID} 2>/dev/null || true" EXIT

while true; do
    "${PHP_BIN}" "${MANAGER_SCRIPT}" >> "${SERVICE_LOG}" 2>&1 || true
    sleep 5
done
