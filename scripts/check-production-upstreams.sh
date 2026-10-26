#!/usr/bin/env bash
set -u

host=evcspowersolutions.com
failed=0

for node in 'App 01:187.77.130.156' 'App 02:187.53.134.122'; do
    name="${node%%:*}"
    address="${node#*:}"

    if curl --fail --silent --show-error --connect-timeout 2 --max-time 5 \
        --resolve "$host:80:$address" "http://$host/health/ready" >/dev/null; then
        printf '%s ready\n' "$name"
    else
        printf '%s unavailable\n' "$name" >&2
        failed=1
    fi
done

exit "$failed"
