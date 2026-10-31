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
    this.invalid = false,
    this.error,
    this.labelAbove = false,
    this.enabled = true,
    super.key,
  });
  final String label;
  final DateTime? value;
  final ValueChanged<DateTime> onChanged;
  final bool birthDate;
  final bool invalid;
  final String? error;
  final bool labelAbove;
  final bool enabled;

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
    final field = Semantics(
      hint: invalid ? '$label needs attention' : null,
      label: labelAbove ? '$label (required)' : null,
      child: OutlinedButton.icon(
        style: labelAbove
            ? OutlinedButton.styleFrom(
                alignment: Alignment.centerLeft,
                padding: const EdgeInsets.all(16),
                minimumSize: const Size(0, 54),
                foregroundColor: value == null
                    ? Theme.of(context).hintColor
                    : Theme.of(context).colorScheme.onSurface,
                textStyle: Theme.of(context).textTheme.bodyMedium?.copyWith(
                  fontSize: 15,
                  fontWeight: FontWeight.w400,
                  height: 1.5,
                ),
                shape: RoundedRectangleBorder(
                  borderRadius: BorderRadius.circular(12),
                ),
                side: BorderSide(
                  color: invalid
                      ? Theme.of(context).colorScheme.error
                      : Theme.of(context).colorScheme.outline,
                  width: invalid ? 2 : 1,
                ),
              )
            : invalid
            ? OutlinedButton.styleFrom(
                side: BorderSide(
                  color: Theme.of(context).colorScheme.error,
                  width: 2,
                ),
              )
            : null,
        icon: labelAbove ? null : const Icon(Icons.calendar_month_outlined),
        label: Padding(
          padding: EdgeInsets.symmetric(vertical: labelAbove ? 0 : 16),
          child: Text(
            labelAbove
                ? (value == null
                      ? 'Select date · DD / MM / YYYY'
                      : DateFormat('dd / MM / yyyy').format(value!))
                : '$label: ${value == null ? "Select date" : DateFormat("dd MMM yyyy").format(value!)}',
          ),
        ),
        onPressed: !enabled
            ? null
            : () async {
                final date = await showDatePicker(
                  context: context,
                  initialDate: initial,
                  firstDate: first,
                  lastDate: last,
                  helpText: birthDate ? 'You must be 18 or older' : label,
                );
                if (context.mounted && date != null) onChanged(date);
              },
      ),
    );
    if (!labelAbove) return field;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Text(
          '$label *',
          style: Theme.of(context).textTheme.labelMedium?.copyWith(
            fontSize: 13,
            fontWeight: FontWeight.w400,
            height: 1.5,
          ),
        ),
        const SizedBox(height: 8),
        field,
        if (error != null)
          Padding(
            padding: const EdgeInsets.only(top: 8),
            child: Semantics(
              liveRegion: true,
              child: Text(
                error!,
                style: TextStyle(color: Theme.of(context).colorScheme.error),
              ),
            ),
          ),
      ],
    );
  }
}
