#!/usr/bin/env bash
set -Eeuo pipefail

environment="${1:-}"
release_tag="${2:-}"
mode="${3:-}"

fail() { printf 'ERROR: %s\n' "$1" >&2; exit 1; }

[[ "$environment" == staging || "$environment" == production ]] || \
    fail 'Usage: release-cluster.sh staging|production sha-<40-character-commit> [--check]'
[[ "$release_tag" == latest || "$release_tag" =~ ^sha-[0-9a-f]{40}$ ]] || \
    fail 'Release must be latest or an immutable sha-<40-character-commit> image tag.'
[[ -z "$mode" || "$mode" == --check ]] || fail 'Only --check is supported.'

app1="${VTSA_APP1_HOST:-187.77.130.156}"
app2="${VTSA_APP2_HOST:-187.53.134.122}"
key="${VTSA_CLUSTER_DEPLOY_KEY:-/root/.ssh/vtsa_cluster_deploy}"
known_hosts="${VTSA_CLUSTER_KNOWN_HOSTS:-/root/.ssh/known_hosts}"
production_host="${VTSA_PRODUCTION_HOST:-evcspowersolutions.com}"
staging_url="${VTSA_STAGING_URL:-https://staging.evcspowersolutions.com}"
production_url="${VTSA_PRODUCTION_URL:-https://evcspowersolutions.com}"

[[ -r "$key" ]] || fail "Deployment key is missing: $key"
[[ -s "$known_hosts" ]] || fail "Verified SSH known_hosts is missing: $known_hosts"
ssh-keygen -y -f "$key" >/dev/null || fail 'Deployment key is invalid.'
ssh_options=(-i "$key" -o BatchMode=yes -o IdentitiesOnly=yes
    -o StrictHostKeyChecking=yes -o UserKnownHostsFile="$known_hosts"
    -o ConnectTimeout=8)

# The caller builds the remote command locally; SSH executes it on the selected node.
# shellcheck disable=SC2029
remote() { ssh "${ssh_options[@]}" "root@$1" "${@:2}"; }
read_branch() {
    local result
    result="$(remote "$1" "git -C /opt/vtsa-csms -c core.sshCommand='ssh -i /root/.ssh/vtsa_repository_read -o IdentitiesOnly=yes -o BatchMode=yes' ls-remote origin refs/heads/$2")"
    [[ "$result" =~ ^([0-9a-f]{40})[[:space:]]+refs/heads/$2$ ]] || \
        fail "Cannot resolve the $2 branch from $1."
    printf 'sha-%s\n' "${BASH_REMATCH[1]}"
}
ready_node() {
    curl --fail --silent --show-error --connect-timeout 3 --max-time 8 \
        --resolve "$production_host:80:$1" \
        "http://$production_host/health/ready" >/dev/null
}
ready_public() {
    curl --fail --silent --show-error --connect-timeout 5 --max-time 15 \
        "$1/health/ready" >/dev/null
}

# A shared lock on the load balancer prevents staging and production releases overlapping.
lock_file="${VTSA_RELEASE_LOCK:-/var/lock/vtsa-csms-cluster-release.lock}"
exec 9>"$lock_file"
flock -n 9 || fail 'Another cluster release is running.'

if [[ "$release_tag" == latest ]]; then
    if [[ "$environment" == staging ]]; then
        release_tag="$(read_branch "$app2" test_env)"
    else
        release_tag="$(read_branch "$app1" main)"
    fi
    printf 'Resolved release: %s\n' "$release_tag"
fi

if [[ "$environment" == staging ]]; then
    remote "$app2" 'test -x /usr/local/sbin/vtsa-deploy-app'
    ready_public "$staging_url" || fail 'Staging is not ready before deployment.'
    if [[ "$mode" == --check ]]; then
        printf 'Staging preflight passed for %s on App02.\n' "$release_tag"
        exit 0
    fi
    remote "$app2" "/usr/local/sbin/vtsa-deploy-app staging '$release_tag' --migrate"
    ready_public "$staging_url" || fail 'Staging did not become ready after deployment.'
    printf 'Staging is ready on %s.\n' "$release_tag"
    exit 0
fi

remote "$app1" 'test -x /usr/local/sbin/vtsa-deploy-app'
remote "$app2" 'test -x /usr/local/sbin/vtsa-deploy-app'
staging_tag="$(remote "$app2" 'cat /var/lib/vtsa-csms/staging/current-app-image-tag')"
[[ "$staging_tag" =~ ^sha-[0-9a-f]{40}$ ]] || fail 'Staging has no valid release marker.'
remote "$app1" "git -C /opt/vtsa-csms -c core.sshCommand='ssh -i /root/.ssh/vtsa_repository_read -o IdentitiesOnly=yes -o BatchMode=yes' fetch --quiet origin main test_env"
staged_tree="$(remote "$app1" "git -C /opt/vtsa-csms rev-parse '${staging_tag#sha-}^{tree}'")"
release_tree="$(remote "$app1" "git -C /opt/vtsa-csms rev-parse '${release_tag#sha-}^{tree}'")"
[[ "$staged_tree" == "$release_tree" ]] || \
    fail "Production source differs from staged $staging_tag; deploy the main tree to staging before promotion."
ready_public "$staging_url" || fail 'Staging is not ready.'
ready_node "$app1" || fail 'App01 production is not ready.'
ready_node "$app2" || fail 'App02 production is not ready.'
ready_public "$production_url" || fail 'Public production is not ready.'

if [[ "$mode" == --check ]]; then
    printf 'Production preflight passed for %s on both app nodes.\n' "$release_tag"
    exit 0
fi

printf 'Deploying App01 with the one production migration and job runner...\n'
remote "$app1" "/usr/local/sbin/vtsa-deploy-app production '$release_tag' --migrate"
ready_node "$app1" || fail 'App01 is not ready; App02 was not changed.'
ready_public "$production_url" || fail 'Public production failed after App01 deployment.'

printf 'Deploying App02 web-only node...\n'
remote "$app2" "/usr/local/sbin/vtsa-deploy-app production '$release_tag'"
ready_node "$app2" || fail 'App02 is not ready; inspect its deployment rollback.'
ready_public "$production_url" || fail 'Public production failed after App02 deployment.'

if remote "$app2" 'docker ps --format "{{.Names}}"' | grep -Eq '^vtsa-csms-production-(worker|scheduler)-'; then
    fail 'App02 unexpectedly runs a production worker or scheduler.'
fi
printf 'Production is ready on App01 and App02 at %s.\n' "$release_tag"
