import 'package:flutter/material.dart';
import '../../../../core/theme/app_theme.dart';
import '../../../../core/theme/responsive.dart';

class CartPage extends StatefulWidget {
  const CartPage({super.key});

  @override
  State<CartPage> createState() => _CartPageState();
}

class _CartPageState extends State<CartPage> {
  final _quantities = [1, 2, 1];
  final _items = [
    ['Cloud VPS Basic', 'US East', 'Monthly', '\$5.00'],
    ['IPv4 Address', 'Global', 'Monthly', '\$3.00'],
    ['SSD Block Storage', 'US East', 'Monthly', '\$10.00'],
  ];

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
            child: Column(
              children: [
                DataTable(
                  dataRowMinHeight: 52,
                  columns: const [
                    DataColumn(label: Text('Product')),
                    DataColumn(label: Text('Region')),
                    DataColumn(label: Text('Cycle')),
                    DataColumn(label: Text('Quantity')),
                    DataColumn(label: Text('Price'), numeric: true),
                    DataColumn(label: Text('')),
                  ],
                  rows: List.generate(_items.length, (i) {
                    return DataRow(cells: [
                      DataCell(Text(_items[i][0], style: const TextStyle(fontWeight: FontWeight.w500))),
                      DataCell(Text(_items[i][1])),
                      DataCell(Text(_items[i][2])),
                      DataCell(_QuantityStepper(
                        value: _quantities[i],
                        onChanged: (v) => setState(() => _quantities[i] = v),
                      )),
                      DataCell(Text(_items[i][3], style: const TextStyle(fontWeight: FontWeight.w600))),
                      DataCell(IconButton(
                        icon: const Icon(Icons.delete_outline, size: 18),
                        onPressed: () {},
                        tooltip: 'Remove',
                      )),
                    ]);
                  }),
                ),
              ],
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
                  _SummaryRow('Subtotal', '\$18.00'),
                  _SummaryRow('Tax', '\$1.44'),
                  const Divider(),
                  _SummaryRow('Total', '\$19.44', bold: true),
                  const SizedBox(height: 20),
                  SizedBox(
                    width: double.infinity,
                    child: ElevatedButton(onPressed: () {}, child: const Text('Checkout')),
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
        ...List.generate(_items.length, (i) => Card(
          child: ListTile(
            leading: const Icon(Icons.dns_outlined),
            title: Text(_items[i][0]),
            subtitle: Text('${_items[i][1]} • ${_items[i][2]} • Qty: ${_quantities[i]}'),
            trailing: Text(_items[i][3], style: const TextStyle(fontWeight: FontWeight.w600)),
          ),
        )),
        const SizedBox(height: 16),
        Card(
          child: Padding(
            padding: const EdgeInsets.all(16),
            child: Column(
              children: [
                _SummaryRow('Subtotal', '\$18.00'),
                _SummaryRow('Total', '\$19.44', bold: true),
                const SizedBox(height: 12),
                SizedBox(width: double.infinity, child: ElevatedButton(onPressed: () {}, child: const Text('Checkout'))),
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
