import 'package:flutter/foundation.dart';

/// Returns the client platform identifier for the X-Client-Platform header.
///
/// Supported values: ios, android, macos, windows, linux, web, ipados.
String getClientPlatform() {
  if (kIsWeb) return 'web';
  switch (defaultTargetPlatform) {
    case TargetPlatform.android:
      return 'android';
    case TargetPlatform.iOS:
      return 'ios';
    case TargetPlatform.macOS:
      return 'macos';
    case TargetPlatform.windows:
      return 'windows';
    case TargetPlatform.linux:
      return 'linux';
    default:
      return 'unknown';
  }
}
