import 'package:flutter/material.dart';
import '../../../../core/theme/app_theme.dart';
import '../../../../core/theme/responsive.dart';

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
      padding: EdgeInsets.all(ResponsiveBreakpoints.contentPadding(context)),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Text('Checkout', style: TextStyle(fontSize: 22, fontWeight: FontWeight.w600)),
          const SizedBox(height: 20),
          if (isDesktop) _buildStepIndicator(),
          const SizedBox(height: 20),
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
    return Container(
      padding: const EdgeInsets.all(24),
      decoration: BoxDecoration(
        color: Theme.of(context).cardTheme.color ?? Colors.white,
        borderRadius: BorderRadius.circular(8),
        border: Border.all(color: AppTheme.cardBorder),
      ),
      child: Row(
        children: [
          _StepCircle(number: 1, label: 'Review', active: _step >= 0),
          const Expanded(child: SizedBox(width: 24, child: Divider())),
          _StepCircle(number: 2, label: 'Payment', active: _step >= 1),
          const Expanded(child: SizedBox(width: 24, child: Divider())),
          _StepCircle(number: 3, label: 'Confirm', active: _step >= 2),
        ],
      ),
    );
  }

  Widget _buildStepContent() {
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(_step == 0 ? 'Order Items' : _step == 1 ? 'Payment Method' : 'Confirm Order',
                 style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w600)),
            const SizedBox(height: 16),
            if (_step == 0) ...[
              ...List.generate(2, (i) => Container(
                padding: const EdgeInsets.symmetric(vertical: 8),
                decoration: BoxDecoration(
                  border: i < 1 ? Border(bottom: BorderSide(color: Colors.grey[200]!)) : null,
                ),
                child: Row(
                  children: [
                    const Icon(Icons.dns_outlined, color: AppTheme.primaryColor),
                    const SizedBox(width: 12),
                    Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text('Cloud VPS ${i == 0 ? "Basic" : "Pro"}', style: const TextStyle(fontWeight: FontWeight.w500)),
                        Text('US East • Monthly', style: TextStyle(fontSize: 12, color: Colors.grey[500])),
                      ],
                    ),
                    const Spacer(),
                    Text('\$${i == 0 ? "5.00" : "20.00"}', style: const TextStyle(fontWeight: FontWeight.w600)),
                  ],
                ),
              )),
            ] else if (_step == 1) ...[
              ListTile(
                leading: const Radio<int>(value: 0, groupValue: 0, onChanged: null),
                title: const Text('Credit Card'),
                subtitle: const Text('Visa, Mastercard'),
              ),
              ListTile(
                leading: const Radio<int>(value: 1, groupValue: 0, onChanged: null),
                title: const Text('PayPal'),
                subtitle: const Text('Pay with PayPal'),
              ),
            ] else ...[
              const ListTile(leading: Icon(Icons.check_circle, color: Colors.green), title: Text('Order confirmed'), subtitle: Text('Your resources will be provisioned shortly')),
            ],
            const SizedBox(height: 24),
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                if (_step > 0)
                  OutlinedButton(onPressed: () => setState(() => _step--), child: const Text('Back'))
                else
                  const SizedBox.shrink(),
                ElevatedButton(
                  onPressed: () => setState(() => _step = (_step + 1).clamp(0, 2)),
                  child: Text(_step == 2 ? 'Confirm Order' : 'Continue'),
                ),
              ],
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
          Text(label, style: TextStyle(color: Colors.grey[600], fontWeight: bold ? FontWeight.w600 : FontWeight.normal)),
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
    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        Container(
          width: 32, height: 32,
          decoration: BoxDecoration(
            shape: BoxShape.circle,
            color: active ? AppTheme.primaryColor : Colors.grey[300],
          ),
          child: Center(
            child: Text('$number', style: TextStyle(color: active ? Colors.white : Colors.grey[600], fontSize: 14, fontWeight: FontWeight.w600)),
          ),
        ),
        const SizedBox(width: 8),
        Text(label, style: TextStyle(fontSize: 13, color: active ? AppTheme.primaryColor : Colors.grey[500], fontWeight: active ? FontWeight.w600 : FontWeight.normal)),
      ],
    );
  }
}
