"""Build the existing Flutter app on a configured Mac; never fabricate an IPA."""

import argparse
from pathlib import Path
import platform
import re
import shutil
import subprocess
import sys
import time

MOBILE = Path(__file__).resolve().parents[1] / 'apps' / 'mobile'


def version(value: str) -> str:
    if not re.fullmatch(r'[0-9]+\.[0-9]+\.[0-9]+', value):
        raise argparse.ArgumentTypeError('Use a version such as 1.0.16.')
    return value


def build_number(value: str) -> str:
    if not re.fullmatch(r'[1-9][0-9]*', value):
        raise argparse.ArgumentTypeError('Build number must be a positive integer.')
    return value


def parser() -> argparse.ArgumentParser:
    result = argparse.ArgumentParser(description=__doc__)
    result.add_argument('--mode', choices=['simulator', 'ipa'], required=True)
    result.add_argument('--version', type=version, required=True)
    result.add_argument('--build-number', type=build_number, required=True)
    result.add_argument('--defines', type=Path, default=MOBILE / 'dart_defines.staging-demo.json')
    result.add_argument('--export-options', type=Path, help='Real ExportOptions.plist from Xcode for IPA signing.')
    return result


def preflight(args: argparse.Namespace) -> None:
    if platform.system() != 'Darwin':
        raise ValueError('iOS builds require a Mac or macOS CI runner with Xcode. Windows and the Linux VPS cannot build an IPA.')
    for command in ['flutter', 'pod', 'xcodebuild']:
        if shutil.which(command) is None:
            raise ValueError(f'Missing {command}. Complete the macOS setup in docs/runbooks/mobile-ios.md.')
    if not args.defines.is_file():
        raise ValueError('The selected Dart configuration file does not exist.')
    if args.mode == 'ipa' and (args.export_options is None or not args.export_options.is_file()):
        raise ValueError('IPA export requires --export-options pointing to your real Xcode export settings, plus installed signing credentials.')


def commands(args: argparse.Namespace) -> list[list[str]]:
    build = ['flutter', 'build']
    if args.mode == 'simulator':
        build += ['ios', '--simulator', '--debug']
    else:
        build += ['ipa', '--release', f'--export-options-plist={args.export_options.resolve()}']
    build += [f'--build-name={args.version}', f'--build-number={args.build_number}',
              f'--dart-define-from-file={args.defines.resolve()}']
    return [['xcodebuild', '-version'], ['flutter', 'pub', 'get'],
            ['flutter', 'analyze', '--no-pub'],
            ['flutter', 'test', '--concurrency=1', '--timeout=2m'], build]


def fresh_ipa(directory: Path, started: float) -> Path:
    candidates = [p for p in directory.glob('*.ipa') if p.stat().st_mtime >= started]
    if len(candidates) != 1:
        raise ValueError('No single newly exported IPA was produced. Check Xcode signing/export errors; an archive alone is not an installable IPA.')
    return candidates[0]


def main(argv: list[str] | None = None) -> int:
    args = parser().parse_args(argv)
    try:
        preflight(args)
        started = time.time()
        for command in commands(args):
            subprocess.run(command, cwd=MOBILE, check=True)
        if args.mode == 'ipa':
            print(f'IPA exported: {fresh_ipa(MOBILE / "build/ios/ipa", started)}')
            print('Validate with Xcode and distribute using the provisioning method selected for this build.')
        else:
            print(f'Simulator app: {MOBILE / "build/ios/iphonesimulator/Runner.app"}')
            print('Simulator output cannot be installed on an iPhone.')
        return 0
    except (ValueError, subprocess.CalledProcessError) as error:
        print(f'iOS build stopped: {error}', file=sys.stderr)
        return 1


if __name__ == '__main__':
    sys.exit(main())
