import 'package:flutter/material.dart';
import '../../../../core/theme/app_theme.dart';
import '../../../../core/theme/responsive.dart';

class ResourceListPage extends StatefulWidget {
  const ResourceListPage({super.key});

  @override
  State<ResourceListPage> createState() => _ResourceListPageState();
}

class _ResourceListPageState extends State<ResourceListPage> {
  final _selected = <int>{};
  int? _sortColumn;
  bool _sortAsc = true;
  String _statusFilter = 'all';

  static const _data = [
    ['vm-1001-1', 'Server', 'US East', 'Running', '10.0.0.1', '2026-06-14'],
    ['vm-1001-2', 'Server', 'Europe', 'Running', '10.0.1.1', '2026-07-01'],
    ['ip-1002-1', 'IP', 'Global', 'Active', '192.168.0.1', '-'],
    ['disk-1003-1', 'Disk', 'US East', 'Active', '-', '-'],
    ['domain-1004-1', 'Domain', 'Global', 'Active', '-', '2027-05-14'],
  ];

  @override
  Widget build(BuildContext context) {
    final isDesktop = AppTheme.isDesktop(context);

    return Padding(
      padding: EdgeInsets.all(ResponsiveBreakpoints.contentPadding(context)),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          // Toolbar
          Row(
            children: [
              const Text('My Resources', style: TextStyle(fontSize: 22, fontWeight: FontWeight.w600)),
              const Spacer(),
              if (isDesktop) ...[
                _StatusChip(label: 'All', value: 'all', current: _statusFilter, onTap: () => setState(() => _statusFilter = 'all')),
                const SizedBox(width: 8),
                _StatusChip(label: 'Running', value: 'Running', current: _statusFilter, onTap: () => setState(() => _statusFilter = 'Running')),
                const SizedBox(width: 8),
                _StatusChip(label: 'Active', value: 'Active', current: _statusFilter, onTap: () => setState(() => _statusFilter = 'Active')),
                const SizedBox(width: 16),
              ],
              FilledButton.icon(
                onPressed: () {},
                icon: const Icon(Icons.add, size: 18),
                label: const Text('New Resource'),
              ),
            ],
          ),
          const SizedBox(height: 20),

          // Content
          Expanded(
            child: isDesktop ? _buildDesktopTable() : _buildMobileCards(),
          ),

          // Bulk actions
          if (_selected.isNotEmpty) _buildBulkActionBar(),
        ],
      ),
    );
  }

  Widget _buildDesktopTable() {
    return Card(
      child: DataTable(
        sortColumnIndex: _sortColumn,
        sortAscending: _sortAsc,
        showCheckboxColumn: true,
        dataRowMinHeight: 44,
        headingRowHeight: 46,
        columns: [
          DataColumn(label: const Text('Resource'), onSort: (c, _) => setState(() { _sortColumn = c; _sortAsc = !_sortAsc; })),
          DataColumn(label: const Text('Type'), onSort: (c, _) => setState(() { _sortColumn = c; _sortAsc = !_sortAsc; })),
          DataColumn(label: const Text('Region'), onSort: (c, _) => setState(() { _sortColumn = c; _sortAsc = !_sortAsc; })),
          DataColumn(label: const Text('Status'), onSort: (c, _) => setState(() { _sortColumn = c; _sortAsc = !_sortAsc; })),
          const DataColumn(label: Text('IP Address')),
          DataColumn(label: const Text('Expires'), numeric: true, onSort: (c, _) => setState(() { _sortColumn = c; _sortAsc = !_sortAsc; })),
          const DataColumn(label: Text('Actions')),
        ],
        rows: List.generate(_data.length, (i) {
          final row = _data[i];
          final statusColor = _statusColor(row[3]);
          final selected = _selected.contains(i);
          return DataRow(
            selected: selected,
            onSelectChanged: (v) => setState(() {
              if (v == true) { _selected.add(i); } else { _selected.remove(i); }
            }),
            cells: [
              DataCell(Text(row[0], style: const TextStyle(fontWeight: FontWeight.w500))),
              DataCell(Text(row[1])),
              DataCell(Text(row[2])),
              DataCell(Chip(
                label: Text(row[3], style: TextStyle(color: statusColor, fontSize: 12)),
                backgroundColor: statusColor.withValues(alpha: 0.1),
                side: BorderSide.none,
                padding: EdgeInsets.zero,
                visualDensity: VisualDensity.compact,
              )),
              DataCell(Text(row[4])),
              DataCell(Text(row[5])),
              DataCell(Row(
                mainAxisSize: MainAxisSize.min,
                children: [
                  IconButton(
                    icon: const Icon(Icons.terminal, size: 18),
                    onPressed: () {},
                    tooltip: 'Console',
                    visualDensity: VisualDensity.compact,
                  ),
                  IconButton(
                    icon: const Icon(Icons.info_outline, size: 18),
                    onPressed: () {},
                    tooltip: 'Details',
                    visualDensity: VisualDensity.compact,
                  ),
                  IconButton(
                    icon: const Icon(Icons.more_horiz, size: 18),
                    onPressed: () => _showContextMenu(context, i),
                    tooltip: 'More',
                    visualDensity: VisualDensity.compact,
                  ),
                ],
              )),
            ],
          );
        }),
      ),
    );
  }

  Widget _buildMobileCards() {
    return ListView.builder(
      itemCount: _data.length,
      itemBuilder: (_, i) => Card(
        child: ListTile(
          leading: const Icon(Icons.dns_outlined),
          title: Text(_data[i][0]),
          subtitle: Text('${_data[i][2]} • ${_data[i][3]}'),
          trailing: const Icon(Icons.chevron_right),
          onTap: () {},
        ),
      ),
    );
  }

  Widget _buildBulkActionBar() {
    return Container(
      margin: const EdgeInsets.only(top: 12),
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
      decoration: BoxDecoration(
        color: AppTheme.primaryColor.withValues(alpha: 0.95),
        borderRadius: BorderRadius.circular(8),
      ),
      child: Row(
        children: [
          Text('${_selected.length} selected', style: const TextStyle(color: Colors.white, fontSize: 14)),
          const Spacer(),
          TextButton.icon(
            onPressed: () => setState(() => _selected.clear()),
            icon: const Icon(Icons.close, size: 16, color: Colors.white70),
            label: const Text('Clear', style: TextStyle(color: Colors.white70)),
          ),
          const SizedBox(width: 12),
          FilledButton.icon(
            onPressed: () {},
            icon: const Icon(Icons.restart_alt, size: 16),
            label: const Text('Reboot'),
            style: FilledButton.styleFrom(backgroundColor: Colors.white, foregroundColor: AppTheme.primaryColor),
          ),
        ],
      ),
    );
  }

  void _showContextMenu(BuildContext context, int index) {
    showMenu<String>(
      context: context,
      position: RelativeRect.fromLTRB(400, 300, 0, 0),
      items: <PopupMenuEntry<String>>[
        PopupMenuItem<String>(value: 'console', child: _menuRow(Icons.terminal, 'Open Console')),
        PopupMenuItem<String>(value: 'details', child: _menuRow(Icons.info_outline, 'View Details')),
        PopupMenuItem<String>(value: 'reboot', child: _menuRow(Icons.restart_alt, 'Reboot')),
        const PopupMenuDivider(),
        PopupMenuItem<String>(value: 'delete', child: _menuRow(Icons.delete_outline, 'Delete')),
      ],
    );
  }

  Widget _menuRow(IconData icon, String text) {
    return Row(children: [Icon(icon, size: 18), const SizedBox(width: 12), Text(text)]);
  }

  Color _statusColor(String status) {
    switch (status) {
      case 'Running': return Colors.green;
      case 'Active': return Colors.blue;
      default: return Colors.grey;
    }
  }
}

class _StatusChip extends StatelessWidget {
  final String label;
  final String value;
  final String current;
  final VoidCallback onTap;
  const _StatusChip({required this.label, required this.value, required this.current, required this.onTap});

  @override
  Widget build(BuildContext context) {
    final active = value == current;
    return ChoiceChip(
      label: Text(label),
      selected: active,
      onSelected: (_) => onTap(),
    );
  }
}
