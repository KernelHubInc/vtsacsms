final class LiveChallenge {
  LiveChallenge.fromJson(Map<String, dynamic> data)
    : token = data['token'] as String,
      action = data['action'] as String,
      step = data['step'] as int,
      complete = data['complete'] as bool,
      feedback = data['feedback'] as String,
      expiresAt = DateTime.parse(data['expires_at'] as String) {
    if (!RegExp(r'^[a-f0-9]{64}$').hasMatch(token) ||
        !const {'center', 'left', 'right', 'complete'}.contains(action) ||
        step < 0 ||
        step > 9 ||
        data['total_steps'] != 9 ||
        complete != (action == 'complete') ||
        complete != (step == 9)) {
      throw const FormatException('Invalid camera challenge');
    }
  }

  final String token;
  final String action;
  final int step;
  final bool complete;
  final String feedback;
  final DateTime expiresAt;

  String get instruction => switch (action) {
    'left' => 'Slowly turn your head to your left',
    'right' => 'Slowly turn your head to your right',
    'complete' => 'Live check complete',
    _ => 'Look straight at the camera',
  };
}
