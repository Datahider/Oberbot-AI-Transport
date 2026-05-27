#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
SERVICE_NAME="codex-multitenant-telegram.service"
TARGET_DIR="${HOME}/.config/systemd/user"

mkdir -p "$TARGET_DIR"
install -m 0644 "$PROJECT_ROOT/systemd/$SERVICE_NAME" "$TARGET_DIR/$SERVICE_NAME"

XDG_RUNTIME_DIR="${XDG_RUNTIME_DIR:-/run/user/$(id -u)}"
export XDG_RUNTIME_DIR

systemctl --user daemon-reload
systemctl --user enable "$SERVICE_NAME"
systemctl --user restart "$SERVICE_NAME"

echo "Installed and restarted $SERVICE_NAME"
