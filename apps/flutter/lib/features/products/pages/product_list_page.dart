import 'package:flutter/material.dart';
import '../../../core/theme/app_theme.dart';

class ProductListPage extends StatelessWidget {
  const ProductListPage({super.key});

  @override
  Widget build(BuildContext context) {
    final isDesktop = AppTheme.isDesktop(context);

    return Padding(
      padding: const EdgeInsets.all(24),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          // Title row
          Row(
            children: [
              const Text(
                'Products',
                style: TextStyle(fontSize: 24, fontWeight: FontWeight.w600),
              ),
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
                  items: const [
                    DropdownMenuItem(value: 'all', child: Text('All Regions')),
                    DropdownMenuItem(value: 'us', child: Text('US East')),
                    DropdownMenuItem(value: 'eu', child: Text('Europe')),
                    DropdownMenuItem(value: 'ap', child: Text('Asia Pacific')),
                  ],
                  onChanged: (_) {},
                ),
              ],
            ],
          ),
          const SizedBox(height: 24),

          // Product grid
          Expanded(
            child: isDesktop ? _buildDesktopGrid(context) : _buildMobileList(context),
          ),
        ],
      ),
    );
  }

  Widget _buildDesktopGrid(BuildContext context) {
    return GridView.builder(
      gridDelegate: const SliverGridDelegateWithMaxCrossAxisExtent(
        maxCrossAxisExtent: 320,
        childAspectRatio: 0.85,
        crossAxisSpacing: 16,
        mainAxisSpacing: 16,
      ),
      itemCount: 12,
      itemBuilder: (_, i) => _ProductCard(index: i),
    );
  }

  Widget _buildMobileList(BuildContext context) {
    return ListView.builder(
      itemCount: 12,
      itemBuilder: (_, i) => Padding(
        padding: const EdgeInsets.only(bottom: 12),
        child: _ProductCard(index: i, compact: true),
      ),
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

class _ProductCard extends StatelessWidget {
  final int index;
  final bool compact;

  const _ProductCard({required this.index, this.compact = false});

  @override
  Widget build(BuildContext context) {
    final names = ['Cloud VPS Basic', 'Cloud VPS Pro', 'Dedicated Server', 'IPv4 Address', 'SSD Block Storage', 'com'];
    final regions = ['US East', 'Europe', 'Asia Pacific', 'Global', 'US East', 'Global'];
    final prices = ['\$5.00', '\$20.00', '\$99.00', '\$3.00', '\$10.00', '\$12.99'];

    return Card(
      clipBehavior: Clip.antiAlias,
      child: InkWell(
        onTap: () {},
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            // Image area
            Container(
              height: compact ? 80 : 140,
              color: const Color(0xFFF1F5F9),
              child: const Center(
                child: Icon(Icons.dns_outlined, size: 48, color: Color(0xFF94A3B8)),
              ),
            ),
            Padding(
              padding: const EdgeInsets.all(16),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    names[index % names.length],
                    style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 16),
                  ),
                  const SizedBox(height: 4),
                  Row(
                    children: [
                      Icon(Icons.location_on_outlined, size: 14, color: Colors.grey[600]),
                      const SizedBox(width: 4),
                      Text(regions[index % regions.length],
                           style: TextStyle(color: Colors.grey[600], fontSize: 13)),
                    ],
                  ),
                  const SizedBox(height: 12),
                  Row(
                    mainAxisAlignment: MainAxisAlignment.spaceBetween,
                    children: [
                      Text(
                        prices[index % prices.length],
                        style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w700, color: AppTheme.primaryColor),
                      ),
                      Text(
                        '/mo',
                        style: TextStyle(color: Colors.grey[500], fontSize: 13),
                      ),
                    ],
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}
