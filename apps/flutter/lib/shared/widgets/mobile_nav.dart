import 'package:flutter/material.dart';

class MobileNav extends StatelessWidget {
  final int currentIndex;
  final ValueChanged<int> onTap;

  const MobileNav({
    super.key,
    required this.currentIndex,
    required this.onTap,
  });

  @override
  Widget build(BuildContext context) {
    return NavigationBar(
      selectedIndex: currentIndex,
      onDestinationSelected: onTap,
      destinations: const [
        NavigationDestination(icon: Icon(Icons.computer), label: 'Products'),
        NavigationDestination(icon: Icon(Icons.shopping_cart), label: 'Cart'),
        NavigationDestination(icon: Icon(Icons.dns), label: 'Resources'),
        NavigationDestination(icon: Icon(Icons.support_agent), label: 'Tickets'),
        NavigationDestination(icon: Icon(Icons.person), label: 'Profile'),
      ],
    );
  }
}
