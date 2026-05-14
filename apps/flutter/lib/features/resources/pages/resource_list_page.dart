import 'package:flutter/material.dart';
import '../../../core/theme/app_theme.dart';

class ResourceListPage extends StatelessWidget {
  const ResourceListPage({super.key});

  @override
  Widget build(BuildContext context) {
    final isDesktop = AppTheme.isDesktop(context);

    return Padding(
      padding: const EdgeInsets.all(24),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              const Text('My Resources', style: TextStyle(fontSize: 24, fontWeight: FontWeight.w600)),
              const Spacer(),
              FilledButton.icon(
                onPressed: () {},
                icon: const Icon(Icons.add, size: 18),
                label: const Text('New Resource'),
              ),
            ],
          ),
          const SizedBox(height: 24),
          Expanded(
            child: isDesktop ? _buildDesktopTable() : _buildMobileCards(),
          ),
        ],
      ),
    );
  }

  Widget _buildDesktopTable() {
    return Card(
      child: DataTable(
        columns: const [
          DataColumn(label: Text('Resource')),
          DataColumn(label: Text('Type')),
          DataColumn(label: Text('Region')),
          DataColumn(label: Text('Status')),
          DataColumn(label: Text('IP Address')),
          DataColumn(label: Text('Expires'), numeric: true),
          DataColumn(label: Text('Actions')),
        ],
        rows: List.generate(5, (i) {
          final data = [
            ['vm-1001-1', 'Server', 'US East', 'Running', '10.0.0.1', '2026-06-14'],
            ['vm-1001-2', 'Server', 'Europe', 'Running', '10.0.1.1', '2026-07-01'],
            ['ip-1002-1', 'IP', 'Global', 'Active', '192.168.0.1', '-'],
            ['disk-1003-1', 'Disk', 'US East', 'Active', '-', '-'],
            ['domain-1004-1', 'Domain', 'Global', 'Active', '-', '2027-05-14'],
          ];
          final statuses = [Colors.green, Colors.green, Colors.blue, Colors.blue, Colors.blue];
          return DataRow(cells: [
            DataCell(Text(data[i][0])),
            DataCell(Text(data[i][1])),
            DataCell(Text(data[i][2])),
            DataCell(Chip(
              label: Text(data[i][3], style: TextStyle(color: statuses[i], fontSize: 12)),
              backgroundColor: statuses[i].withValues(alpha: 0.1),
              side: BorderSide.none,
              padding: EdgeInsets.zero,
              visualDensity: VisualDensity.compact,
            )),
            DataCell(Text(data[i][4])),
            DataCell(Text(data[i][5])),
            DataCell(Row(
              mainAxisSize: MainAxisSize.min,
              children: [
                IconButton(icon: const Icon(Icons.terminal, size: 18), onPressed: () {}, tooltip: 'Console'),
                IconButton(icon: const Icon(Icons.info_outline, size: 18), onPressed: () {}, tooltip: 'Details'),
              ],
            )),
          ]);
        }),
      ),
    );
  }

  Widget _buildMobileCards() {
    return ListView.builder(
      itemCount: 5,
      itemBuilder: (_, i) => Card(
        child: ListTile(
          leading: const Icon(Icons.dns_outlined),
          title: Text('vm-100${i+1}-${i+1}'),
          subtitle: const Text('US East • Running'),
          trailing: const Icon(Icons.chevron_right),
          onTap: () {},
        ),
      ),
    );
  }
}
