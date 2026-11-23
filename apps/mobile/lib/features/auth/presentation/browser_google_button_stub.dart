import 'package:flutter/widgets.dart';
import 'package:vtsa_mobile/features/auth/domain/google_auth.dart';

Widget browserGoogleButton({
  required GoogleIdentityProvider provider,
  required bool busy,
  required ValueChanged<String> onCredential,
}) => const SizedBox.shrink();
