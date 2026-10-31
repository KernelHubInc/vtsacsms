import 'package:vtsa_mobile/features/auth/presentation/driver_date_field.dart';

Map<String, bool> registrationPasswordRequirements(String password) => {
  'At least 8 characters': password.runes.length >= 8,
  'Uppercase and lowercase letters':
      RegExp(r'\p{Lu}', unicode: true).hasMatch(password) &&
      RegExp(r'\p{Ll}', unicode: true).hasMatch(password),
  'At least one number': RegExp(r'\p{N}', unicode: true).hasMatch(password),
  'At least one special character': RegExp(
    r'[\p{S}\p{P}]',
    unicode: true,
  ).hasMatch(password),
};

String registrationFieldMessage(String field) => switch (field) {
  'email' => 'Enter a valid email address, such as name@example.com.',
  'password' =>
    'Use at least 8 characters with uppercase and lowercase letters, a number and a symbol.',
  'password_confirmation' => 'Enter the same password in both fields.',
  'first_name' => 'Enter your first name using no more than 80 characters.',
  'middle_name' => 'Use no more than 80 characters for your middle name.',
  'last_name' => 'Enter your last name using no more than 80 characters.',
  'birth_date' => 'Select your date of birth. You must be 18 or older.',
  'plate_number' =>
    'Enter 1–20 letters or numbers; spaces and hyphens are allowed. Or select Plate pending.',
  _ => 'Review this field and try again.',
};

// Do not display arbitrary server text or infer a syntax error from a rejection.
String registrationServerFieldMessage(String field) => switch (field) {
  'email' =>
    'This email could not be accepted. Check it, or try signing in or resetting your password.',
  'password' =>
    'This password could not be accepted. Choose a different password that meets all the requirements.',
  'password_confirmation' => registrationFieldMessage(field),
  _ => 'Check this detail and try again.',
};

/// Local checks are deliberately conservative; the API remains authoritative.
Set<String> registrationInvalidFields({
  required String firstName,
  required String middleName,
  required String lastName,
  required DateTime? birthDate,
  required DateTime today,
  required String plate,
  required bool platePending,
  required String email,
  required String password,
  required String passwordConfirmation,
}) => {
  if (firstName.trim().isEmpty || firstName.trim().runes.length > 80)
    'first_name',
  if (middleName.trim().runes.length > 80) 'middle_name',
  if (lastName.trim().isEmpty || lastName.trim().runes.length > 80) 'last_name',
  if (birthDate == null || !isAdult(birthDate, today)) 'birth_date',
  if (!platePending &&
      !RegExp(
        r'^[A-Z0-9][A-Z0-9 -]{0,19}$',
      ).hasMatch(plate.trim().toUpperCase()))
    'plate_number',
  if (email.trim().length > 254 ||
      !RegExp(r'^[^\s@]+@[^\s@]+\.[^\s@]+$').hasMatch(email.trim()))
    'email',
  if (registrationPasswordRequirements(password).values.any((met) => !met))
    'password',
  if (passwordConfirmation.isEmpty || passwordConfirmation != password)
    'password_confirmation',
};
