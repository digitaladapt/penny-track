#!/usr/bin/env bash

set -euo pipefail

penny_dir=$(readlink -f "$0" | xargs dirname)
SCREEN_NAME="penny-track"
WORKDIR="$penny_dir/public"
COMMAND="php -S 0.0.0.0:8000"

if screen -list | grep -q "[.]${SCREEN_NAME}[[:space:]]"; then
    echo "✅ Screen '${SCREEN_NAME}' is already running."
else
    echo "🚀 Creating screen '${SCREEN_NAME}'..."

    screen -dmS "${SCREEN_NAME}" bash -c "
        cd '${WORKDIR}'
        exec ${COMMAND}
    "

    echo "✅ Screen '${SCREEN_NAME}' created."

    sleep 1

    if screen -list | grep -q "[.]${SCREEN_NAME}[[:space:]]"; then
        echo "✅ Started '${SCREEN_NAME}'."
    else
        echo "❌ Failed to start '${SCREEN_NAME}'."
        exit 1
    fi
fi

