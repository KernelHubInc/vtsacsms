import 'package:geolocator/geolocator.dart';
import 'package:vtsa_mobile/core/maps/map_models.dart';

abstract interface class DeviceLocationService {
  Future<MapPosition?> currentPosition();
}

final class GeolocatorDeviceLocationService implements DeviceLocationService {
  @override
  Future<MapPosition?> currentPosition() async {
    if (!await Geolocator.isLocationServiceEnabled()) {
      return null;
    }

    var permission = await Geolocator.checkPermission();
    if (permission == LocationPermission.denied) {
      permission = await Geolocator.requestPermission();
    }
    if (permission == LocationPermission.denied ||
        permission == LocationPermission.deniedForever) {
      return null;
    }

    final position = await Geolocator.getCurrentPosition(
      locationSettings: const LocationSettings(
        accuracy: LocationAccuracy.medium,
        timeLimit: Duration(seconds: 10),
      ),
    );

    return MapPosition(position.latitude, position.longitude);
  }
}

final class UnavailableDeviceLocationService implements DeviceLocationService {
  const UnavailableDeviceLocationService();

  @override
  Future<MapPosition?> currentPosition() async => null;
}
