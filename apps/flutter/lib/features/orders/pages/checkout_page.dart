import 'package:flutter/material.dart';
import '../../../core/theme/app_theme.dart';

class CheckoutPage extends StatefulWidget {
  const CheckoutPage({super.key});

  @override
  State<CheckoutPage> createState() => _CheckoutPageState();
}

class _CheckoutPageState extends State<CheckoutPage> {
  int _step = 0;

  @override
  Widget build(BuildContext context) {
    final isDesktop = AppTheme.isDesktop(context);

    return Padding(
      padding: const EdgeInsets.all(24),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Text('Checkout', style: TextStyle(fontSize: 24, fontWeight: FontWeight.w600)),
          const SizedBox(height: 24),
          if (isDesktop)
            Row(
              children: [
                _buildStepIndicator(),
              ],
            ),
          const SizedBox(height: 24),
          Expanded(
            child: isDesktop
                ? Row(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Expanded(flex: 3, child: _buildStepContent()),
                      const SizedBox(width: 24),
                      SizedBox(width: 300, child: _buildOrderSummary()),
                    ],
                  )
                : _buildStepContent(),
          ),
        ],
      ),
    );
  }

  Widget _buildStepIndicator() {
    return Row(
      children: [
        _StepCircle(number: 1, label: 'Review', active: _step >= 0),
        const SizedBox(width: 32, child: Divider()),
        _StepCircle(number: 2, label: 'Payment', active: _step >= 1),
        const SizedBox(width: 32, child: Divider()),
        _StepCircle(number: 3, label: 'Confirm', active: _step >= 2),
      ],
    );
  }

  Widget _buildStepContent() {
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Text('Order Items', style: TextStyle(fontSize: 16, fontWeight: FontWeight.w600)),
            const SizedBox(height: 16),
            ...List.generate(2, (i) => ListTile(
                  leading: const Icon(Icons.dns_outlined),
                  title: Text('Cloud VPS ${i == 0 ? "Basic" : "Pro"}'),
                  subtitle: const Text('US East • Monthly'),
                  trailing: Text('\$${i == 0 ? "5.00" : "20.00"}', style: const TextStyle(fontWeight: FontWeight.w600)),
                )),
            const SizedBox(height: 24),
            SizedBox(
              width: double.infinity,
              child: ElevatedButton(
                onPressed: () => setState(() => _step = (_step + 1).clamp(0, 2)),
                child: Text(_step == 2 ? 'Confirm Order' : 'Continue'),
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildOrderSummary() {
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Text('Order Summary', style: TextStyle(fontSize: 16, fontWeight: FontWeight.w600)),
            const SizedBox(height: 16),
            _line('Subtotal', '\$25.00'),
            _line('Tax', '\$2.00'),
            const Divider(),
            _line('Total', '\$27.00', bold: true),
          ],
        ),
      ),
    );
  }

  Widget _line(String label, String value, {bool bold = false}) {
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

class _StepCircle extends StatelessWidget {
  final int number;
  final String label;
  final bool active;

  const _StepCircle({required this.number, required this.label, required this.active});

  @override
  Widget build(BuildContext context) {
    return Column(
      children: [
        Container(
          width: 32,
          height: 32,
          decoration: BoxDecoration(
            shape: BoxShape.circle,
            color: active ? AppTheme.primaryColor : Colors.grey[300],
          ),
          child: Center(
            child: Text('$number', style: TextStyle(color: active ? Colors.white : Colors.grey[600], fontSize: 14)),
          ),
        ),
        const SizedBox(height: 4),
        Text(label, style: TextStyle(fontSize: 12, color: active ? AppTheme.primaryColor : Colors.grey)),
      ],
    );
  }
}
