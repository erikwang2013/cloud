import 'package:flutter/material.dart';
import '../../core/theme/responsive.dart';
import 'sidebar_nav.dart';
import 'mobile_nav.dart';
import 'top_header.dart';
import 'desktop_title_bar.dart';
import 'desktop_menu_bar.dart';
import '../../features/products/pages/product_list_page.dart';
import '../../features/orders/pages/cart_page.dart';
import '../../features/resources/pages/resource_list_page.dart';

class ResponsiveScaffold extends StatefulWidget {
  final ValueNotifier<int>? navNotifier;

  const ResponsiveScaffold({super.key, this.navNotifier});

  @override
  State<ResponsiveScaffold> createState() => _ResponsiveScaffoldState();
}

class _ResponsiveScaffoldState extends State<ResponsiveScaffold> {
  int _currentIndex = 0;

  static const _pages = <Widget>[
    ProductListPage(),
    CartPage(),
    ResourceListPage(),
    Center(child: Text('Support Tickets', style: TextStyle(fontSize: 18))),
    Center(child: Text('Profile Settings', style: TextStyle(fontSize: 18))),
  ];

  static const _titles = [
    'Products',
    'Shopping Cart',
    'My Resources',
    'Support Tickets',
    'Profile Settings',
  ];

  @override
  void initState() {
    super.initState();
    widget.navNotifier?.addListener(_onNavNotifierChanged);
  }

  @override
  void dispose() {
    widget.navNotifier?.removeListener(_onNavNotifierChanged);
    super.dispose();
  }

  void _onNavNotifierChanged() {
    final i = widget.navNotifier?.value ?? _currentIndex;
    if (i >= 0 && i < _pages.length && i != _currentIndex) {
      setState(() => _currentIndex = i);
    }
  }

  void _switchTo(int index) {
    setState(() => _currentIndex = index);
    widget.navNotifier?.value = index;
  }

  @override
  Widget build(BuildContext context) {
    final useDesktop = ResponsiveBreakpoints.useDesktopLayout(context);

    if (useDesktop) {
      return _buildDesktopLayout();
    }
    return _buildMobileLayout();
  }

  Widget _buildDesktopLayout() {
    return Scaffold(
      body: Column(
        children: [
          const DesktopTitleBar(),
          DesktopMenuBar(menus: DesktopMenuBar.defaultMenus()),
          Expanded(
            child: Row(
              children: [
                SidebarNav(
                  currentIndex: _currentIndex,
                  onTap: _switchTo,
                ),
                Expanded(
                  child: Column(
                    children: [
                      TopHeader(pageTitle: _titles[_currentIndex]),
                      Expanded(child: _pages[_currentIndex]),
                    ],
                  ),
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
      appBar: AppBar(title: Text(_titles[_currentIndex])),
      body: _pages[_currentIndex],
      bottomNavigationBar: MobileNav(
        currentIndex: _currentIndex,
        onTap: _switchTo,
      ),
    );
  }
}
