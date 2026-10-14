#!/bin/sh
set -eu

exec php bin/console messenger:consume async \
    --time-limit="${MESSENGER_TIME_LIMIT:-3600}" \
    --memory-limit="${MESSENGER_MEMORY_LIMIT:-128M}" \
    --limit="${MESSENGER_MESSAGE_LIMIT:-500}"
