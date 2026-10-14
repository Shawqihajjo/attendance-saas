#!/bin/sh
# Invoked by GitHub Actions on the runner. SSHes to a configured Compose host and
# runs release.sh with an immutable IMAGE_TAG. Never prints secret values.
set -eu

target="${1:?usage: ci-ssh-deploy.sh <staging|production>}"

: "${DEPLOY_HOST:?DEPLOY_HOST is required}"
: "${DEPLOY_USER:?DEPLOY_USER is required}"
: "${DEPLOY_PATH:?DEPLOY_PATH is required}"
: "${IMAGE_TAG:?IMAGE_TAG is required}"
: "${SSH_KEY:?SSH_KEY is required}"

DEPLOY_PORT="${DEPLOY_PORT:-22}"
if [ -z "$DEPLOY_PORT" ]; then
    DEPLOY_PORT=22
fi
ENV_FILE="${ENV_FILE:-.env.prod}"
GHCR_OWNER="$(printf '%s' "${GHCR_OWNER}" | tr '[:upper:]' '[:lower:]')"
HEALTHCHECK_URL="${HEALTHCHECK_URL:-http://127.0.0.1}"

case "${RUN_BACKUP:-true}" in
    true|1|yes) BACKUP_FLAG=1 ;;
    *) BACKUP_FLAG=0 ;;
esac

case "$IMAGE_TAG" in
    latest|main)
        echo "Refusing mutable image tag: ${IMAGE_TAG}" >&2
        exit 1
        ;;
    sha-*|v*)
        ;;
    *)
        echo "IMAGE_TAG must be sha-* or v* (got: ${IMAGE_TAG})" >&2
        exit 1
        ;;
esac

if [ -z "${GHCR_OWNER}" ]; then
    echo "GHCR_OWNER is required." >&2
    exit 1
fi

key_file="$(mktemp)"
remote_script="$(mktemp)"
trap 'rm -f "$key_file" "$remote_script"' EXIT
printf '%s\n' "$SSH_KEY" > "$key_file"
chmod 600 "$key_file"

# Build the remote script without embedding raw secrets in the Actions log.
# Values are written to a temp file and piped to ssh; the job only logs metadata.
{
    echo 'set -eu'
    printf 'export IMAGE_TAG=%s\n' "$(printf '%q' "$IMAGE_TAG")"
    printf 'export GHCR_OWNER=%s\n' "$(printf '%q' "$GHCR_OWNER")"
    printf 'export ENV_FILE=%s\n' "$(printf '%q' "$ENV_FILE")"
    printf 'export DEPLOY_MODE=pull\n'
    printf 'export BACKUP_BEFORE_MIGRATE=%s\n' "$BACKUP_FLAG"
    printf 'export HEALTHCHECK_URL=%s\n' "$(printf '%q' "$HEALTHCHECK_URL")"
    printf 'export DEPLOY_REVISION=%s\n' "$(printf '%q' "$IMAGE_TAG")"
    printf 'export GHCR_TOKEN=%s\n' "$(printf '%q' "${GHCR_TOKEN:-}")"
    printf 'cd %s\n' "$(printf '%q' "$DEPLOY_PATH")"
    cat <<'EOS'
if [ -n "${GHCR_TOKEN}" ]; then
  echo "${GHCR_TOKEN}" | docker login ghcr.io -u "${GHCR_OWNER}" --password-stdin >/dev/null
fi
./docker/deploy/release.sh
EOS
} > "$remote_script"

echo "Deploying target=${target} host=${DEPLOY_USER}@${DEPLOY_HOST}:${DEPLOY_PORT} path=${DEPLOY_PATH} tag=${IMAGE_TAG} backup=${BACKUP_FLAG}"

ssh -i "$key_file" \
    -o BatchMode=yes \
    -o StrictHostKeyChecking=accept-new \
    -p "$DEPLOY_PORT" \
    "${DEPLOY_USER}@${DEPLOY_HOST}" \
    'sh -s' < "$remote_script"

echo "Remote release finished for ${target} @ ${IMAGE_TAG}"
