import 'package:flutter_test/flutter_test.dart';
import 'package:vtsa_mobile/features/kyc/domain/live_challenge.dart';

void main() {
  Map<String, dynamic> prompt() => {
    'token': 'a' * 64,
    'action': 'left',
    'step': 1,
    'total_steps': 9,
    'complete': false,
    'feedback': 'follow_prompt',
    'expires_at': '2026-09-24T01:00:00Z',
  };

  test('live prompt uses server action and does not infer completion', () {
    final challenge = LiveChallenge.fromJson(prompt());
    expect(challenge.instruction, 'Slowly turn your head to your left');
    expect(challenge.complete, false);
    expect(challenge.step, 1);
    expect(
      LiveChallenge.fromJson({
        ...prompt(),
        'action': 'complete',
        'step': 9,
        'complete': true,
      }).complete,
      true,
    );
  });

  test('invalid server responses cannot declare successful live capture', () {
    for (final update in [
      {'complete': true},
      {'step': 9},
      {'action': 'complete'},
      {'token': 'bad'},
      {'action': 'unknown'},
      {'total_steps': 0},
    ]) {
      expect(
        () => LiveChallenge.fromJson({...prompt(), ...update}),
        throwsFormatException,
      );
    }
  });
}
