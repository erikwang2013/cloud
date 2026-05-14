import 'package:flutter/material.dart';
import '../../../core/theme/app_theme.dart';

class CartPage extends StatelessWidget {
  const CartPage({super.key});

  @override
  Widget build(BuildContext context) {
    final isDesktop = AppTheme.isDesktop(context);

    return Padding(
      padding: const EdgeInsets.all(24),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Text('Shopping Cart', style: TextStyle(fontSize: 24, fontWeight: FontWeight.w600)),
          const SizedBox(height: 24),
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
            child: DataTable(
              columns: const [
                DataColumn(label: Text('Product')),
                DataColumn(label: Text('Region')),
                DataColumn(label: Text('Cycle')),
                DataColumn(label: Text('Qty')),
                DataColumn(label: Text('Price'), numeric: true),
                DataColumn(label: Text('')),
              ],
              rows: List.generate(3, (i) {
                final items = [
                  ['Cloud VPS Basic', 'US East', 'Monthly', '1', '\$5.00'],
                  ['IPv4 Address', 'Global', 'Monthly', '2', '\$6.00'],
                  ['SSD Block Storage', 'US East', 'Monthly', '1', '\$10.00'],
                ];
                return DataRow(cells: [
                  DataCell(Text(items[i][0])),
                  DataCell(Text(items[i][1])),
                  DataCell(Text(items[i][2])),
                  DataCell(Text(items[i][3])),
                  DataCell(Text(items[i][4])),
                  DataCell(IconButton(
                    icon: const Icon(Icons.delete_outline, size: 18),
                    onPressed: () {},
                  )),
                ]);
              }),
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
                  _SummaryRow('Subtotal', '\$21.00'),
                  _SummaryRow('Tax', '\$1.68'),
                  const Divider(),
                  _SummaryRow('Total', '\$22.68', bold: true),
                  const SizedBox(height: 20),
                  SizedBox(
                    width: double.infinity,
                    child: ElevatedButton(
                      onPressed: () {},
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
        Card(
          child: ListTile(
            leading: const Icon(Icons.dns_outlined),
            title: const Text('Cloud VPS Basic'),
            subtitle: const Text('US East • Monthly • Qty: 1'),
            trailing: const Text('\$5.00', style: TextStyle(fontWeight: FontWeight.w600)),
          ),
        ),
        const SizedBox(height: 16),
        Card(
          child: Padding(
            padding: const EdgeInsets.all(16),
            child: Column(
              children: [
                _SummaryRow('Subtotal', '\$21.00'),
                _SummaryRow('Total', '\$22.68', bold: true),
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
          Text(label, style: TextStyle(color: Colors.grey, fontWeight: bold ? FontWeight.w600 : FontWeight.normal)),
          Text(value, style: TextStyle(fontWeight: bold ? FontWeight.w700 : FontWeight.w500, fontSize: bold ? 16 : 14)),
        ],
      ),
    );
  }
}
