#!/usr/bin/env python3
"""Fail an App Store build if Firebase, APNs signing or bundle identity is wrong."""

import argparse
import importlib.util
from pathlib import Path
import plistlib
import subprocess
import sys

SPEC = importlib.util.spec_from_file_location('ios_firebase', Path(__file__).with_name('prepare-ios-firebase.py'))
firebase = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(firebase)


def verify(app, entitlements, environment):
    firebase.validate((app / 'GoogleService-Info.plist').read_bytes(), environment)
    info = plistlib.loads((app / 'Info.plist').read_bytes())
    if info.get('CFBundleIdentifier') != firebase.BUNDLE_ID:
        raise ValueError('Archived bundle identifier is incorrect.')
    if entitlements.get('aps-environment') != 'production':
        raise ValueError('App Store/TestFlight signing requires the production APNs entitlement.')
    if not str(entitlements.get('application-identifier', '')).endswith('.' + firebase.BUNDLE_ID):
        raise ValueError('Signing application identifier does not match the bundle.')
    if entitlements.get('get-task-allow', False):
        raise ValueError('A development signing profile cannot be uploaded to the App Store.')
    defines = firebase.build_defines(environment)
    if defines.get('GOOGLE_SIGN_IN_ENABLED') == 'true':
        client = defines['GOOGLE_SIGN_IN_IOS_CLIENT_ID']
        schemes = [scheme for item in info.get('CFBundleURLTypes', []) for scheme in item.get('CFBundleURLSchemes', [])]
        if (info.get('GIDClientID') != client or info.get('GIDServerClientID') != defines['GOOGLE_SIGN_IN_SERVER_CLIENT_ID']
                or firebase.reversed_client_id(client) not in schemes):
            raise ValueError('Archived Google sign-in client or callback scheme is missing or incorrect.')


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--app', type=Path, required=True)
    parser.add_argument('--environment', required=True, choices=firebase.ENVIRONMENTS)
    args = parser.parse_args(argv)
    try:
        result = subprocess.run(['codesign', '-d', '--entitlements', ':-', str(args.app)], capture_output=True, check=True)
        verify(args.app, plistlib.loads(result.stdout), args.environment)
    except (ValueError, OSError, plistlib.InvalidFileException, subprocess.CalledProcessError):
        print('iOS archive verification failed. Check bundle ID, Firebase client file and App Store APNs signing.', file=sys.stderr)
        return 1
    print('iOS archive Firebase and production APNs signing verified.')
    return 0


if __name__ == '__main__':
    sys.exit(main())
