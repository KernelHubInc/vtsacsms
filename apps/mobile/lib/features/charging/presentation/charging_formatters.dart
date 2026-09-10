import 'package:intl/intl.dart';
import 'package:vtsa_mobile/features/charging/domain/charging_models.dart';

String formatMoney(Money? money) {
  if (money == null) {
    return 'Pending';
  }
  return NumberFormat.simpleCurrency(
    name: money.currency,
  ).format(money.minorUnits / 100);
}

String formatEnergy(int wattHours) =>
    '${(wattHours / 1000).toStringAsFixed(2)} kWh';

String formatPower(int? watts) {
  if (watts == null) {
    return 'Not reported';
  }
  return watts >= 1000 ? '${(watts / 1000).toStringAsFixed(1)} kW' : '$watts W';
}

String formatDuration(int totalSeconds) {
  final hours = totalSeconds ~/ 3600;
  final minutes = (totalSeconds % 3600) ~/ 60;
  final seconds = totalSeconds % 60;
  return [
    hours,
    minutes,
    seconds,
  ].map((value) => value.toString().padLeft(2, '0')).join(':');
}
