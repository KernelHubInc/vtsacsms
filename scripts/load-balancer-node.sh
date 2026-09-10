#!/usr/bin/env bash
set -Eeuo pipefail

env_file="${VTSA_LOAD_BALANCER_ENV_FILE:-/etc/vtsa-csms/load-balancer.env}"
state_dir="/var/lib/vtsa-csms/load-balancer"
action="${1:-}"
node="${2:-}"

fail() {
    printf 'ERROR: %s\n' "$1" >&2
    exit 1
}

[[ "$action" == "drain" || "$action" == "enable" || "$action" == "status" ]] || \
    fail "Usage: vtsa-lb-node drain|enable|status app1|app2"
[[ "$node" == "app1" || "$node" == "app2" ]] || fail "Node must be app1 or app2."
[[ -f "$env_file" ]] || fail "$env_file does not exist."

# This is a root-owned configuration file containing addresses, not application secrets.
# shellcheck disable=SC1090
source "$env_file"

: "${APP_NODE_1_HOST:?APP_NODE_1_HOST is required}"
: "${APP_NODE_2_HOST:?APP_NODE_2_HOST is required}"
: "${APP_NODE_HTTP_PORT:=80}"
: "${NGINX_UPSTREAM_FILE:=/etc/nginx/vtsa-upstreams/http.conf}"

mkdir -p "$state_dir" "$(dirname "$NGINX_UPSTREAM_FILE")"
exec 9>/var/lock/vtsa-csms-load-balancer.lock
flock -n 9 || fail "Another load-balancer change is running."

node_host="$APP_NODE_1_HOST"
other_host="$APP_NODE_2_HOST"
other_node="app2"
if [[ "$node" == "app2" ]]; then
    node_host="$APP_NODE_2_HOST"
    other_host="$APP_NODE_1_HOST"
    other_node="app1"
fi

node_state="$state_dir/$node.drained"
other_state="$state_dir/$other_node.drained"

if [[ "$action" == "status" ]]; then
    [[ -f "$node_state" ]] && printf '%s is drained.\n' "$node" || printf '%s is enabled.\n' "$node"
    exit 0
fi

if [[ "$action" == "drain" ]]; then
    [[ ! -f "$other_state" ]] || fail "Cannot drain $node while $other_node is drained."
    curl --fail --silent --show-error --max-time 5 \
        "http://$other_host:$APP_NODE_HTTP_PORT/health/ready" >/dev/null || \
        fail "$other_node is not ready; refusing to drain $node."
    desired_node_state="drained"
else
    curl --fail --silent --show-error --max-time 5 \
        "http://$node_host:$APP_NODE_HTTP_PORT/health/ready" >/dev/null || \
        fail "$node is not ready; it remains drained."
    desired_node_state="enabled"
fi

app1_suffix=""
app2_suffix=""
[[ -f "$state_dir/app1.drained" ]] && app1_suffix=" down"
[[ -f "$state_dir/app2.drained" ]] && app2_suffix=" down"
if [[ "$node" == "app1" ]]; then
    [[ "$desired_node_state" == "drained" ]] && app1_suffix=" down" || app1_suffix=""
else
    [[ "$desired_node_state" == "drained" ]] && app2_suffix=" down" || app2_suffix=""
fi

temporary_file="$(mktemp "$(dirname "$NGINX_UPSTREAM_FILE")/.vtsa-upstream.XXXXXX")"
backup_file="$NGINX_UPSTREAM_FILE.previous"
trap 'rm -f "$temporary_file"' EXIT
printf 'server %s:%s max_fails=1 fail_timeout=5s%s;\n' \
    "$APP_NODE_1_HOST" "$APP_NODE_HTTP_PORT" "$app1_suffix" > "$temporary_file"
printf 'server %s:%s max_fails=1 fail_timeout=5s%s;\n' \
    "$APP_NODE_2_HOST" "$APP_NODE_HTTP_PORT" "$app2_suffix" >> "$temporary_file"

upstream_existed=false
if [[ -f "$NGINX_UPSTREAM_FILE" ]]; then
    cp "$NGINX_UPSTREAM_FILE" "$backup_file"
    upstream_existed=true
fi
install -m 0644 "$temporary_file" "$NGINX_UPSTREAM_FILE"
if ! nginx -t; then
    if [[ "$upstream_existed" == true ]]; then
        cp "$backup_file" "$NGINX_UPSTREAM_FILE"
    else
        rm -f "$NGINX_UPSTREAM_FILE"
    fi
    fail "Nginx rejected the generated upstream configuration."
fi
systemctl reload nginx

if [[ "$desired_node_state" == "drained" ]]; then
    touch "$node_state"
else
    rm -f "$node_state"
fi

printf '%s is now %s.\n' "$node" "$desired_node_state"
