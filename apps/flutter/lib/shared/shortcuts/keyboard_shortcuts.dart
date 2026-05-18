import 'dart:io' show Platform;
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

/// Logical action intents used by the shortcut system.
class NavigateIntent extends Intent {
  final int index;
  const NavigateIntent(this.index);
}

class CommandPaletteIntent extends Intent {
  const CommandPaletteIntent();
}

class QuitIntent extends Intent {
  const QuitIntent();
}

/// Returns the appropriate meta key for the current platform.
SingleActivator act(LogicalKeyboardKey key, {bool shift = false, bool alt = false}) {
  if (Platform.isMacOS) {
    return SingleActivator(
      key,
      meta: true,
      shift: shift,
      alt: alt,
    );
  }
  return SingleActivator(
    key,
    control: true,
    shift: shift,
    alt: alt,
  );
}

Map<ShortcutActivator, Intent> buildShortcuts() {
  return {
    // Navigation: Cmd/Ctrl + 1-5 to switch sections
    act(LogicalKeyboardKey.digit1): const NavigateIntent(0),
    act(LogicalKeyboardKey.digit2): const NavigateIntent(1),
    act(LogicalKeyboardKey.digit3): const NavigateIntent(2),
    act(LogicalKeyboardKey.digit4): const NavigateIntent(3),
    act(LogicalKeyboardKey.digit5): const NavigateIntent(4),

    // Command palette: Cmd/Ctrl + K
    act(LogicalKeyboardKey.keyK): const CommandPaletteIntent(),

    // Quit: Cmd/Ctrl + Q
    act(LogicalKeyboardKey.keyQ): const QuitIntent(),

    // New: Cmd/Ctrl + N
    act(LogicalKeyboardKey.keyN): const CommandPaletteIntent(),

    // Settings: Cmd/Ctrl + Comma
    act(LogicalKeyboardKey.comma): const CommandPaletteIntent(),
  };
}
