import 'package:flutter/material.dart';

class _CommandItem {
  final String id;
  final String title;
  final String category;
  final IconData icon;
  final VoidCallback onSelect;

  const _CommandItem({
    required this.id,
    required this.title,
    required this.category,
    required this.icon,
    required this.onSelect,
  });
}

class CommandPalette extends StatefulWidget {
  final List<_CommandItem> commands;

  const CommandPalette({super.key, required this.commands});

  /// Show the command palette as an overlay.
  static void show(BuildContext context, {List<_CommandItem> commands = const []}) {
    final items = commands.isEmpty ? _defaultCommands(context) : commands;
    showDialog(
      context: context,
      builder: (_) => CommandPalette(commands: items),
      barrierColor: Colors.black38,
    );
  }

  static List<_CommandItem> _defaultCommands(BuildContext context) => [
    _CommandItem(
      id: 'products',
      title: 'Products',
      category: 'Navigation',
      icon: Icons.computer_rounded,
      onSelect: () => Navigator.of(context).pop(),
    ),
    _CommandItem(
      id: 'cart',
      title: 'Shopping Cart',
      category: 'Navigation',
      icon: Icons.shopping_cart_outlined,
      onSelect: () => Navigator.of(context).pop(),
    ),
    _CommandItem(
      id: 'resources',
      title: 'My Resources',
      category: 'Navigation',
      icon: Icons.dns_outlined,
      onSelect: () => Navigator.of(context).pop(),
    ),
    _CommandItem(
      id: 'tickets',
      title: 'Support Tickets',
      category: 'Navigation',
      icon: Icons.support_agent_outlined,
      onSelect: () => Navigator.of(context).pop(),
    ),
    _CommandItem(
      id: 'profile',
      title: 'Profile Settings',
      category: 'Navigation',
      icon: Icons.person_outline,
      onSelect: () => Navigator.of(context).pop(),
    ),
    _CommandItem(
      id: 'new_resource',
      title: 'New Resource',
      category: 'Actions',
      icon: Icons.add,
      onSelect: () => Navigator.of(context).pop(),
    ),
    _CommandItem(
      id: 'theme_toggle',
      title: 'Toggle Dark Mode',
      category: 'Actions',
      icon: Icons.dark_mode,
      onSelect: () => Navigator.of(context).pop(),
    ),
    _CommandItem(
      id: 'settings',
      title: 'Settings',
      category: 'Actions',
      icon: Icons.settings,
      onSelect: () => Navigator.of(context).pop(),
    ),
  ];

  @override
  State<CommandPalette> createState() => _CommandPaletteState();
}

class _CommandPaletteState extends State<CommandPalette> {
  final _controller = TextEditingController();
  final _focusNode = FocusNode();
  int _selectedIndex = 0;
  String _query = '';
  late List<_CommandItem> _filtered;

  @override
  void initState() {
    super.initState();
    _filtered = widget.commands;
    _controller.addListener(() => setState(() {
      _query = _controller.text;
      _selectedIndex = 0;
      _filtered = _query.isEmpty
          ? widget.commands
          : widget.commands.where((c) =>
              c.title.toLowerCase().contains(_query.toLowerCase()) ||
              c.category.toLowerCase().contains(_query.toLowerCase())).toList();
    }));
  }

  @override
  void dispose() {
    _controller.dispose();
    _focusNode.dispose();
    super.dispose();
  }

  void _execute(int index) {
    if (index >= 0 && index < _filtered.length) {
      Navigator.of(context).pop();
      _filtered[index].onSelect();
    }
  }

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Container(
        width: 520,
        constraints: const BoxConstraints(maxHeight: 420),
        decoration: BoxDecoration(
          borderRadius: BorderRadius.circular(12),
          color: Theme.of(context).scaffoldBackgroundColor,
          boxShadow: [
            BoxShadow(
              color: Colors.black.withValues(alpha: 0.2),
              blurRadius: 24,
              offset: const Offset(0, 8),
            ),
          ],
        ),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            // Search input
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
              decoration: BoxDecoration(
                border: Border(
                  bottom: BorderSide(
                    color: _filtered.isNotEmpty
                        ? Theme.of(context).dividerColor
                        : Colors.transparent,
                  ),
                ),
              ),
              child: Row(
                children: [
                  Icon(Icons.search, size: 20, color: Colors.grey[500]),
                  const SizedBox(width: 12),
                  Expanded(
                    child: TextField(
                      controller: _controller,
                      focusNode: _focusNode,
                      autofocus: true,
                      decoration: const InputDecoration(
                        hintText: 'Type a command...',
                        border: InputBorder.none,
                        isDense: true,
                        contentPadding: EdgeInsets.zero,
                      ),
                      onSubmitted: (_) => _execute(_selectedIndex),
                    ),
                  ),
                ],
              ),
            ),

            // Results list
            Flexible(
              child: _filtered.isEmpty
                  ? Padding(
                      padding: const EdgeInsets.all(32),
                      child: Text('No results', style: TextStyle(color: Colors.grey[500])),
                    )
                  : ListView.builder(
                      padding: const EdgeInsets.symmetric(vertical: 8),
                      shrinkWrap: true,
                      itemCount: _filtered.length,
                      itemBuilder: (_, i) => _CommandTile(
                        item: _filtered[i],
                        selected: i == _selectedIndex,
                        onTap: () => _execute(i),
                        onHover: () => setState(() => _selectedIndex = i),
                      ),
                    ),
            ),

            // Footer hint
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
              decoration: BoxDecoration(
                border: Border(
                  top: BorderSide(color: Theme.of(context).dividerColor),
                ),
              ),
              child: Row(
                children: [
                  _KbdHint('↑↓'),
                  const Text(' navigate', style: TextStyle(fontSize: 12, color: Colors.grey)),
                  const SizedBox(width: 16),
                  _KbdHint('↵'),
                  const Text(' select', style: TextStyle(fontSize: 12, color: Colors.grey)),
                  const Spacer(),
                  _KbdHint('Esc'),
                  const Text(' close', style: TextStyle(fontSize: 12, color: Colors.grey)),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _CommandTile extends StatelessWidget {
  final _CommandItem item;
  final bool selected;
  final VoidCallback onTap;
  final VoidCallback onHover;

  const _CommandTile({
    required this.item,
    required this.selected,
    required this.onTap,
    required this.onHover,
  });

  @override
  Widget build(BuildContext context) {
    return InkWell(
      onTap: onTap,
      onHover: (_) => onHover(),
      child: Container(
        color: selected
            ? Theme.of(context).colorScheme.primary.withValues(alpha: 0.1)
            : Colors.transparent,
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
        child: Row(
          children: [
            Container(
              width: 36,
              height: 36,
              decoration: BoxDecoration(
                color: Theme.of(context).colorScheme.primary.withValues(alpha: 0.08),
                borderRadius: BorderRadius.circular(6),
              ),
              child: Icon(item.icon, size: 20),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: Text(
                item.title,
                style: const TextStyle(fontSize: 14, fontWeight: FontWeight.w500),
              ),
            ),
            Text(
              item.category,
              style: TextStyle(fontSize: 11, color: Colors.grey[500]),
            ),
          ],
        ),
      ),
    );
  }
}

class _KbdHint extends StatelessWidget {
  final String kbdKey;
  const _KbdHint(this.kbdKey);

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 5, vertical: 2),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(4),
        border: Border.all(color: Colors.grey[400]!),
        color: Colors.grey[100],
      ),
      child: Text(
        kbdKey,
        style: TextStyle(fontSize: 11, color: Colors.grey[700], fontFamily: 'monospace'),
      ),
    );
  }
}
