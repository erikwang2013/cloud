import 'package:flutter/material.dart';
import '../../core/theme/app_theme.dart';
import 'command_palette.dart';

class TopHeader extends StatefulWidget {
  final String pageTitle;
  final List<String>? breadcrumbs;

  const TopHeader({
    super.key,
    this.pageTitle = 'Products',
    this.breadcrumbs,
  });

  @override
  State<TopHeader> createState() => _TopHeaderState();
}

class _TopHeaderState extends State<TopHeader> {
  @override
  Widget build(BuildContext context) {
    final crumbs = widget.breadcrumbs ?? [widget.pageTitle];

    return Container(
      height: 56,
      padding: const EdgeInsets.symmetric(horizontal: 20),
      decoration: const BoxDecoration(
        color: AppTheme.headerBg,
        border: Border(bottom: BorderSide(color: AppTheme.headerBorder)),
      ),
      child: Row(
        children: [
          // Breadcrumbs
          ...crumbs.asMap().entries.map((e) {
            final last = e.key == crumbs.length - 1;
            return Row(
              mainAxisSize: MainAxisSize.min,
              children: [
                if (e.key > 0)
                  Padding(
                    padding: const EdgeInsets.symmetric(horizontal: 6),
                    child: Icon(Icons.chevron_right, size: 16, color: Colors.grey[400]),
                  ),
                Text(
                  e.value,
                  style: TextStyle(
                    fontSize: last ? 15 : 14,
                    fontWeight: last ? FontWeight.w600 : FontWeight.w400,
                    color: last ? const Color(0xFF1E293B) : Colors.grey[500],
                  ),
                ),
              ],
            );
          }),

          const Spacer(),

          // Command palette trigger
          _CommandPaletteButton(onTap: () => CommandPalette.show(context)),

          const SizedBox(width: 8),

          // Search
          SizedBox(
            width: 240,
            child: TextField(
              decoration: InputDecoration(
                hintText: 'Search...',
                hintStyle: TextStyle(color: Colors.grey[400], fontSize: 13),
                prefixIcon: const Icon(Icons.search, size: 18),
                border: OutlineInputBorder(
                  borderRadius: BorderRadius.circular(6),
                  borderSide: BorderSide(color: Colors.grey[300]!),
                ),
                contentPadding: const EdgeInsets.symmetric(vertical: 6),
                isDense: true,
                filled: true,
                fillColor: const Color(0xFFF8FAFC),
              ),
              style: const TextStyle(fontSize: 13),
            ),
          ),

          const SizedBox(width: 12),

          // Notifications
          _HeaderIconButton(
            icon: Icons.notifications_outlined,
            tooltip: 'Notifications',
            onTap: () {},
          ),

          // Language
          PopupMenuButton<String>(
            icon: const Icon(Icons.language, size: 20),
            tooltip: 'Language',
            onSelected: (_) {},
            itemBuilder: (_) => const [
              PopupMenuItem(value: 'en', child: Text('English')),
              PopupMenuItem(value: 'zh', child: Text('中文')),
            ],
          ),

          const SizedBox(width: 4),

          // User
          PopupMenuButton<String>(
            icon: const Icon(Icons.person_outline, size: 20),
            tooltip: 'Account',
            onSelected: (value) {},
            itemBuilder: (_) => const [
              PopupMenuItem(value: 'profile', child: Text('Profile')),
              PopupMenuItem(value: 'billing', child: Text('Billing')),
              PopupMenuItem(value: 'settings', child: Text('Settings')),
              PopupMenuDivider(),
              PopupMenuItem(value: 'logout', child: Text('Logout')),
            ],
          ),
        ],
      ),
    );
  }
}

class _CommandPaletteButton extends StatefulWidget {
  final VoidCallback onTap;
  const _CommandPaletteButton({required this.onTap});

  @override
  State<_CommandPaletteButton> createState() => _CommandPaletteButtonState();
}

class _CommandPaletteButtonState extends State<_CommandPaletteButton> {
  bool _focused = false;

  @override
  Widget build(BuildContext context) {
    return Focus(
      onFocusChange: (f) => setState(() => _focused = f),
      child: InkWell(
        onTap: widget.onTap,
        borderRadius: BorderRadius.circular(6),
        hoverColor: const Color(0x0A000000),
        child: Container(
          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(6),
            border: Border.all(color: _focused ? AppTheme.primaryColor : Colors.grey[300]!),
            color: const Color(0xFFF8FAFC),
          ),
          child: Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              Icon(Icons.search, size: 16, color: Colors.grey[500]),
              const SizedBox(width: 8),
              Text('Ctrl+K', style: TextStyle(fontSize: 12, color: Colors.grey[500], fontFamily: 'monospace')),
            ],
          ),
        ),
      ),
    );
  }
}

class _HeaderIconButton extends StatefulWidget {
  final IconData icon;
  final String tooltip;
  final VoidCallback onTap;

  const _HeaderIconButton({
    required this.icon,
    required this.tooltip,
    required this.onTap,
  });

  @override
  State<_HeaderIconButton> createState() => _HeaderIconButtonState();
}

class _HeaderIconButtonState extends State<_HeaderIconButton> {
  bool _hovered = false;

  @override
  Widget build(BuildContext context) {
    return Tooltip(
      message: widget.tooltip,
      child: InkWell(
        onTap: widget.onTap,
        onHover: (h) => setState(() => _hovered = h),
        borderRadius: BorderRadius.circular(8),
        child: Container(
          width: 36,
          height: 36,
          alignment: Alignment.center,
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(8),
            color: _hovered ? const Color(0x0A000000) : Colors.transparent,
          ),
          child: Icon(widget.icon, size: 20, color: const Color(0xFF475569)),
        ),
      ),
    );
  }
}
