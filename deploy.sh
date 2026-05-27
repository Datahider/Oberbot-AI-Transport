#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ENV_FILE="$SCRIPT_DIR/deploy.env"

if [ -f "$ENV_FILE" ]; then
    # shellcheck disable=SC1090
    . "$ENV_FILE"
fi

DEPLOY_HOST="${TELEGRAM_TRANSPORT_DEPLOY_HOST:-}"
DEPLOY_PORT="${TELEGRAM_TRANSPORT_DEPLOY_PORT:-22}"
DEPLOY_USER="${TELEGRAM_TRANSPORT_DEPLOY_USER:-}"
REMOTE_APP_DIR="${TELEGRAM_TRANSPORT_REMOTE_APP_DIR:-}"
REMOTE_SERVICE="${TELEGRAM_TRANSPORT_REMOTE_SERVICE:-codex-multitenant-telegram}"
REMOTE_PHP_BIN="${TELEGRAM_TRANSPORT_REMOTE_PHP_BIN:-php}"
REMOTE_COMPOSER_BIN="${TELEGRAM_TRANSPORT_REMOTE_COMPOSER_BIN:-composer}"

PROJECT_COMMIT="$(git -C "$SCRIPT_DIR" rev-parse HEAD 2>/dev/null || echo no-git-commit)"
PROJECT_VERSION="$(git -C "$SCRIPT_DIR" describe --tags --always 2>/dev/null || echo no-git-version)"

function require_var() {
    local name="$1"
    local value="${!name:-}"
    if [ -z "$value" ]; then
        echo "Required deploy variable is missing: $name"
        exit 1
    fi
}

if ! command -v rsync >/dev/null 2>&1; then
    echo "rsync is required"
    exit 1
fi

if ! command -v ssh >/dev/null 2>&1; then
    echo "ssh is required"
    exit 1
fi

require_var DEPLOY_HOST
require_var DEPLOY_USER
require_var REMOTE_APP_DIR
require_var REMOTE_SERVICE
require_var REMOTE_PHP_BIN
require_var REMOTE_COMPOSER_BIN

SSH_OPTS="-p $DEPLOY_PORT -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null"
REMOTE="$DEPLOY_USER@$DEPLOY_HOST"

echo "Telegram Transport commit: $PROJECT_COMMIT"
echo "Telegram Transport version: $PROJECT_VERSION"
echo "Deploying Transport-Telegram"
echo "  from: $SCRIPT_DIR/"
echo "  to:   $REMOTE:$REMOTE_APP_DIR"

ssh $SSH_OPTS "$REMOTE" "mkdir -p '$REMOTE_APP_DIR' '$REMOTE_APP_DIR/etc' '$REMOTE_APP_DIR/var/log'"

rsync -avz --delete \
    --exclude='.git/' \
    --exclude='vendor/' \
    --exclude='var/' \
    --exclude='tests/' \
    --exclude='deploy.env' \
    --exclude='etc/config.php' \
    --exclude='.idea/' \
    --exclude='.vscode/' \
    -e "ssh $SSH_OPTS" \
    "$SCRIPT_DIR/" \
    "$REMOTE:$REMOTE_APP_DIR"

ssh $SSH_OPTS "$REMOTE" "
    set -euo pipefail
    cd '$REMOTE_APP_DIR' \
    && '$REMOTE_COMPOSER_BIN' install --no-dev --prefer-dist --no-interaction \
    && sudo systemctl restart '$REMOTE_SERVICE'
"

echo "Deploy completed."
