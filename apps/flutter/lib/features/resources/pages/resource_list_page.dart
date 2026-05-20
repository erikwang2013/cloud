import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import '../../../../core/network/api_service.dart';
import '../../../../core/theme/app_theme.dart';
import '../../../../core/theme/responsive.dart';

class ResourceListPage extends StatefulWidget {
  const ResourceListPage({super.key});
  @override
  State<ResourceListPage> createState() => _ResourceListPageState();
}

class _ResourceListPageState extends State<ResourceListPage> {
  final ApiService _api = ApiService();
  final _selected = <int>{};
  int? _sortColumn;
  bool _sortAsc = true;
  String _statusFilter = 'all';
  List<dynamic> _resources = [];
  bool _loading = true;
  String? _error;

  @override
  void initState() {
    super.initState();
    _fetchResources();
  }

  Future<void> _fetchResources() async {
    setState(() { _loading = true; _error = null; });
    try {
      final data = await _api.getResources(status: _statusFilter == 'all' ? null : _statusFilter);
      if (mounted) setState(() { _resources = data; _loading = false; });
    } on DioException catch (e) {
      if (mounted) setState(() { _error = e.message; _loading = false; });
    }
  }

  @override
  Widget build(BuildContext context) {
    final isDesktop = AppTheme.isDesktop(context);

    return Padding(
      padding: EdgeInsets.all(ResponsiveBreakpoints.contentPadding(context)),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          // Toolbar
          SingleChildScrollView(
            scrollDirection: Axis.horizontal,
            child: Row(
              children: [
                const Text('My Resources', style: TextStyle(fontSize: 22, fontWeight: FontWeight.w600)),
                const SizedBox(width: 24),
                if (isDesktop) ...[
                  _StatusChip(label: 'All', value: 'all', current: _statusFilter, onTap: () => { setState(() => _statusFilter = 'all'), _fetchResources() }),
                  const SizedBox(width: 8),
                  _StatusChip(label: 'Active', value: 'active', current: _statusFilter, onTap: () => { setState(() => _statusFilter = 'active'), _fetchResources() }),
                  const SizedBox(width: 8),
                  _StatusChip(label: 'Stopped', value: 'stopped', current: _statusFilter, onTap: () => { setState(() => _statusFilter = 'stopped'), _fetchResources() }),
                  const SizedBox(width: 16),
                ],
                FilledButton.icon(
                  onPressed: () {},
                  icon: const Icon(Icons.add, size: 18),
                  label: const Text('New Resource'),
                ),
              ],
            ),
          ),
          const SizedBox(height: 20),

          // Content
          if (_loading)
            const Expanded(child: Center(child: CircularProgressIndicator()))
          else if (_error != null)
            Expanded(child: Center(child: Text('Error: $_error', style: TextStyle(color: Colors.red.shade400))))
          else
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
        rows: _resources.asMap().entries.map((e) {
          final i = e.key;
          final r = e.value as Map<String, dynamic>? ?? {};
          final id = (r['id'] ?? '').toString();
          final type = (r['type'] ?? 'server').toString();
          final status = (r['status'] ?? 'unknown').toString();
          final region = (r['region'] is Map ? r['region']['name'] ?? '' : '').toString();
          final ip = (r['server'] is Map ? r['server']['ip_address'] ?? '' : (r['ip'] is Map ? r['ip']['ip_address'] ?? '' : '')).toString();
          final expires = (r['expired_at'] ?? 'N/A').toString();
          final statusColor = _statusColor(status);
          final selected = _selected.contains(i);
          return DataRow(
            selected: selected,
            onSelectChanged: (v) => setState(() {
              if (v == true) { _selected.add(i); } else { _selected.remove(i); }
            }),
            cells: [
              DataCell(Text(id, style: const TextStyle(fontWeight: FontWeight.w500))),
              DataCell(Text(type)),
              DataCell(Text(region)),
              DataCell(Chip(
                label: Text(status, style: TextStyle(color: statusColor, fontSize: 12)),
                backgroundColor: statusColor.withValues(alpha: 0.1),
                side: BorderSide.none,
                padding: EdgeInsets.zero,
                visualDensity: VisualDensity.compact,
              )),
              DataCell(Text(ip)),
              DataCell(Text(expires)),
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
                    onPressed: () {
                      final renderBox = context.findRenderObject() as RenderBox;
                      final pos = renderBox.localToGlobal(Offset.zero);
                      _showContextMenu(context, pos, i);
                    },
                    tooltip: 'More',
                    visualDensity: VisualDensity.compact,
                  ),
                ],
              )),
            ],
          );
        }).toList(),
      ),
    );
  }

  Widget _buildMobileCards() {
    return ListView.builder(
      itemCount: _resources.length,
      itemBuilder: (_, i) {
        final r = _resources[i] as Map<String, dynamic>? ?? {};
        final id = (r['id'] ?? '').toString();
        final type = (r['type'] ?? '').toString();
        final status = (r['status'] ?? '').toString();
        return Card(
          child: ListTile(
            leading: const Icon(Icons.dns_outlined),
            title: Text(id),
            subtitle: Text('$type • $status'),
            trailing: const Icon(Icons.chevron_right),
            onTap: () {},
          ),
        );
      },
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

  void _showContextMenu(BuildContext context, Offset position, int index) {
    showMenu<String>(
      context: context,
      position: RelativeRect.fromLTRB(position.dx, position.dy, position.dx + 1, position.dy + 1),
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
    switch (status.toLowerCase()) {
      case 'active': case 'running': return Colors.green;
      case 'provisioning': case 'pending': return Colors.orange;
      case 'stopped': case 'destroyed': return Colors.red;
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
