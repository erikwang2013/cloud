import 'dart:io' show Platform;
import 'package:flutter/material.dart';
import 'package:window_manager/window_manager.dart';
import '../../core/theme/app_theme.dart';

class DesktopMenuBar extends StatelessWidget {
  final List<_MenuGroup> menus;

  const DesktopMenuBar({super.key, required this.menus});

  static List<_MenuGroup> defaultMenus() => [
    _MenuGroup(label: 'File', items: [
      _MenuItem(label: 'New Resource', shortcut: 'Ctrl+N', onTap: () {}),
      _MenuItem.divider(),
      _MenuItem(label: 'Settings', shortcut: 'Ctrl+,', onTap: () {}),
      _MenuItem.divider(),
      _MenuItem(label: 'Quit', shortcut: 'Ctrl+Q', onTap: () => windowManager.close()),
    ]),
    _MenuGroup(label: 'Edit', items: [
      _MenuItem(label: 'Undo', shortcut: 'Ctrl+Z', onTap: () {}),
      _MenuItem(label: 'Redo', shortcut: 'Ctrl+Shift+Z', onTap: () {}),
      _MenuItem.divider(),
      _MenuItem(label: 'Cut', shortcut: 'Ctrl+X', onTap: () {}),
      _MenuItem(label: 'Copy', shortcut: 'Ctrl+C', onTap: () {}),
      _MenuItem(label: 'Paste', shortcut: 'Ctrl+V', onTap: () {}),
    ]),
    _MenuGroup(label: 'View', items: [
      _MenuItem(label: 'Command Palette', shortcut: 'Ctrl+K', onTap: () {}),
      _MenuItem.divider(),
      _MenuItem(label: 'Toggle Sidebar', shortcut: 'Ctrl+B', onTap: () {}),
      _MenuItem(label: 'Toggle Dark Mode', onTap: () {}),
    ]),
    _MenuGroup(label: 'Help', items: [
      _MenuItem(label: 'Documentation', onTap: () {}),
      _MenuItem(label: 'About', onTap: () {}),
    ]),
  ];

  @override
  Widget build(BuildContext context) {
    if (Platform.isMacOS) {
      return PlatformMenuBar(
        menus: menus.map(_toPlatformMenu).toList(),
        child: const SizedBox.shrink(),
      );
    }

    return Container(
      height: 36,
      padding: const EdgeInsets.symmetric(horizontal: 4),
      decoration: BoxDecoration(
        color: Theme.of(context).scaffoldBackgroundColor,
        border: const Border(bottom: BorderSide(color: AppTheme.headerBorder)),
      ),
      child: Row(
        children: menus.map((m) => _InlineMenu(label: m.label, items: m.items)).toList(),
      ),
    );
  }

  PlatformMenu _toPlatformMenu(_MenuGroup group) {
    return PlatformMenu(
      label: group.label,
      menus: group.items.map(_toPlatformMenuItem).toList(),
    );
  }

  PlatformMenuItem _toPlatformMenuItem(_MenuItem item) {
    return PlatformMenuItemGroup(
      members: [
        PlatformMenuItem(
          label: item.label,
          shortcut: _parseShortcut(item.shortcut),
          onSelected: item.onTap,
        ),
      ],
    );
  }

  MenuSerializableShortcut? _parseShortcut(String? shortcut) {
    if (shortcut == null) return null;
    return CharacterActivator(shortcut.characters.last.toLowerCase());
  }
}

class _MenuGroup {
  final String label;
  final List<_MenuItem> items;
  const _MenuGroup({required this.label, required this.items});
}

class _MenuItem {
  final String label;
  final String? shortcut;
  final VoidCallback onTap;

  const _MenuItem({
    required this.label,
    this.shortcut,
    required this.onTap,
  });
  const _MenuItem.divider()
      : label = '',
        shortcut = null,
        onTap = _noop;

  static void _noop() {}
}

class _InlineMenu extends StatefulWidget {
  final String label;
  final List<_MenuItem> items;
  const _InlineMenu({required this.label, required this.items});

  @override
  State<_InlineMenu> createState() => _InlineMenuState();
}

class _InlineMenuState extends State<_InlineMenu> {
  bool _hovered = false;
  OverlayEntry? _overlay;

  @override
  Widget build(BuildContext context) {
    return MouseRegion(
      onEnter: (_) => setState(() => _hovered = true),
      onExit: (_) => setState(() => _hovered = false),
      child: GestureDetector(
        onTap: _toggleOverlay,
        child: Container(
          padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
          margin: const EdgeInsets.symmetric(horizontal: 1),
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(4),
            color: _hovered ? Colors.grey.withValues(alpha: 0.15) : Colors.transparent,
          ),
          child: Text(
            widget.label,
            style: TextStyle(fontSize: 12, color: _hovered ? Colors.black87 : Colors.grey[700]),
          ),
        ),
      ),
    );
  }

  void _toggleOverlay() {
    _overlay?.remove();
    _overlay = null;
  }

  @override
  void dispose() {
    _overlay?.remove();
    super.dispose();
  }
}
