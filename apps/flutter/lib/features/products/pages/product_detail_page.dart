import 'package:flutter/material.dart';
import '../../../core/theme/app_theme.dart';

class ProductDetailPage extends StatefulWidget {
  const ProductDetailPage({super.key});

  @override
  State<ProductDetailPage> createState() => _ProductDetailPageState();
}

class _ProductDetailPageState extends State<ProductDetailPage> {
  String _cycle = 'monthly';

  @override
  Widget build(BuildContext context) {
    final isDesktop = AppTheme.isDesktop(context);

    return Padding(
      padding: EdgeInsets.all(isDesktop ? 24 : 16),
      child: isDesktop ? _buildDesktopLayout() : _buildMobileLayout(),
    );
  }

  Widget _buildDesktopLayout() {
    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        // Left: spec details
        Expanded(
          flex: 3,
          child: Card(
            child: Padding(
              padding: const EdgeInsets.all(24),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    children: [
                      const Icon(Icons.dns_outlined, size: 40, color: AppTheme.primaryColor),
                      const SizedBox(width: 16),
                      const Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text('Cloud VPS Pro',
                                 style: TextStyle(fontSize: 22, fontWeight: FontWeight.w600)),
                            SizedBox(height: 4),
                            Text('US East • Linux', style: TextStyle(color: Colors.grey)),
                          ],
                        ),
                      ),
                      ElevatedButton.icon(
                        onPressed: () {},
                        icon: const Icon(Icons.shopping_cart, size: 18),
                        label: const Text('Add to Cart'),
                      ),
                    ],
                  ),
                  const SizedBox(height: 32),
                  const Text('Specifications', style: TextStyle(fontSize: 16, fontWeight: FontWeight.w600)),
                  const SizedBox(height: 16),
                  _buildSpecTable(),
                ],
              ),
            ),
          ),
        ),
        const SizedBox(width: 24),
        // Right: pricing sidebar
        SizedBox(
          width: 300,
          child: Card(
            child: Padding(
              padding: const EdgeInsets.all(24),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  const Text('Pricing', style: TextStyle(fontSize: 16, fontWeight: FontWeight.w600)),
                  const SizedBox(height: 16),
                  SegmentedButton<String>(
                    segments: const [
                      ButtonSegment(value: 'monthly', label: Text('Monthly')),
                      ButtonSegment(value: 'quarterly', label: Text('Quarterly')),
                      ButtonSegment(value: 'yearly', label: Text('Yearly')),
                    ],
                    selected: {_cycle},
                    onSelectionChanged: (v) => setState(() => _cycle = v.first),
                  ),
                  const SizedBox(height: 20),
                  const Text('\$20.00', style: TextStyle(fontSize: 32, fontWeight: FontWeight.w700)),
                  const Text('/month', style: TextStyle(color: Colors.grey)),
                  const SizedBox(height: 16),
                  const Divider(),
                  const SizedBox(height: 16),
                  _PriceRow('Setup fee', '\$0.00'),
                  _PriceRow('Estimated tax', '\$1.60'),
                  const Divider(),
                  _PriceRow('Total', '\$21.60', bold: true),
                ],
              ),
            ),
          ),
        ),
      ],
    );
  }

  Widget _buildMobileLayout() {
    return ListView(
      children: [
        Card(
          child: Padding(
            padding: const EdgeInsets.all(16),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                const Text('Cloud VPS Pro', style: TextStyle(fontSize: 20, fontWeight: FontWeight.w600)),
                const SizedBox(height: 16),
                const Text('Specifications', style: TextStyle(fontSize: 16, fontWeight: FontWeight.w600)),
                const SizedBox(height: 12),
                _buildSpecTable(),
              ],
            ),
          ),
        ),
        const SizedBox(height: 16),
        Card(
          child: Padding(
            padding: const EdgeInsets.all(16),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                const Text('Pricing', style: TextStyle(fontSize: 16, fontWeight: FontWeight.w600)),
                const SizedBox(height: 12),
                const Text('\$20.00', style: TextStyle(fontSize: 28, fontWeight: FontWeight.w700)),
                const SizedBox(height: 16),
                SizedBox(
                  width: double.infinity,
                  child: ElevatedButton(onPressed: () {}, child: const Text('Add to Cart')),
                ),
              ],
            ),
          ),
        ),
      ],
    );
  }

  Widget _buildSpecTable() {
    return Table(
      columnWidths: const {0: FixedColumnWidth(160), 1: FlexColumnWidth()},
      defaultVerticalAlignment: TableCellVerticalAlignment.middle,
      children: const [
        TableRow(children: [
          Padding(
            padding: EdgeInsets.symmetric(vertical: 8),
            child: Text('CPU', style: TextStyle(color: Colors.grey)),
          ),
          Padding(padding: EdgeInsets.symmetric(vertical: 8), child: Text('4 vCPU')),
        ]),
        TableRow(children: [
          Padding(
            padding: EdgeInsets.symmetric(vertical: 8),
            child: Text('RAM', style: TextStyle(color: Colors.grey)),
          ),
          Padding(padding: EdgeInsets.symmetric(vertical: 8), child: Text('8 GB')),
        ]),
        TableRow(children: [
          Padding(
            padding: EdgeInsets.symmetric(vertical: 8),
            child: Text('System Disk', style: TextStyle(color: Colors.grey)),
          ),
          Padding(padding: EdgeInsets.symmetric(vertical: 8), child: Text('80 GB SSD')),
        ]),
        TableRow(children: [
          Padding(
            padding: EdgeInsets.symmetric(vertical: 8),
            child: Text('Bandwidth', style: TextStyle(color: Colors.grey)),
          ),
          Padding(padding: EdgeInsets.symmetric(vertical: 8), child: Text('5 TB / month')),
        ]),
        TableRow(children: [
          Padding(
            padding: EdgeInsets.symmetric(vertical: 8),
            child: Text('IPv4', style: TextStyle(color: Colors.grey)),
          ),
          Padding(padding: EdgeInsets.symmetric(vertical: 8), child: Text('1 Included')),
        ]),
      ],
    );
  }
}

class _PriceRow extends StatelessWidget {
  final String label;
  final String value;
  final bool bold;

  const _PriceRow(this.label, this.value, {this.bold = false});

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 4),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        children: [
          Text(label, style: TextStyle(color: Colors.grey, fontWeight: bold ? FontWeight.w600 : FontWeight.normal)),
          Text(value, style: TextStyle(fontWeight: bold ? FontWeight.w700 : FontWeight.w500)),
        ],
      ),
    );
  }
}
