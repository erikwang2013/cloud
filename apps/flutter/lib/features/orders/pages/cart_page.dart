import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import '../../../../core/network/api_service.dart';
import '../../../../core/theme/app_theme.dart';
import '../../../../core/theme/responsive.dart';

class CartPage extends StatefulWidget {
  const CartPage({super.key});
  @override
  State<CartPage> createState() => _CartPageState();
}

class _CartPageState extends State<CartPage> {
  final ApiService _api = ApiService();
  List<dynamic> _items = [];
  Map<String, dynamic>? _summary;
  bool _loading = true;
  String? _error;

  @override
  void initState() {
    super.initState();
    _fetchCart();
  }

  Future<void> _fetchCart() async {
    setState(() { _loading = true; _error = null; });
    try {
      final data = await _api.getCart();
      if (mounted) setState(() {
        _items = data['items'] as List<dynamic>? ?? [];
        _summary = data['summary'] as Map<String, dynamic>?;
        _loading = false;
      });
    } on DioException catch (e) {
      if (mounted) setState(() { _error = e.message; _loading = false; });
    }
  }

  Future<void> _removeItem(int id) async {
    try { await _api.removeFromCart(id); _fetchCart(); }
    catch (_) {}
  }

  @override
  Widget build(BuildContext context) {
    final isDesktop = AppTheme.isDesktop(context);

    return Padding(
      padding: EdgeInsets.all(ResponsiveBreakpoints.contentPadding(context)),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Text('Shopping Cart', style: TextStyle(fontSize: 22, fontWeight: FontWeight.w600)),
          const SizedBox(height: 20),
          if (_loading)
            const Expanded(child: Center(child: CircularProgressIndicator()))
          else if (_error != null)
            Expanded(child: Center(child: Text('Error: $_error', style: TextStyle(color: Colors.red.shade400))))
          else if (_items.isEmpty)
            const Expanded(child: Center(child: Text('Your cart is empty', style: TextStyle(color: Colors.grey))))
          else
          Expanded(
            child: isDesktop ? _buildDesktopCart() : _buildMobileCart(),
          ),
        ],
      ),
    );
  }

  Widget _buildDesktopCart() {
    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Expanded(
          flex: 3,
          child: Card(
            child: SingleChildScrollView(
              child: DataTable(
                  dataRowMinHeight: 44,
                  columns: const [
                    DataColumn(label: Text('Product')),
                    DataColumn(label: Text('Region')),
                    DataColumn(label: Text('Cycle')),
                    DataColumn(label: Text('Quantity')),
                    DataColumn(label: Text('Price'), numeric: true),
                    DataColumn(label: Text('')),
                  ],
                  rows: _items.asMap().entries.map((entry) {
                    final i = entry.key;
                    final item = entry.value as Map<String, dynamic>? ?? {};
                    final name = (item['product'] is Map ? item['product']['name'] ?? 'Unknown' : 'Unknown').toString();
                    final region = (item['region'] ?? 'N/A').toString();
                    final cycle = (item['cycle'] ?? 'monthly').toString();
                    final qty = int.tryParse((item['quantity'] ?? '1').toString()) ?? 1;
                    final price = (item['unit_price'] ?? item['price'] ?? '0').toString();
                    final id = int.tryParse((item['id'] ?? '0').toString()) ?? 0;
                    return DataRow(cells: [
                      DataCell(Text(name, style: const TextStyle(fontWeight: FontWeight.w500))),
                      DataCell(Text(region)),
                      DataCell(Text(cycle)),
                      DataCell(_QuantityStepper(value: qty, onChanged: (v) {})),
                      DataCell(Text('\$$price', style: const TextStyle(fontWeight: FontWeight.w600))),
                      DataCell(IconButton(
                        icon: const Icon(Icons.delete_outline, size: 18),
                        onPressed: () => _removeItem(id),
                        tooltip: 'Remove',
                      )),
                    ]);
                  }).toList(),
              ),
            ),
          ),
        ),
        const SizedBox(width: 24),
        SizedBox(
          width: 300,
          child: Card(
            child: Padding(
              padding: const EdgeInsets.all(24),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  const Text('Order Summary', style: TextStyle(fontSize: 16, fontWeight: FontWeight.w600)),
                  const SizedBox(height: 16),
                  _SummaryRow('Subtotal', '\$${_summary?['subtotal'] ?? '0.00'}'),
                  _SummaryRow('Tax', '\$${_summary?['tax'] ?? '0.00'}'),
                  const Divider(),
                  _SummaryRow('Total', '\$${_summary?['total'] ?? '0.00'}', bold: true),
                  const SizedBox(height: 20),
                  SizedBox(
                    width: double.infinity,
                    child: ElevatedButton(
                      onPressed: () => Navigator.pushNamed(context, '/checkout'),
                      child: const Text('Checkout'),
                    ),
                  ),
                ],
              ),
            ),
          ),
        ),
      ],
    );
  }

  Widget _buildMobileCart() {
    return ListView(
      children: [
        ..._items.asMap().entries.map((entry) {
          final item = entry.value as Map<String, dynamic>? ?? {};
          final name = (item['product'] is Map ? item['product']['name'] ?? 'Unknown' : 'Unknown').toString();
          final region = (item['region'] ?? 'N/A').toString();
          final cycle = (item['cycle'] ?? 'monthly').toString();
          final qty = (item['quantity'] ?? 1).toString();
          final price = (item['unit_price'] ?? item['price'] ?? '0').toString();
          return Card(
            child: ListTile(
              leading: const Icon(Icons.dns_outlined),
              title: Text(name),
              subtitle: Text('$region • $cycle • Qty: $qty'),
              trailing: Text('\$$price', style: const TextStyle(fontWeight: FontWeight.w600)),
            ),
          );
        }).toList(),
        const SizedBox(height: 16),
        Card(
          child: Padding(
            padding: const EdgeInsets.all(16),
            child: Column(
              children: [
                _SummaryRow('Subtotal', '\$${_summary?['subtotal'] ?? '0.00'}'),
                _SummaryRow('Total', '\$${_summary?['total'] ?? '0.00'}', bold: true),
                const SizedBox(height: 12),
                SizedBox(width: double.infinity,
                  child: ElevatedButton(
                    onPressed: () => Navigator.pushNamed(context, '/checkout'),
                    child: const Text('Checkout'),
                  ),
                ),
              ],
            ),
          ),
        ),
      ],
    );
  }
}

class _QuantityStepper extends StatelessWidget {
  final int value;
  final ValueChanged<int> onChanged;

  const _QuantityStepper({required this.value, required this.onChanged});

  @override
  Widget build(BuildContext context) {
    return Container(
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(6),
        border: Border.all(color: const Color(0xFFE2E8F0)),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          _StepButton(
            icon: Icons.remove,
            onTap: value > 1 ? () => onChanged(value - 1) : null,
          ),
          SizedBox(
            width: 36,
            child: Text('$value', textAlign: TextAlign.center, style: const TextStyle(fontSize: 14)),
          ),
          _StepButton(
            icon: Icons.add,
            onTap: () => onChanged(value + 1),
          ),
        ],
      ),
    );
  }
}

class _StepButton extends StatefulWidget {
  final IconData icon;
  final VoidCallback? onTap;

  const _StepButton({required this.icon, required this.onTap});

  @override
  State<_StepButton> createState() => _StepButtonState();
}

class _StepButtonState extends State<_StepButton> {
  bool _hovered = false;

  @override
  Widget build(BuildContext context) {
    final disabled = widget.onTap == null;
    return InkWell(
      onTap: widget.onTap,
      onHover: (h) => setState(() => _hovered = h && !disabled),
      borderRadius: BorderRadius.circular(4),
      child: Container(
        width: 32, height: 32,
        alignment: Alignment.center,
        color: _hovered ? const Color(0x0A000000) : Colors.transparent,
        child: Icon(
          widget.icon,
          size: 16,
          color: disabled ? Colors.grey[300] : Colors.grey[600],
        ),
      ),
    );
  }
}

class _SummaryRow extends StatelessWidget {
  final String label;
  final String value;
  final bool bold;
  const _SummaryRow(this.label, this.value, {this.bold = false});

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 4),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        children: [
          Text(label, style: TextStyle(color: Colors.grey[600], fontWeight: bold ? FontWeight.w600 : FontWeight.normal)),
          Text(value, style: TextStyle(fontWeight: bold ? FontWeight.w700 : FontWeight.w500, fontSize: bold ? 16 : 14)),
        ],
      ),
    );
  }
}
