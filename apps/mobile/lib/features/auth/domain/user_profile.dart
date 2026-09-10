import 'package:flutter/foundation.dart';

@immutable
final class UserProfile {
  const UserProfile({
    required this.id,
    required this.name,
    required this.email,
    required this.emailVerified,
    this.mobileNumber,
  });

  factory UserProfile.fromJson(Map<String, dynamic> json) => UserProfile(
    id: (json['id'] ?? json['user_id'] ?? '') as String,
    name: (json['name'] ?? 'Power Solutions driver') as String,
    email: (json['email'] ?? '') as String,
    emailVerified:
        json['email_verified'] == true || json['email_verified_at'] != null,
    mobileNumber: json['mobile_number'] as String?,
  );

  final String id;
  final String name;
  final String email;
  final bool emailVerified;
  final String? mobileNumber;
}
