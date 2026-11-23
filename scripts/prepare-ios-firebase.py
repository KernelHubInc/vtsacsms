#!/usr/bin/env python3
"""Validate a private Firebase Apple client file and prepare public build defines."""

import argparse
import base64
import json
import os
from pathlib import Path
import plistlib
import sys

ROOT = Path(__file__).resolve().parents[1]
BUNDLE_ID = 'com.vtsa.vtsaMobile'
ENVIRONMENTS = {
    'production': ('evcs-power-solutions', 'https://evcspowersolutions.com', '01M5TYA79VGM9C3AGJB4PMNZ2Q'),
    'staging': ('evcs-power-solutions-staging', 'https://staging.evcspowersolutions.com', '01J0000000VTSADEMA00000000'),
}


def validate(data, environment):
    try:
        config = plistlib.loads(data)
    except Exception as error:
        raise ValueError('Invalid Firebase Apple client plist.') from error
    if not isinstance(config, dict) or any(key in config for key in ('private_key', 'private_key_id')):
        raise ValueError('Use the Apple client plist, never a service-account key.')
    if config.get('PROJECT_ID') != ENVIRONMENTS[environment][0] or config.get('BUNDLE_ID') != BUNDLE_ID:
        raise ValueError('Firebase project or Apple bundle ID does not match the selected environment.')
    if not all(isinstance(config.get(key), str) and config[key] for key in ('GOOGLE_APP_ID', 'GCM_SENDER_ID', 'API_KEY')):
        raise ValueError('Firebase Apple client configuration is incomplete.')
    return config


def prepare(data, environment, root=ROOT):
    config = validate(data, environment)
    defines = build_defines(environment)
    ios_client = defines.get('GOOGLE_SIGN_IN_IOS_CLIENT_ID')
    if ios_client and config.get('CLIENT_ID') not in (None, '', ios_client):
        raise ValueError('Firebase Apple OAuth client does not match the approved Google sign-in client.')
    mobile = root / 'apps/mobile'
    destination = mobile / 'ios/Runner/GoogleService-Info.plist'
    destination.parent.mkdir(parents=True, exist_ok=True)
    destination.write_bytes(data)
    destination.chmod(0o600)
    if ios_client:
        info_path = mobile / 'ios/Runner/Info.plist'
        info = plistlib.loads(info_path.read_bytes()) if info_path.exists() else {}
        info['GIDClientID'] = ios_client
        info['GIDServerClientID'] = defines['GOOGLE_SIGN_IN_SERVER_CLIENT_ID']
        schemes = [item for item in info.get('CFBundleURLTypes', [])
                   if item.get('CFBundleURLName') != 'google-sign-in']
        schemes.append({'CFBundleURLName': 'google-sign-in', 'CFBundleURLSchemes': [reversed_client_id(ios_client)]})
        info['CFBundleURLTypes'] = schemes
        info_path.write_bytes(plistlib.dumps(info, sort_keys=False))
    output = mobile / '.codemagic'
    output.mkdir(parents=True, exist_ok=True)
    path = output / 'ios-defines.json'
    path.write_text(json.dumps(defines, indent=2) + '\n', encoding='utf-8')
    return path


def reversed_client_id(client_id):
    return '.'.join(reversed(client_id.split('.')))


def build_defines(environment):
    source = 'dart_defines.production.json' if environment == 'production' else 'dart_defines.staging-demo.json'
    defines = json.loads((ROOT / 'apps/mobile' / source).read_text())
    _, api, tenant = ENVIRONMENTS[environment]
    if (defines.get('APP_ENVIRONMENT'), defines.get('API_BASE_URL'), defines.get('DEFAULT_TENANT_ID')) != (environment, api, tenant):
        raise ValueError('Mobile build definitions do not match the selected environment.')
    # A staging iOS build needs its own approved Apple OAuth client before activation.
    if not defines.get('GOOGLE_SIGN_IN_IOS_CLIENT_ID'):
        defines['GOOGLE_SIGN_IN_ENABLED'] = 'false'
    return defines


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--environment', required=True, choices=ENVIRONMENTS)
    parser.add_argument('--source', type=Path, help='Local Apple Firebase client plist; otherwise use FIREBASE_IOS_PLIST_BASE64.')
    args = parser.parse_args(argv)
    try:
        data = args.source.read_bytes() if args.source else base64.b64decode(os.environ.get('FIREBASE_IOS_PLIST_BASE64', ''), validate=True)
        path = prepare(data, args.environment)
    except (ValueError, OSError) as error:
        # Never print configuration contents or decoded credentials.
        print(str(error) if isinstance(error, ValueError) else 'Cannot read or write the Firebase client configuration.', file=sys.stderr)
        return 1
    print(f'iOS Firebase configuration verified for {args.environment}; defines: {path.relative_to(ROOT)}')
    return 0


if __name__ == '__main__':
    sys.exit(main())
