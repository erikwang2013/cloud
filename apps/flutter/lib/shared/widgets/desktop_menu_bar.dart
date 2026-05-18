import 'dart:io' show Platform;
import 'package:flutter/material.dart';
import 'package:window_manager/window_manager.dart';
import '../../core/theme/app_theme.dart';

class DesktopMenuBar extends StatelessWidget {
  final List<MenuGroup> menus;

  const DesktopMenuBar({super.key, required this.menus});

  static List<MenuGroup> defaultMenus() => [
    MenuGroup(label: 'File', items: [
      MenuItem(label: 'New Resource', shortcut: 'Ctrl+N', onTap: () {}),
      MenuItem.divider(),
      MenuItem(label: 'Settings', shortcut: 'Ctrl+,', onTap: () {}),
      MenuItem.divider(),
      MenuItem(label: 'Quit', shortcut: 'Ctrl+Q', onTap: () => windowManager.close()),
    ]),
    MenuGroup(label: 'Edit', items: [
      MenuItem(label: 'Undo', shortcut: 'Ctrl+Z', onTap: () {}),
      MenuItem(label: 'Redo', shortcut: 'Ctrl+Shift+Z', onTap: () {}),
      MenuItem.divider(),
      MenuItem(label: 'Cut', shortcut: 'Ctrl+X', onTap: () {}),
      MenuItem(label: 'Copy', shortcut: 'Ctrl+C', onTap: () {}),
      MenuItem(label: 'Paste', shortcut: 'Ctrl+V', onTap: () {}),
    ]),
    MenuGroup(label: 'View', items: [
      MenuItem(label: 'Command Palette', shortcut: 'Ctrl+K', onTap: () {}),
      MenuItem.divider(),
      MenuItem(label: 'Toggle Sidebar', shortcut: 'Ctrl+B', onTap: () {}),
      MenuItem(label: 'Toggle Dark Mode', onTap: () {}),
    ]),
    MenuGroup(label: 'Help', items: [
      MenuItem(label: 'Documentation', onTap: () {}),
      MenuItem(label: 'About', onTap: () {}),
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
        children: menus.map((m) => _InlineMenu(group: m)).toList(),
      ),
    );
  }

  PlatformMenu _toPlatformMenu(MenuGroup group) {
    return PlatformMenu(
      label: group.label,
      menus: group.items.where((i) => !i.isDivider).map((item) {
        return PlatformMenuItemGroup(
          members: [
            PlatformMenuItem(
              label: item.label,
              shortcut: _parseShortcut(item.shortcut),
              onSelected: item.onTap,
            ),
          ],
        );
      }).toList(),
    );
  }

  MenuSerializableShortcut? _parseShortcut(String? shortcut) {
    if (shortcut == null) return null;
    return CharacterActivator(shortcut.characters.last.toLowerCase());
  }
}

class MenuGroup {
  final String label;
  final List<MenuItem> items;
  const MenuGroup({required this.label, required this.items});
}

class MenuItem {
  final String label;
  final String? shortcut;
  final VoidCallback onTap;
  final bool isDivider;

  const MenuItem({
    required this.label,
    this.shortcut,
    required this.onTap,
    this.isDivider = false,
  });
  const MenuItem.divider()
      : label = '',
        shortcut = null,
        onTap = _noop,
        isDivider = true;

  static void _noop() {}
}

class _InlineMenu extends StatefulWidget {
  final MenuGroup group;
  const _InlineMenu({required this.group});

  @override
  State<_InlineMenu> createState() => _InlineMenuState();
}

class _InlineMenuState extends State<_InlineMenu> {
  bool _hovered = false;
  final _layerLink = LayerLink();
  OverlayEntry? _overlay;
  bool _open = false;

  void _show() {
    if (_open) return;
    setState(() => _open = true);
    final overlay = Overlay.of(context);
    final renderBox = context.findRenderObject() as RenderBox;
    final size = renderBox.size;
    final offset = renderBox.localToGlobal(Offset.zero);

    _overlay = OverlayEntry(
      builder: (ctx) => GestureDetector(
        behavior: HitTestBehavior.translucent,
        onTap: _hide,
        child: Stack(
          children: [
            Positioned(
              left: offset.dx,
              top: offset.dy + size.height,
              width: 220,
              child: CompositedTransformFollower(
                link: _layerLink,
                showWhenUnlinked: false,
                offset: Offset(0, size.height),
                child: Material(
                  elevation: 8,
                  borderRadius: BorderRadius.circular(8),
                  color: Theme.of(context).scaffoldBackgroundColor,
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: widget.group.items.map((item) {
                      if (item.isDivider) return const Divider(height: 1);
                      return InkWell(
                        onTap: () {
                          _hide();
                          item.onTap();
                        },
                        child: Padding(
                          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
                          child: Row(
                            children: [
                              Expanded(child: Text(item.label, style: const TextStyle(fontSize: 13))),
                              if (item.shortcut != null)
                                Text(item.shortcut!, style: TextStyle(fontSize: 11, color: Colors.grey[500])),
                            ],
                          ),
                        ),
                      );
                    }).toList(),
                  ),
                ),
              ),
            ),
          ],
        ),
      ),
    );
    overlay.insert(_overlay!);
  }

  void _hide() {
    if (!_open) return;
    _overlay?.remove();
    _overlay = null;
    setState(() => _open = false);
  }

  @override
  Widget build(BuildContext context) {
    return MouseRegion(
      onEnter: (_) => setState(() => _hovered = true),
      onExit: (_) => setState(() => _hovered = false),
      child: CompositedTransformTarget(
        link: _layerLink,
        child: GestureDetector(
          onTap: _open ? _hide : _show,
          child: Container(
            padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
            margin: const EdgeInsets.symmetric(horizontal: 1),
            decoration: BoxDecoration(
              borderRadius: BorderRadius.circular(4),
              color: _open || _hovered ? Colors.grey.withValues(alpha: 0.15) : Colors.transparent,
            ),
            child: Text(
              widget.group.label,
              style: TextStyle(fontSize: 12, color: _hovered || _open ? Colors.black87 : Colors.grey[700]),
            ),
          ),
        ),
      ),
    );
  }

  @override
  void dispose() {
    _overlay?.remove();
    super.dispose();
  }
}
