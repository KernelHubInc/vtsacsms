import 'package:flutter/material.dart';

abstract final class PowerSolutionsColors {
  static const brandNavy = Color(0xFF12366B);
  static const brandBlue = Color(0xFF2589BE);
  static const brandTurquoise = Color(0xFF57DDD2);
  static const brandCyan = Color(0xFF45CFC7);
  static const brandDarkNavy = Color(0xFF0B2448);
  static const surfaceDefault = Color(0xFFFFFFFF);
  static const surfaceSoftCyan = Color(0xFFE9FAF8);
  static const surfaceSoftBlue = Color(0xFFEDF7FC);
  static const surfaceMuted = Color(0xFFF5F8FB);
  static const surfaceDark = Color(0xFF081B35);
  static const textPrimary = Color(0xFF17243A);
  static const textSecondary = Color(0xFF66758A);
  static const textOnPrimary = Color(0xFFFFFFFF);
  static const textLink = Color(0xFF1D78AD);
  static const borderDefault = Color(0xFFD8E4ED);
  static const borderStrong = Color(0xFFAFC6D8);
  static const focusRing = Color(0xFF45CFC7);
  static const success = Color(0xFF18A978);
  static const warning = Color(0xFFE8A522);
  static const danger = Color(0xFFD64555);
  static const information = Color(0xFF2589BE);
  static const primaryHover = Color(0xFF0E2B59);
  static const primaryActive = Color(0xFF091F43);
  static const primaryDisabled = Color(0xFF9CB0C6);
  static const accentHover = Color(0xFF32BBB4);
  static const accentActive = Color(0xFF25A49E);
}

abstract final class VtsaSpacing {
  static const double xxs = 4;
  static const double xs = 8;
  static const double sm = 12;
  static const double md = 16;
  static const double lg = 24;
  static const double xl = 32;
  static const double xxl = 48;
  static const double xxxl = 64;
}

abstract final class VtsaRadii {
  static const double sm = 6;
  static const double md = 10;
  static const double lg = 14;
  static const double xl = 20;
  static const double pill = 999;
}

enum VtsaOperationalState {
  available,
  preparing,
  charging,
  suspended,
  finishing,
  reserved,
  unavailable,
  faulted,
  offline,
  unknown,
}

@immutable
class VtsaSemanticColors extends ThemeExtension<VtsaSemanticColors> {
  const VtsaSemanticColors({
    required this.canvas,
    required this.surfaceSubtle,
    required this.surfaceRaised,
    required this.textMuted,
    required this.border,
    required this.borderStrong,
    required this.brandSoft,
    required this.accent,
    required this.success,
    required this.successSurface,
    required this.warning,
    required this.warningSurface,
    required this.danger,
    required this.dangerSurface,
    required this.information,
    required this.informationSurface,
    required this.focus,
    required this.available,
    required this.preparing,
    required this.charging,
    required this.suspended,
    required this.finishing,
    required this.reserved,
    required this.unavailable,
    required this.faulted,
    required this.offline,
    required this.unknown,
  });

  static const VtsaSemanticColors light = VtsaSemanticColors(
    canvas: Color(0xFFF5F8FB),
    surfaceSubtle: Color(0xFFEDF7FC),
    surfaceRaised: Color(0xFFFFFFFF),
    textMuted: Color(0xFF66758A),
    border: Color(0xFFD8E4ED),
    borderStrong: Color(0xFFAFC6D8),
    brandSoft: Color(0xFFE9FAF8),
    accent: Color(0xFF45CFC7),
    success: Color(0xFF18A978),
    successSurface: Color(0xFFE2F7EE),
    warning: Color(0xFFA66400),
    warningSurface: Color(0xFFFFF2CF),
    danger: Color(0xFFD64555),
    dangerSurface: Color(0xFFFDE7EC),
    information: Color(0xFF2589BE),
    informationSurface: Color(0xFFE6F1FF),
    focus: Color(0xFF1D78AD),
    available: Color(0xFF18A978),
    preparing: Color(0xFF2589BE),
    charging: Color(0xFF12366B),
    suspended: Color(0xFFA86600),
    finishing: Color(0xFF2589BE),
    reserved: Color(0xFF9747B5),
    unavailable: Color(0xFF697187),
    faulted: Color(0xFFB4233F),
    offline: Color(0xFF4A5164),
    unknown: Color(0xFF8C94A8),
  );

  static const VtsaSemanticColors dark = VtsaSemanticColors(
    canvas: Color(0xFF07172C),
    surfaceSubtle: Color(0xFF102F57),
    surfaceRaised: Color(0xFF12366B),
    textMuted: Color(0xFFB9CFDD),
    border: Color(0xFF27496F),
    borderStrong: Color(0xFF4E7194),
    brandSoft: Color(0xFF123B4F),
    accent: Color(0xFF57DDD2),
    success: Color(0xFF55D9A5),
    successSurface: Color(0xFF12372F),
    warning: Color(0xFFF6C453),
    warningSurface: Color(0xFF3A2C12),
    danger: Color(0xFFFF8094),
    dangerSurface: Color(0xFF421D29),
    information: Color(0xFF75B8FF),
    informationSurface: Color(0xFF162E4C),
    focus: Color(0xFFB8AFFF),
    available: Color(0xFF55D9A5),
    preparing: Color(0xFF6ED4E4),
    charging: Color(0xFF6ECFF2),
    suspended: Color(0xFFF6C453),
    finishing: Color(0xFF75B8FF),
    reserved: Color(0xFFE59AF2),
    unavailable: Color(0xFFAEB5C7),
    faulted: Color(0xFFFF8094),
    offline: Color(0xFF8C94A8),
    unknown: Color(0xFFB8BECE),
  );

  final Color canvas;
  final Color surfaceSubtle;
  final Color surfaceRaised;
  final Color textMuted;
  final Color border;
  final Color borderStrong;
  final Color brandSoft;
  final Color accent;
  final Color success;
  final Color successSurface;
  final Color warning;
  final Color warningSurface;
  final Color danger;
  final Color dangerSurface;
  final Color information;
  final Color informationSurface;
  final Color focus;
  final Color available;
  final Color preparing;
  final Color charging;
  final Color suspended;
  final Color finishing;
  final Color reserved;
  final Color unavailable;
  final Color faulted;
  final Color offline;
  final Color unknown;

  Color forState(VtsaOperationalState state) => switch (state) {
    VtsaOperationalState.available => available,
    VtsaOperationalState.preparing => preparing,
    VtsaOperationalState.charging => charging,
    VtsaOperationalState.suspended => suspended,
    VtsaOperationalState.finishing => finishing,
    VtsaOperationalState.reserved => reserved,
    VtsaOperationalState.unavailable => unavailable,
    VtsaOperationalState.faulted => faulted,
    VtsaOperationalState.offline => offline,
    VtsaOperationalState.unknown => unknown,
  };

  @override
  VtsaSemanticColors copyWith({
    Color? canvas,
    Color? surfaceSubtle,
    Color? surfaceRaised,
    Color? textMuted,
    Color? border,
    Color? borderStrong,
    Color? brandSoft,
    Color? accent,
    Color? success,
    Color? successSurface,
    Color? warning,
    Color? warningSurface,
    Color? danger,
    Color? dangerSurface,
    Color? information,
    Color? informationSurface,
    Color? focus,
    Color? available,
    Color? preparing,
    Color? charging,
    Color? suspended,
    Color? finishing,
    Color? reserved,
    Color? unavailable,
    Color? faulted,
    Color? offline,
    Color? unknown,
  }) => VtsaSemanticColors(
    canvas: canvas ?? this.canvas,
    surfaceSubtle: surfaceSubtle ?? this.surfaceSubtle,
    surfaceRaised: surfaceRaised ?? this.surfaceRaised,
    textMuted: textMuted ?? this.textMuted,
    border: border ?? this.border,
    borderStrong: borderStrong ?? this.borderStrong,
    brandSoft: brandSoft ?? this.brandSoft,
    accent: accent ?? this.accent,
    success: success ?? this.success,
    successSurface: successSurface ?? this.successSurface,
    warning: warning ?? this.warning,
    warningSurface: warningSurface ?? this.warningSurface,
    danger: danger ?? this.danger,
    dangerSurface: dangerSurface ?? this.dangerSurface,
    information: information ?? this.information,
    informationSurface: informationSurface ?? this.informationSurface,
    focus: focus ?? this.focus,
    available: available ?? this.available,
    preparing: preparing ?? this.preparing,
    charging: charging ?? this.charging,
    suspended: suspended ?? this.suspended,
    finishing: finishing ?? this.finishing,
    reserved: reserved ?? this.reserved,
    unavailable: unavailable ?? this.unavailable,
    faulted: faulted ?? this.faulted,
    offline: offline ?? this.offline,
    unknown: unknown ?? this.unknown,
  );

  @override
  VtsaSemanticColors lerp(covariant VtsaSemanticColors? other, double t) {
    if (other == null) {
      return this;
    }

    Color blend(Color from, Color to) => Color.lerp(from, to, t) ?? from;

    return VtsaSemanticColors(
      canvas: blend(canvas, other.canvas),
      surfaceSubtle: blend(surfaceSubtle, other.surfaceSubtle),
      surfaceRaised: blend(surfaceRaised, other.surfaceRaised),
      textMuted: blend(textMuted, other.textMuted),
      border: blend(border, other.border),
      borderStrong: blend(borderStrong, other.borderStrong),
      brandSoft: blend(brandSoft, other.brandSoft),
      accent: blend(accent, other.accent),
      success: blend(success, other.success),
      successSurface: blend(successSurface, other.successSurface),
      warning: blend(warning, other.warning),
      warningSurface: blend(warningSurface, other.warningSurface),
      danger: blend(danger, other.danger),
      dangerSurface: blend(dangerSurface, other.dangerSurface),
      information: blend(information, other.information),
      informationSurface: blend(informationSurface, other.informationSurface),
      focus: blend(focus, other.focus),
      available: blend(available, other.available),
      preparing: blend(preparing, other.preparing),
      charging: blend(charging, other.charging),
      suspended: blend(suspended, other.suspended),
      finishing: blend(finishing, other.finishing),
      reserved: blend(reserved, other.reserved),
      unavailable: blend(unavailable, other.unavailable),
      faulted: blend(faulted, other.faulted),
      offline: blend(offline, other.offline),
      unknown: blend(unknown, other.unknown),
    );
  }
}

extension VtsaThemeContext on BuildContext {
  VtsaSemanticColors get vtsaColors =>
      Theme.of(this).extension<VtsaSemanticColors>() ??
      VtsaSemanticColors.light;
}
