import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import '../../../../core/network/api_service.dart';
import '../../../../core/network/api_client.dart';
import '../../../../core/theme/app_theme.dart';
import '../../../../core/theme/responsive.dart';

class ProductListPage extends StatefulWidget {
  const ProductListPage({super.key});
  @override
  State<ProductListPage> createState() => _ProductListPageState();
}

class _ProductListPageState extends State<ProductListPage> {
  final ApiService _api = ApiService();
  bool _tableView = false;
  final _selected = <int>{};
  String _region = 'all';
  String _typeFilter = 'all';
  List<dynamic> _products = [];
  bool _loading = true;
  String? _error;

  @override
  void initState() {
    super.initState();
    _fetchProducts();
  }

  Future<void> _fetchProducts() async {
    setState(() { _loading = true; _error = null; });
    try {
      final data = await _api.getProducts();
      if (mounted) setState(() { _products = data; _loading = false; });
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
          _buildToolbar(isDesktop),
          const SizedBox(height: 20),

          // Content
          if (_loading)
            const Expanded(child: Center(child: CircularProgressIndicator()))
          else if (_error != null)
            Expanded(child: Center(child: Text('Error: $_error', style: TextStyle(color: Colors.red.shade400))))
          else
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
    return SingleChildScrollView(
      scrollDirection: Axis.horizontal,
      child: Row(
      children: [
        const Text('Products', style: TextStyle(fontSize: 22, fontWeight: FontWeight.w600)),
        const SizedBox(width: 24),
        if (isDesktop) ...[
          ChoiceChip(label: const Text('All'), selected: _typeFilter == 'all', onSelected: (_) => setState(() => _typeFilter = 'all')),
          const SizedBox(width: 8),
          ChoiceChip(label: const Text('Server'), selected: _typeFilter == 'server', onSelected: (_) => setState(() => _typeFilter = 'server')),
          const SizedBox(width: 8),
          ChoiceChip(label: const Text('IP'), selected: _typeFilter == 'ip', onSelected: (_) => setState(() => _typeFilter = 'ip')),
          const SizedBox(width: 8),
          ChoiceChip(label: const Text('Disk'), selected: _typeFilter == 'disk', onSelected: (_) => setState(() => _typeFilter = 'disk')),
          const SizedBox(width: 8),
          ChoiceChip(label: const Text('Domain'), selected: _typeFilter == 'domain', onSelected: (_) => setState(() => _typeFilter = 'domain')),
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
    ));
  }

  Widget _buildGrid(BuildContext context, bool isDesktop) {
    return GridView.builder(
      gridDelegate: SliverGridDelegateWithMaxCrossAxisExtent(
        maxCrossAxisExtent: isDesktop ? 300 : 180,
        childAspectRatio: isDesktop ? 0.82 : 0.88,
        crossAxisSpacing: 16,
        mainAxisSpacing: 16,
      ),
      itemCount: _products.length,
      itemBuilder: (_, idx) {
        final p = _products[idx];
        final i = idx;
        return _ProductCard(
          product: p,
          index: i,
          selected: _selected.contains(i),
          compact: !isDesktop,
          onTap: () => setState(() {
            if (_selected.contains(i)) { _selected.remove(i); } else { _selected.add(i); }
          }),
          onSecondaryTapDown: (d) => _showContextMenu(context, d.globalPosition, i),
        );
      },
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
        rows: _products.asMap().entries.map((entry) {
          final i = entry.key;
          final p = entry.value as Map<String, dynamic>? ?? {};
          final name = (p['name'] ?? p['name_localized'] ?? '').toString();
          final category = (p['category'] is Map ? p['category']['name'] ?? 'vps' : 'vps').toString();
          final price = _lowestPrice(p);
          return DataRow(
            key: ValueKey(i),
            selected: _selected.contains(i),
            onSelectChanged: (v) => setState(() {
              if (v == true) { _selected.add(i); } else { _selected.remove(i); }
            }),
            cells: [
              DataCell(Text(name, style: const TextStyle(fontWeight: FontWeight.w500))),
              DataCell(Chip(
                label: Text(category.toUpperCase(), style: const TextStyle(fontSize: 11)),
                backgroundColor: AppTheme.primaryColor.withValues(alpha: 0.08),
                side: BorderSide.none,
                padding: EdgeInsets.zero,
                visualDensity: VisualDensity.compact,
              )),
              DataCell(Text(category)),
              DataCell(Text(price, style: const TextStyle(fontWeight: FontWeight.w600))),
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
                    onPressed: () {
                      final renderBox = context.findRenderObject() as RenderBox;
                      final pos = renderBox.localToGlobal(Offset.zero);
                      _showContextMenu(context, pos, i);
                    },
                    tooltip: 'More actions',
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

  /// Find the lowest price across all SKUs and regions.
  String _lowestPrice(dynamic product) {
    double? lowest;
    final skus = product['skus'] as List<dynamic>? ?? [];
    for (final sku in skus) {
      final prices = sku['region_prices'] as List<dynamic>? ?? [];
      for (final rp in prices) {
        final p = double.tryParse((rp['price'] ?? '').toString());
        if (p != null && (lowest == null || p < lowest)) lowest = p;
      }
    }
    return lowest != null ? '\$${lowest.toStringAsFixed(2)}' : '\$--';
  }

  void _showContextMenu(BuildContext context, Offset position, int index) {
    showMenu<String>(
      context: context,
      position: RelativeRect.fromLTRB(position.dx, position.dy, position.dx + 1, position.dy + 1),
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

class _ProductCard extends StatefulWidget {
  final dynamic product;
  final int index;
  final bool selected;
  final bool compact;
  final VoidCallback onTap;
  final void Function(TapDownDetails) onSecondaryTapDown;

  const _ProductCard({
    required this.product,
    required this.index,
    required this.selected,
    required this.compact,
    required this.onTap,
    required this.onSecondaryTapDown,
  });

  @override
  State<_ProductCard> createState() => _ProductCardState();
}

class _ProductCardState extends State<_ProductCard> {
  bool _hovered = false;

  @override
  Widget build(BuildContext context) {
    final p = widget.product as Map<String, dynamic>? ?? {};
    final name = (p['name'] ?? p['name_localized'] ?? 'Unknown').toString();
    final price = _lowestPrice(p);
    final category = p['category'] is Map ? (p['category']['name'] ?? '') : '';

    return MouseRegion(
      onEnter: (_) => setState(() => _hovered = true),
      onExit: (_) => setState(() => _hovered = false),
      child: GestureDetector(
        onSecondaryTapDown: widget.onSecondaryTapDown,
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
                        name,
                        style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 15),
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                      ),
                      const SizedBox(height: 4),
                      Row(
                        children: [
                          Icon(Icons.location_on_outlined, size: 13, color: Colors.grey[500]),
                          const SizedBox(width: 4),
                          Text(category, style: TextStyle(color: Colors.grey[500], fontSize: 12)),
                        ],
                      ),
                      const SizedBox(height: 10),
                      Row(
                        mainAxisAlignment: MainAxisAlignment.spaceBetween,
                        children: [
                          Text(
                            price,
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
