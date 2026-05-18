import 'package:flutter/material.dart';
import '../../../../core/theme/app_theme.dart';
import '../../../../core/theme/responsive.dart';


class ProductListPage extends StatefulWidget {
  const ProductListPage({super.key});

  @override
  State<ProductListPage> createState() => _ProductListPageState();
}

class _ProductListPageState extends State<ProductListPage> {
  bool _tableView = false;
  final _selected = <int>{};
  String _region = 'all';

  static const _names = [
    'Cloud VPS Basic', 'Cloud VPS Pro', 'Dedicated Server',
    'IPv4 Address', 'SSD Block Storage', 'Domain Name',
    'Load Balancer', 'CDN Accelerator', 'Object Storage',
    'Kubernetes Cluster', 'Managed Database', 'Firewall',
  ];
  static const _regions = ['US East', 'Europe', 'Asia Pacific', 'Global', 'US East', 'Global', 'US East', 'Europe', 'Asia Pacific', 'Global', 'US East', 'Europe'];
  static const _prices = ['\$5.00', '\$20.00', '\$99.00', '\$3.00', '\$10.00', '\$12.99', '\$25.00', '\$15.00', '\$8.00', '\$120.00', '\$45.00', '\$30.00'];
  static const _types = ['vps', 'vps', 'server', 'ip', 'disk', 'domain', 'lb', 'cdn', 'storage', 'k8s', 'db', 'fw'];

  @override
  Widget build(BuildContext context) {
    final isDesktop = AppTheme.isDesktop(context);

    return Padding(
      padding: EdgeInsets.all(ResponsiveBreakpoints.contentPadding(context)),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          // Toolbar
          _buildToolbar(isDesktop),
          const SizedBox(height: 20),

          // Content
          Expanded(
            child: _tableView && isDesktop ? _buildDataTable() : _buildGrid(context, isDesktop),
          ),

          // Selection action bar
          if (_selected.isNotEmpty) _buildSelectionBar(),
        ],
      ),
    );
  }

  Widget _buildToolbar(bool isDesktop) {
    return Row(
      children: [
        const Text('Products', style: TextStyle(fontSize: 22, fontWeight: FontWeight.w600)),
        const SizedBox(width: 16),
        if (isDesktop) ...[
          const Spacer(),
          _FilterChip(label: 'All', selected: true),
          const SizedBox(width: 8),
          _FilterChip(label: 'Server', selected: false),
          const SizedBox(width: 8),
          _FilterChip(label: 'IP', selected: false),
          const SizedBox(width: 8),
          _FilterChip(label: 'Disk', selected: false),
          const SizedBox(width: 8),
          _FilterChip(label: 'Domain', selected: false),
          const SizedBox(width: 16),
          DropdownButton<String>(
            value: _region,
            items: const [
              DropdownMenuItem(value: 'all', child: Text('All Regions')),
              DropdownMenuItem(value: 'us', child: Text('US East')),
              DropdownMenuItem(value: 'eu', child: Text('Europe')),
              DropdownMenuItem(value: 'ap', child: Text('Asia Pacific')),
            ],
            onChanged: (v) => setState(() => _region = v ?? 'all'),
            underline: const SizedBox.shrink(),
          ),
          const SizedBox(width: 12),
          // Table / grid toggle
          SegmentedButton<bool>(
            segments: const [
              ButtonSegment(value: false, icon: Icon(Icons.grid_view_rounded, size: 18)),
              ButtonSegment(value: true, icon: Icon(Icons.table_rows_rounded, size: 18)),
            ],
            selected: {_tableView},
            onSelectionChanged: (v) => setState(() => _tableView = v.first),
            style: ButtonStyle(
              visualDensity: VisualDensity.compact,
              tapTargetSize: MaterialTapTargetSize.shrinkWrap,
            ),
          ),
        ],
      ],
    );
  }

  Widget _buildGrid(BuildContext context, bool isDesktop) {
    return GridView.builder(
      gridDelegate: SliverGridDelegateWithMaxCrossAxisExtent(
        maxCrossAxisExtent: isDesktop ? 300 : 180,
        childAspectRatio: isDesktop ? 0.82 : 0.88,
        crossAxisSpacing: 16,
        mainAxisSpacing: 16,
      ),
      itemCount: 12,
      itemBuilder: (_, i) => _ProductCard(
        index: i,
        selected: _selected.contains(i),
        compact: !isDesktop,
        onTap: () => setState(() {
          if (_selected.contains(i)) { _selected.remove(i); } else { _selected.add(i); }
        }),
        onContextMenu: () => _showContextMenu(context, i),
      ),
    );
  }

  Widget _buildDataTable() {
    return Card(
      child: DataTable(
        showCheckboxColumn: true,
        sortColumnIndex: 0,
        dataRowMinHeight: 44,
        columns: const [
          DataColumn(label: Text('Name')),
          DataColumn(label: Text('Type'), numeric: false),
          DataColumn(label: Text('Region')),
          DataColumn(label: Text('Price'), numeric: true),
          DataColumn(label: Text('')),
        ],
        rows: List.generate(12, (i) => DataRow(
          selected: _selected.contains(i),
          onSelectChanged: (v) => setState(() {
            if (v == true) { _selected.add(i); } else { _selected.remove(i); }
          }),
          cells: [
            DataCell(Text(_names[i], style: const TextStyle(fontWeight: FontWeight.w500))),
            DataCell(Chip(
              label: Text(_types[i].toUpperCase(), style: const TextStyle(fontSize: 11)),
              backgroundColor: AppTheme.primaryColor.withValues(alpha: 0.08),
              side: BorderSide.none,
              padding: EdgeInsets.zero,
              visualDensity: VisualDensity.compact,
            )),
            DataCell(Text(_regions[i])),
            DataCell(Text(_prices[i], style: const TextStyle(fontWeight: FontWeight.w600))),
            DataCell(Row(
              mainAxisSize: MainAxisSize.min,
              children: [
                IconButton(
                  icon: const Icon(Icons.shopping_cart_outlined, size: 18),
                  onPressed: () {},
                  tooltip: 'Add to cart',
                  visualDensity: VisualDensity.compact,
                ),
                IconButton(
                  icon: const Icon(Icons.more_horiz, size: 18),
                  onPressed: () => _showContextMenu(context, i),
                  tooltip: 'More actions',
                  visualDensity: VisualDensity.compact,
                ),
              ],
            )),
          ],
        )),
      ),
    );
  }

  Widget _buildSelectionBar() {
    return Container(
      margin: const EdgeInsets.only(top: 12),
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
      decoration: BoxDecoration(
        color: AppTheme.primaryColor.withValues(alpha: 0.95),
        borderRadius: BorderRadius.circular(8),
        boxShadow: [
          BoxShadow(color: Colors.black.withValues(alpha: 0.15), blurRadius: 8, offset: const Offset(0, 4)),
        ],
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
          const SizedBox(width: 8),
          FilledButton.icon(
            onPressed: () {},
            icon: const Icon(Icons.shopping_cart, size: 16),
            label: const Text('Add to Cart'),
            style: FilledButton.styleFrom(
              backgroundColor: Colors.white,
              foregroundColor: AppTheme.primaryColor,
            ),
          ),
        ],
      ),
    );
  }

  void _showContextMenu(BuildContext context, int index) {
    showMenu<String>(
      context: context,
      position: RelativeRect.fromLTRB(200, 200, 0, 0),
      items: <PopupMenuEntry<String>>[
        PopupMenuItem<String>(value: 'view', child: _MenuRow(Icons.info_outline, 'View Details')),
        PopupMenuItem<String>(value: 'cart', child: _MenuRow(Icons.shopping_cart_outlined, 'Add to Cart')),
        PopupMenuItem<String>(value: 'compare', child: _MenuRow(Icons.compare_arrows, 'Compare')),
        const PopupMenuDivider(),
        PopupMenuItem<String>(value: 'share', child: _MenuRow(Icons.share, 'Share')),
      ],
    );
  }
}

class _MenuRow extends StatelessWidget {
  final IconData icon;
  final String text;
  const _MenuRow(this.icon, this.text);

  @override
  Widget build(BuildContext context) {
    return Row(
      children: [
        Icon(icon, size: 18),
        const SizedBox(width: 12),
        Text(text),
      ],
    );
  }
}

class _FilterChip extends StatelessWidget {
  final String label;
  final bool selected;
  const _FilterChip({required this.label, required this.selected});

  @override
  Widget build(BuildContext context) {
    return ChoiceChip(
      label: Text(label),
      selected: selected,
      onSelected: (_) {},
    );
  }
}

class _ProductCard extends StatefulWidget {
  final int index;
  final bool selected;
  final bool compact;
  final VoidCallback onTap;
  final VoidCallback onContextMenu;

  const _ProductCard({
    required this.index,
    required this.selected,
    required this.compact,
    required this.onTap,
    required this.onContextMenu,
  });

  @override
  State<_ProductCard> createState() => _ProductCardState();
}

class _ProductCardState extends State<_ProductCard> {
  bool _hovered = false;

  @override
  Widget build(BuildContext context) {
    final names = ['Cloud VPS Basic', 'Cloud VPS Pro', 'Dedicated Server', 'IPv4 Address', 'SSD Block Storage', 'com'];
    final regions = ['US East', 'Europe', 'Asia Pacific', 'Global', 'US East', 'Global'];
    final prices = ['\$5.00', '\$20.00', '\$99.00', '\$3.00', '\$10.00', '\$12.99'];

    return MouseRegion(
      onEnter: (_) => setState(() => _hovered = true),
      onExit: (_) => setState(() => _hovered = false),
      child: GestureDetector(
        onSecondaryTap: widget.onContextMenu,
        child: Card(
          clipBehavior: Clip.antiAlias,
          elevation: _hovered ? 2 : 0,
          color: widget.selected ? AppTheme.primaryColor.withValues(alpha: 0.04) : null,
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(8),
            side: BorderSide(
              color: widget.selected ? AppTheme.primaryColor : (_hovered ? AppTheme.primaryColor.withValues(alpha: 0.3) : AppTheme.cardBorder),
              width: widget.selected ? 1.5 : 1,
            ),
          ),
          child: InkWell(
            onTap: widget.onTap,
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Container(
                  height: widget.compact ? 80 : 130,
                  color: const Color(0xFFF8FAFC),
                  child: Center(
                    child: Icon(
                      Icons.dns_outlined,
                      size: widget.compact ? 36 : 48,
                      color: _hovered ? AppTheme.primaryColor.withValues(alpha: 0.5) : const Color(0xFF94A3B8),
                    ),
                  ),
                ),
                Padding(
                  padding: EdgeInsets.all(widget.compact ? 12 : 16),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        names[widget.index % names.length],
                        style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 15),
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                      ),
                      const SizedBox(height: 4),
                      Row(
                        children: [
                          Icon(Icons.location_on_outlined, size: 13, color: Colors.grey[500]),
                          const SizedBox(width: 4),
                          Text(regions[widget.index % regions.length],
                               style: TextStyle(color: Colors.grey[500], fontSize: 12)),
                        ],
                      ),
                      const SizedBox(height: 10),
                      Row(
                        mainAxisAlignment: MainAxisAlignment.spaceBetween,
                        children: [
                          Text(
                            prices[widget.index % prices.length],
                            style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w700, color: AppTheme.primaryColor),
                          ),
                          Text('/mo', style: TextStyle(color: Colors.grey[400], fontSize: 12)),
                        ],
                      ),
                    ],
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
