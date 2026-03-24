#!/usr/bin/env bash
set -euo pipefail

###############################################################################
# GrandePay Hypervel — Postback End-to-End Benchmark (WSL)
#
# Flow: k6 → POST /api/webhooks/firebank → Hypervel processes webhook
#       → credits wallet → dispatches postback → mock receiver catches it
#
# Prerequisites:
#   - PostgreSQL running (127.0.0.1:5432)
#   - Redis running (127.0.0.1:6379)
#   - k6 installed (./install_k6_ubuntu.sh)
#   - Python3 installed
#
# Usage:
#   RATE=100 DURATION=30s COUNT=2000 ./benchmarks/wsl/run_postback_benchmark.sh
###############################################################################

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(cd "$SCRIPT_DIR/../.." && pwd)"
cd "$PROJECT_DIR"

# --- Config ---
APP_BASE_URL="${APP_BASE_URL:-http://127.0.0.1:9501}"
RATE="${RATE:-100}"
DURATION="${DURATION:-30s}"
COUNT="${COUNT:-2000}"
ENTERPRISES="${ENTERPRISES:-10}"
POSTBACK_URL="${POSTBACK_URL:-http://127.0.0.1:18080/postbacks}"
POSTBACK_BENCHMARK_ALLOWED_HOSTS="${POSTBACK_BENCHMARK_ALLOWED_HOSTS:-127.0.0.1,localhost}"
RECEIVER_PORT="${RECEIVER_PORT:-18080}"
MANIFEST_PATH="$PROJECT_DIR/storage/app/benchmarks/postback-manifest.json"
POSTBACK_LOG="storage/logs/postback-receiver.jsonl"
DOCKER_COMPOSE_FILE="${DOCKER_COMPOSE_FILE:-$SCRIPT_DIR/docker-compose.benchmark.yml}"
START_DOCKER_INFRA="${START_DOCKER_INFRA:-0}"
START_RECEIVER="${START_RECEIVER:-1}"
START_SERVER="${START_SERVER:-0}"
START_WORKER="${START_WORKER:-0}"

export POSTBACK_BENCHMARK_ALLOWED_HOSTS

echo "============================================================"
echo " GrandePay Hypervel — Postback Benchmark"
echo "============================================================"
echo " Server URL   : $APP_BASE_URL"
echo " Rate         : $RATE req/s"
echo " Duration     : $DURATION"
echo " Transactions : $COUNT"
echo " Enterprises  : $ENTERPRISES"
echo " Postback URL : $POSTBACK_URL"
echo " Allowed hosts: $POSTBACK_BENCHMARK_ALLOWED_HOSTS"
echo " Docker infra : $START_DOCKER_INFRA"
echo "============================================================"

if [[ "$START_SERVER" != "1" ]]; then
    echo " Reminder     : start the existing Hypervel server/worker with POSTBACK_BENCHMARK_ALLOWED_HOSTS=$POSTBACK_BENCHMARK_ALLOWED_HOSTS"
    echo "============================================================"
fi

# --- Cleanup ---
cleanup() {
    echo ""
    echo "[cleanup] Stopping background processes..."
    [[ -n "${RECEIVER_PID:-}" ]] && kill "$RECEIVER_PID" 2>/dev/null && echo "  Receiver stopped (PID $RECEIVER_PID)"
    [[ -n "${SERVER_PID:-}" ]]   && kill "$SERVER_PID" 2>/dev/null   && echo "  Server stopped (PID $SERVER_PID)"
    [[ -n "${WORKER_INBOUND_PID:-}" ]]  && kill "$WORKER_INBOUND_PID" 2>/dev/null  && echo "  Inbound worker stopped"
    [[ -n "${WORKER_POSTBACK_PID:-}" ]] && kill "$WORKER_POSTBACK_PID" 2>/dev/null && echo "  Postback worker stopped"
    if [[ "${DOCKER_INFRA_STARTED:-0}" == "1" ]]; then
        docker compose -f "$DOCKER_COMPOSE_FILE" down >/dev/null 2>&1 || true
        echo "  Docker infra stopped"
    fi

    # --- Postback stats ---
    if [[ -f "$POSTBACK_LOG" ]]; then
        TOTAL=$(wc -l < "$POSTBACK_LOG" 2>/dev/null || echo 0)
        echo ""
        echo "============================================================"
        echo " Postback Receiver Results"
        echo "============================================================"
        echo " Total postbacks received: $TOTAL"
        echo " Log file: $POSTBACK_LOG"
        echo "============================================================"
    fi
}
trap cleanup EXIT

if [[ "$START_DOCKER_INFRA" == "1" ]]; then
    echo ""
    echo "[0/5] Starting PostgreSQL and Redis via Docker..."
    docker compose -f "$DOCKER_COMPOSE_FILE" up -d postgres redis
    DOCKER_INFRA_STARTED=1
    echo "  Waiting for infrastructure healthchecks..."
    sleep 8
fi

# --- 1. Start mock postback receiver ---
if [[ "$START_RECEIVER" == "1" ]]; then
    echo ""
    echo "[1/5] Starting mock postback receiver on port $RECEIVER_PORT..."
    rm -f "$POSTBACK_LOG"
    python3 "$SCRIPT_DIR/mock_postback_receiver.py" \
        --bind 127.0.0.1 \
        --port "$RECEIVER_PORT" \
        --log-file "$POSTBACK_LOG" &
    RECEIVER_PID=$!
    sleep 1
    echo "  Receiver PID: $RECEIVER_PID"
fi

# --- 2. Optionally start Hypervel server ---
if [[ "$START_SERVER" == "1" ]]; then
    echo ""
    echo "[2/5] Starting Hypervel server..."
    php artisan serve &
    SERVER_PID=$!
    sleep 2
    echo "  Server PID: $SERVER_PID"
fi

# --- 3. Optionally start queue workers ---
if [[ "$START_WORKER" == "1" ]]; then
    echo ""
    echo "[3/5] Clearing runtime cache and starting queue workers..."
    rm -rf runtime/container 2>/dev/null || true

    # Separate workers per queue to avoid starvation
    php artisan queue:work redis --queue=payments-webhooks-high 2>&1 &
    WORKER_INBOUND_PID=$!
    php artisan queue:work redis --queue=payments-postbacks-high 2>&1 &
    WORKER_POSTBACK_PID=$!
    sleep 2
    echo "  Inbound worker PID : $WORKER_INBOUND_PID"
    echo "  Postback worker PID: $WORKER_POSTBACK_PID"
fi

# --- 4. Seed benchmark data ---
echo ""
echo "[4/5] Seeding benchmark data..."
php artisan benchmark:seed-postback \
    --count="$COUNT" \
    --enterprises="$ENTERPRISES" \
    --postback-url="$POSTBACK_URL" \
    --output="$MANIFEST_PATH"

# --- 5. Run k6 load test ---
echo ""
echo "[5/5] Running k6 load test..."
echo ""

k6 run \
    -e APP_BASE_URL="$APP_BASE_URL" \
    -e MANIFEST_PATH="$MANIFEST_PATH" \
    -e RATE="$RATE" \
    -e DURATION="$DURATION" \
    "$SCRIPT_DIR/postback_benchmark_load.js"

echo ""
echo "[done] Waiting 5s for postbacks to drain..."
sleep 5
