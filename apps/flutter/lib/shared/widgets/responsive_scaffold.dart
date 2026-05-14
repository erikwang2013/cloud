import 'package:flutter/material.dart';
import '../../core/theme/app_theme.dart';
import 'sidebar_nav.dart';
import 'mobile_nav.dart';
import 'top_header.dart';
import '../../features/products/pages/product_list_page.dart';
import '../../features/orders/pages/cart_page.dart';
import '../../features/resources/pages/resource_list_page.dart';
import '../../features/auth/pages/login_page.dart';

class ResponsiveScaffold extends StatefulWidget {
  const ResponsiveScaffold({super.key});

  @override
  State<ResponsiveScaffold> createState() => _ResponsiveScaffoldState();
}

class _ResponsiveScaffoldState extends State<ResponsiveScaffold> {
  int _currentIndex = 0;

  static const _pages = <Widget>[
    ProductListPage(),
    CartPage(),
    ResourceListPage(),
    Center(child: Text('Tickets')),
    Center(child: Text('Profile')),
  ];

  @override
  Widget build(BuildContext context) {
    final isDesktop = AppTheme.isDesktop(context);

    if (isDesktop) {
      return _buildDesktopLayout();
    }
    return _buildMobileLayout();
  }

  Widget _buildDesktopLayout() {
    return Scaffold(
      body: Row(
        children: [
          SidebarNav(
            currentIndex: _currentIndex,
            onTap: (index) => setState(() => _currentIndex = index),
          ),
          Expanded(
            child: Column(
              children: [
                const TopHeader(),
                Expanded(
                  child: _pages[_currentIndex],
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildMobileLayout() {
    return Scaffold(
      appBar: AppBar(title: const Text('CloudPlatform')),
      body: _pages[_currentIndex],
      bottomNavigationBar: MobileNav(
        currentIndex: _currentIndex,
        onTap: (index) => setState(() => _currentIndex = index),
      ),
    );
  }
}
