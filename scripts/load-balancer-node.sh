#!/usr/bin/env bash
set -Eeuo pipefail

environment="${1:-}"
action="${2:-}"
node="${3:-}"

fail() {
    printf 'ERROR: %s\n' "$1" >&2
    exit 1
}

[[ "$environment" == "staging" || "$environment" == "production" ]] || \
    fail "Usage: vtsa-lb-node staging|production drain|enable|status app1|app2"
[[ "$action" == "drain" || "$action" == "enable" || "$action" == "status" ]] || \
    fail "Usage: vtsa-lb-node staging|production drain|enable|status app1|app2"
[[ "$node" == "app1" || "$node" == "app2" ]] || fail "Node must be app1 or app2."
env_file="${VTSA_LOAD_BALANCER_ENV_FILE:-/etc/vtsa-csms/load-balancer.$environment.env}"
state_dir="/var/lib/vtsa-csms/load-balancer/$environment"
[[ -f "$env_file" ]] || fail "$env_file does not exist."

# This is a root-owned configuration file containing addresses, not application secrets.
# shellcheck disable=SC1090
source "$env_file"

: "${APP_NODE_1_HOST:?APP_NODE_1_HOST is required}"
: "${APP_NODE_2_HOST:?APP_NODE_2_HOST is required}"
: "${APP_NODE_HTTP_PORT:=80}"
: "${APP_NODE_OCPP_PORT:=9000}"
: "${NGINX_HTTP_UPSTREAM_FILE:=/etc/nginx/vtsa-upstreams/$environment-http.conf}"
: "${NGINX_OCPP_UPSTREAM_FILE:=/etc/nginx/vtsa-upstreams/$environment-ocpp.conf}"

mkdir -p "$state_dir" "$(dirname "$NGINX_HTTP_UPSTREAM_FILE")" "$(dirname "$NGINX_OCPP_UPSTREAM_FILE")"
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
    [[ -f "$node_state" ]] && printf '%s %s is drained.\n' "$environment" "$node" || \
        printf '%s %s is enabled.\n' "$environment" "$node"
    exit 0
fi

if [[ "$action" == "drain" ]]; then
    [[ ! -f "$other_state" ]] || fail "Cannot drain $environment $node while $other_node is drained."
    curl --fail --silent --show-error --max-time 5 \
        "http://$other_host:$APP_NODE_HTTP_PORT/health/ready" >/dev/null || \
        fail "$environment $other_node is not ready; refusing to drain $node."
    curl --fail --silent --show-error --max-time 5 \
        "http://$other_host:$APP_NODE_OCPP_PORT/health/ready" >/dev/null || \
        fail "$environment $other_node OCPP gateway is not ready; refusing to drain $node."
    desired_node_state="drained"
else
    curl --fail --silent --show-error --max-time 5 \
        "http://$node_host:$APP_NODE_HTTP_PORT/health/ready" >/dev/null || \
        fail "$environment $node is not ready; it remains drained."
    curl --fail --silent --show-error --max-time 5 \
        "http://$node_host:$APP_NODE_OCPP_PORT/health/ready" >/dev/null || \
        fail "$environment $node OCPP gateway is not ready; it remains drained."
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

http_temporary_file="$(mktemp "$(dirname "$NGINX_HTTP_UPSTREAM_FILE")/.vtsa-http-upstream.XXXXXX")"
ocpp_temporary_file="$(mktemp "$(dirname "$NGINX_OCPP_UPSTREAM_FILE")/.vtsa-ocpp-upstream.XXXXXX")"
http_backup_file="$NGINX_HTTP_UPSTREAM_FILE.previous"
ocpp_backup_file="$NGINX_OCPP_UPSTREAM_FILE.previous"
trap 'rm -f "$http_temporary_file" "$ocpp_temporary_file"' EXIT
printf 'server %s:%s max_fails=1 fail_timeout=5s%s;\n' \
    "$APP_NODE_1_HOST" "$APP_NODE_HTTP_PORT" "$app1_suffix" > "$http_temporary_file"
printf 'server %s:%s max_fails=1 fail_timeout=5s%s;\n' \
    "$APP_NODE_2_HOST" "$APP_NODE_HTTP_PORT" "$app2_suffix" >> "$http_temporary_file"
printf 'server %s:%s max_fails=1 fail_timeout=5s%s;\n' \
    "$APP_NODE_1_HOST" "$APP_NODE_OCPP_PORT" "$app1_suffix" > "$ocpp_temporary_file"
printf 'server %s:%s max_fails=1 fail_timeout=5s%s;\n' \
    "$APP_NODE_2_HOST" "$APP_NODE_OCPP_PORT" "$app2_suffix" >> "$ocpp_temporary_file"

http_upstream_existed=false
ocpp_upstream_existed=false
if [[ -f "$NGINX_HTTP_UPSTREAM_FILE" ]]; then
    cp "$NGINX_HTTP_UPSTREAM_FILE" "$http_backup_file"
    http_upstream_existed=true
fi
if [[ -f "$NGINX_OCPP_UPSTREAM_FILE" ]]; then
    cp "$NGINX_OCPP_UPSTREAM_FILE" "$ocpp_backup_file"
    ocpp_upstream_existed=true
fi
install -m 0644 "$http_temporary_file" "$NGINX_HTTP_UPSTREAM_FILE"
install -m 0644 "$ocpp_temporary_file" "$NGINX_OCPP_UPSTREAM_FILE"

restore_upstreams() {
    if [[ "$http_upstream_existed" == true ]]; then
        cp "$http_backup_file" "$NGINX_HTTP_UPSTREAM_FILE"
    else
        rm -f "$NGINX_HTTP_UPSTREAM_FILE"
    fi
    if [[ "$ocpp_upstream_existed" == true ]]; then
        cp "$ocpp_backup_file" "$NGINX_OCPP_UPSTREAM_FILE"
    else
        rm -f "$NGINX_OCPP_UPSTREAM_FILE"
    fi
}

if ! nginx -t; then
    restore_upstreams
    fail "Nginx rejected the generated upstream configuration."
fi
if ! systemctl reload nginx; then
    restore_upstreams
    nginx -t && systemctl reload nginx || true
    fail "Nginx reload failed; the previous upstream configuration was restored."
fi

if [[ "$desired_node_state" == "drained" ]]; then
    touch "$node_state"
else
    rm -f "$node_state"
fi

printf '%s %s is now %s.\n' "$environment" "$node" "$desired_node_state"
