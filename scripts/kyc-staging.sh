#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

repo_dir="${VTSA_REPO_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
command="${1:-up}"
fail() { printf 'ERROR: %s\n' "$1" >&2; exit 1; }
[[ $# -le 1 ]] || fail 'Usage: kyc-staging.sh prepare|models|check|up'
case "$command" in prepare|models|check|up) ;; *) fail 'Usage: kyc-staging.sh prepare|models|check|up' ;; esac
[[ "$EUID" -eq 0 ]] || fail 'Run with sudo on VPS3.'
command -v python3 >/dev/null || fail 'Python 3 is required.'
env_file="${VTSA_KYC_ENV_FILE:-/etc/vtsa-csms/kyc-staging.env}"

case "$command" in
    prepare)
        python3 "$repo_dir/scripts/kyc-staging.py" prepare "$env_file"
        ;;
    models)
        command -v docker >/dev/null || fail 'Docker is required.'
        model_dir="$(python3 "$repo_dir/scripts/kyc-staging.py" model-directory "$env_file")"
        # Downloads run in Python 3.12 even when the VPS has an older host Python.
        docker run --rm --network bridge --security-opt no-new-privileges:true --cap-drop ALL \
            --read-only --tmpfs /tmp \
            --mount "type=bind,src=$repo_dir/services/kyc-service/scripts,dst=/scripts,readonly" \
            --mount "type=bind,src=$model_dir,dst=/models" \
            python:3.12-slim sh -ec 'python /scripts/install_face_models.py /models; python /scripts/install_liveness_model.py /models; chmod 755 /models; chmod 644 /models/*.onnx /models/*.LICENSE'
        ;;
    check) exec bash "$repo_dir/scripts/deploy-kyc.sh" staging source --check ;;
    up) exec bash "$repo_dir/scripts/deploy-kyc.sh" staging source ;;
esac
