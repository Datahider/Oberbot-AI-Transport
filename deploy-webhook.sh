#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ENV_FILE="$SCRIPT_DIR/deploy.env"

if [ -f "$ENV_FILE" ]; then
    # shellcheck disable=SC1090
    . "$ENV_FILE"
fi

DEPLOY_HOST="${TELEGRAM_WEBHOOK_DEPLOY_HOST:-}"
DEPLOY_PORT="${TELEGRAM_WEBHOOK_DEPLOY_PORT:-22}"
DEPLOY_USER="${TELEGRAM_WEBHOOK_DEPLOY_USER:-root}"
REMOTE_APP_DIR="${TELEGRAM_WEBHOOK_REMOTE_APP_DIR:-}"
REMOTE_SITE_USER="${TELEGRAM_WEBHOOK_REMOTE_SITE_USER:-}"
REMOTE_PHP_BIN="${TELEGRAM_WEBHOOK_REMOTE_PHP_BIN:-php8.5}"
REMOTE_COMPOSER_BIN="${TELEGRAM_WEBHOOK_REMOTE_COMPOSER_BIN:-/usr/local/bin/composer}"

for name in DEPLOY_HOST REMOTE_APP_DIR REMOTE_SITE_USER REMOTE_PHP_BIN REMOTE_COMPOSER_BIN; do
    if [ -z "${!name:-}" ]; then
        echo "Required deploy variable is missing: $name"
        exit 1
    fi
done

SSH_OPTS="-p $DEPLOY_PORT -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null"
REMOTE="$DEPLOY_USER@$DEPLOY_HOST"

ssh $SSH_OPTS "$REMOTE" "mkdir -p '$REMOTE_APP_DIR' '$REMOTE_APP_DIR/etc'"

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
    chown -R '$REMOTE_SITE_USER:$REMOTE_SITE_USER' '$REMOTE_APP_DIR'
    cd '$REMOTE_APP_DIR'
    runuser -u '$REMOTE_SITE_USER' -- '$REMOTE_PHP_BIN' '$REMOTE_COMPOSER_BIN' install --no-dev --prefer-dist --no-interaction
"

echo "Webhook deploy completed."
