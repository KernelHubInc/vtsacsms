import 'package:flutter/material.dart';
import 'package:intl/intl.dart';

String dateOnly(DateTime date) => DateFormat('yyyy-MM-dd').format(date);

bool isAdult(DateTime birthDate, DateTime today) {
  final age =
      today.year -
      birthDate.year -
      ((today.month < birthDate.month ||
              (today.month == birthDate.month && today.day < birthDate.day))
          ? 1
          : 0);
  return age >= 18 && birthDate.year >= 1900;
}

class DriverDateField extends StatelessWidget {
  const DriverDateField({
    required this.label,
    required this.value,
    required this.onChanged,
    this.birthDate = true,
    super.key,
  });
  final String label;
  final DateTime? value;
  final ValueChanged<DateTime> onChanged;
  final bool birthDate;

  @override
  Widget build(BuildContext context) {
    final now = DateTime.now().toUtc();
    final last = birthDate
        ? DateTime(
            now.year - 18,
            now.month,
            now.day.clamp(1, DateTime(now.year - 18, now.month + 1, 0).day),
          )
        : DateTime(now.year + 30, 12, 31);
    final first = birthDate
        ? DateTime(1900)
        : DateTime(now.year, now.month, now.day);
    final initial =
        value == null || value!.isBefore(first) || value!.isAfter(last)
        ? last
        : value!;
    return OutlinedButton.icon(
      icon: const Icon(Icons.calendar_month_outlined),
      label: Padding(
        padding: const EdgeInsets.symmetric(vertical: 16),
        child: Text(
          '$label: ${value == null ? "Select date" : DateFormat("dd MMM yyyy").format(value!)}',
        ),
      ),
      onPressed: () async {
        final date = await showDatePicker(
          context: context,
          initialDate: initial,
          firstDate: first,
          lastDate: last,
          helpText: birthDate ? 'You must be 18 or older' : label,
        );
        if (date != null) onChanged(date);
      },
    );
  }
}
