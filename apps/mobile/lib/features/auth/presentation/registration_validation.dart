import 'package:vtsa_mobile/features/auth/presentation/driver_date_field.dart';

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
  if (password.runes.length < 8 ||
      !RegExp(r'\p{Lu}', unicode: true).hasMatch(password) ||
      !RegExp(r'\p{Ll}', unicode: true).hasMatch(password) ||
      !RegExp(r'\p{N}', unicode: true).hasMatch(password) ||
      !RegExp(r'[\p{S}\p{P}]', unicode: true).hasMatch(password))
    'password',
  if (passwordConfirmation.isEmpty || passwordConfirmation != password)
    'password_confirmation',
};
