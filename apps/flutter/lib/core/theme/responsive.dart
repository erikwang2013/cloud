import 'dart:io' show Platform;
import 'package:flutter/material.dart';

class ResponsiveBreakpoints {
  static const double mobile = 600;
  static const double tablet = 900;
  static const double desktop = 1200;
  static const double largeDesktop = 1600;

  static bool isMobile(BuildContext context) =>
      MediaQuery.of(context).size.width < mobile;

  static bool isTablet(BuildContext context) {
    final w = MediaQuery.of(context).size.width;
    return w >= mobile && w < tablet;
  }

  static bool isDesktop(BuildContext context) {
    final w = MediaQuery.of(context).size.width;
    return w >= tablet;
  }

  static bool isLargeDesktop(BuildContext context) {
    final w = MediaQuery.of(context).size.width;
    return w >= largeDesktop;
  }

  /// Whether the current platform is a desktop OS (not mobile/tablet).
  static bool get isDesktopPlatform =>
      Platform.isMacOS || Platform.isWindows || Platform.isLinux;

  /// Whether the device is an iPad (iOS but not a phone-sized screen).
  static bool get isIPadOS => Platform.isIOS && !Platform.isAndroid;

  /// True when the layout should use desktop navigation patterns.
  static bool useDesktopLayout(BuildContext context) =>
      isDesktopPlatform || isDesktop(context);

  /// True when the layout should use tablet patterns (iPadOS or large tablet).
  static bool useTabletLayout(BuildContext context) =>
      isTablet(context) || (isIPadOS && MediaQuery.of(context).size.width >= tablet);

  static double sidebarWidth(BuildContext context) {
    final w = MediaQuery.of(context).size.width;
    if (w >= largeDesktop) return 260;
    if (w >= desktop) return 240;
    return 220;
  }

  static double contentPadding(BuildContext context) {
    final w = MediaQuery.of(context).size.width;
    if (w >= largeDesktop) return 32;
    if (w >= desktop) return 24;
    return 16;
  }

  /// Min touch target size for iPadOS / touch-friendly PC.
  static double get minTouchTarget => 44;
}
