#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

repo_dir="${VTSA_REPO_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
environment="${1:-}"
release="${2:-}"
option="${3:-}"
fail() { printf 'ERROR: %s\n' "$1" >&2; exit 1; }
[[ "$environment" == production || "$environment" == staging ]] || fail 'Usage: deploy-kyc.sh production|staging sha-<40-character-commit> [--check]'
[[ "$release" =~ ^sha-[0-9a-f]{40}$ || ( "$environment" == staging && "$release" == source ) ]] || fail 'Use an immutable sha-<40-character-commit> release; source builds are staging-only.'
[[ -z "$option" || "$option" == --check ]] || fail 'The only optional argument is --check.'
[[ $# -le 3 ]] || fail 'Unexpected arguments.'
env_file="${VTSA_KYC_ENV_FILE:-/etc/vtsa-csms/kyc-$environment.env}"
app_env="${VTSA_APP_ENV_FILE:-/etc/vtsa-csms/$environment.env}"
for executable in docker python3 flock ip; do command -v "$executable" >/dev/null || fail "$executable is required."; done
docker compose version >/dev/null || fail 'Docker Compose v2 is required.'
exec 9>/var/lock/vtsa-csms-app-deploy.lock
flock -n 9 || fail 'Another application or KYC deployment is running on this node.'
if [[ "$release" != source ]]; then
    command -v git >/dev/null || fail 'git is required for release deployment.'
    [[ "$(git -C "$repo_dir" rev-parse HEAD)" == "${release#sha-}" ]] || fail 'Check out the requested release before running its deployment command.'
    [[ -z "$(git -C "$repo_dir" status --porcelain -- scripts/deploy-kyc.sh scripts/validate-kyc-deployment.py infra/cluster/compose.kyc.yaml infra/cluster/kyc.Caddyfile)" ]] || fail 'Deployment scripts and configuration must match the committed release.'
else
    python3 "$repo_dir/scripts/kyc-staging.py" guard "$env_file" "$app_env"
fi

python3 "$repo_dir/scripts/validate-kyc-deployment.py" "$environment" "$env_file" "$app_env"
compose=(docker compose --project-name "vtsa-kyc-$environment" --env-file "$env_file" -f "$repo_dir/infra/cluster/compose.kyc.yaml")
# Shell variables must not silently override the reviewed protected configuration.
for key in "${!KYC_@}" "${!AWS_@}" "${!COMPOSE_@}"; do
    if [[ -n "$key" ]]; then unset "$key"; fi
done
if [[ "$release" == source ]]; then
    context_file="$(mktemp)"
    trap 'rm -f -- "$context_file"' EXIT
    release="staging-source-$(python3 "$repo_dir/scripts/kyc-staging.py" context "$context_file")"
    docker build --target runtime --tag "ghcr.io/kernelhubinc/vtsacsms/kyc:$release" - < "$context_file"
    export KYC_IMAGE_TAG="$release" KYC_ENV_FILE="$env_file"
    "${compose[@]}" config --quiet
    "${compose[@]}" pull --quiet kyc-redis kyc-ingress kyc-backup
else
    export KYC_IMAGE_TAG="$release" KYC_ENV_FILE="$env_file"
    "${compose[@]}" config --quiet
    "${compose[@]}" pull --quiet
fi
export KYC_IMAGE_TAG="$release" KYC_ENV_FILE="$env_file"
"${compose[@]}" run --rm --no-deps -T kyc-api python -m app.deployment_check
if [[ "$option" == --check ]]; then
    printf 'KYC %s configuration, TLS, database and private storage preflight passed. No services replaced.\n' "$environment"
    exit 0
fi

backup_dir="/var/backups/vtsa-csms/kyc-$environment"
mkdir -p "$backup_dir"
backup="$backup_dir/$(date -u +%Y%m%dT%H%M%SZ)-$$.dump"
if ! "${compose[@]}" run --rm --no-deps -T kyc-backup > "$backup.part" 2> "$backup.error"; then
    fail "KYC database backup failed. Protected diagnostics: $backup.error. Migration was not attempted."
fi
[[ -s "$backup.part" ]] || fail 'KYC backup is empty; migration was not attempted.'
mv "$backup.part" "$backup"
"${compose[@]}" run --rm --no-deps -T kyc-migrate
"${compose[@]}" up --detach --wait --wait-timeout 240 kyc-api kyc-worker kyc-beat kyc-ingress
"${compose[@]}" ps
printf 'KYC %s deployed. Core Laravel migrations/permissions and app configuration must be deployed separately.\n' "$environment"
