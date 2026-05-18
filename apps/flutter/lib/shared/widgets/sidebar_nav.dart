import 'package:flutter/material.dart';
import '../../core/theme/app_theme.dart';

class SidebarNav extends StatefulWidget {
  final int currentIndex;
  final ValueChanged<int> onTap;
  final bool initiallyCollapsed;

  const SidebarNav({
    super.key,
    required this.currentIndex,
    required this.onTap,
    this.initiallyCollapsed = false,
  });

  @override
  State<SidebarNav> createState() => _SidebarNavState();
}

class _SidebarNavState extends State<SidebarNav> with SingleTickerProviderStateMixin {
  bool _collapsed = false;
  late AnimationController _anim;
  late Animation<double> _widthAnim;

  @override
  void initState() {
    super.initState();
    _collapsed = widget.initiallyCollapsed;
    _anim = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 180),
      value: _collapsed ? 1.0 : 0.0,
    );
    _widthAnim = Tween<double>(begin: 56, end: 240).animate(
      CurvedAnimation(parent: _anim, curve: Curves.easeInOut),
    );
  }

  @override
  void dispose() {
    _anim.dispose();
    super.dispose();
  }

  void _toggle() {
    setState(() {
      _collapsed = !_collapsed;
      _collapsed ? _anim.forward() : _anim.reverse();
    });
  }

  @override
  Widget build(BuildContext context) {
    return AnimatedBuilder(
      animation: _widthAnim,
      builder: (context, _) {
        return MouseRegion(
          onEnter: _collapsed ? (_) {
            setState(() => _collapsed = false);
            _anim.reverse();
          } : null,
          child: Container(
            width: _collapsed ? 56 : _widthAnim.value,
            color: AppTheme.sidebarBg,
            child: Column(
              children: [
                // Logo / collapse toggle
                _buildLogo(),

                const SizedBox(height: 8),

                // Navigation
                Expanded(
                  child: ListView(
                    padding: const EdgeInsets.symmetric(vertical: 4),
                    children: [
                      _NavItem(
                        icon: Icons.computer_rounded,
                        label: 'Products',
                        collapsed: _collapsed,
                        isActive: widget.currentIndex == 0,
                        onTap: () => widget.onTap(0),
                      ),
                      _NavItem(
                        icon: Icons.shopping_cart_outlined,
                        label: 'Cart',
                        collapsed: _collapsed,
                        isActive: widget.currentIndex == 1,
                        onTap: () => widget.onTap(1),
                      ),
                      _NavItem(
                        icon: Icons.dns_outlined,
                        label: 'Resources',
                        collapsed: _collapsed,
                        isActive: widget.currentIndex == 2,
                        onTap: () => widget.onTap(2),
                      ),
                      _NavItem(
                        icon: Icons.support_agent_outlined,
                        label: 'Tickets',
                        collapsed: _collapsed,
                        isActive: widget.currentIndex == 3,
                        badge: '3',
                        onTap: () => widget.onTap(3),
                      ),
                      const SizedBox(height: 8),
                      if (!_collapsed)
                        const Padding(
                          padding: EdgeInsets.symmetric(horizontal: 20),
                          child: Text(
                            'ACCOUNT',
                            style: TextStyle(
                              color: Color(0xFF64748B),
                              fontSize: 10,
                              fontWeight: FontWeight.w700,
                              letterSpacing: 1.2,
                            ),
                          ),
                        )
                      else
                        const SizedBox(height: 8),
                      _NavItem(
                        icon: Icons.person_outline,
                        label: 'Profile',
                        collapsed: _collapsed,
                        isActive: widget.currentIndex == 4,
                        onTap: () => widget.onTap(4),
                      ),
                      _NavItem(
                        icon: Icons.settings_outlined,
                        label: 'Settings',
                        collapsed: _collapsed,
                        isActive: false,
                        onTap: () {},
                      ),
                    ],
                  ),
                ),

                // Collapse toggle button
                _buildCollapseButton(),

                // User footer
                if (!_collapsed) _buildUserFooter(),
              ],
            ),
          ),
        );
      },
    );
  }

  Widget _buildLogo() {
    return InkWell(
      onTap: _toggle,
      hoverColor: AppTheme.sidebarHover,
      child: Container(
        height: 56,
        padding: const EdgeInsets.symmetric(horizontal: 16),
        decoration: const BoxDecoration(
          border: Border(bottom: BorderSide(color: Color(0xFF334155), width: 1)),
        ),
        child: Row(
          children: [
            const Icon(Icons.cloud, color: AppTheme.sidebarActive, size: 24),
            if (!_collapsed) ...[
              const SizedBox(width: 10),
              const Expanded(
                child: Text(
                  'CloudPlatform',
                  style: TextStyle(
                    color: Colors.white,
                    fontSize: 16,
                    fontWeight: FontWeight.w600,
                  ),
                  overflow: TextOverflow.ellipsis,
                ),
              ),
            ],
          ],
        ),
      ),
    );
  }

  Widget _buildCollapseButton() {
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 4),
      child: InkWell(
        onTap: _toggle,
        borderRadius: BorderRadius.circular(6),
        hoverColor: AppTheme.sidebarHover,
        child: Container(
          height: 32,
          alignment: _collapsed ? Alignment.center : Alignment.centerRight,
          padding: const EdgeInsets.symmetric(horizontal: 8),
          child: Icon(
            _collapsed ? Icons.menu_rounded : Icons.chevron_left_rounded,
            size: 18,
            color: const Color(0xFF94A3B8),
          ),
        ),
      ),
    );
  }

  Widget _buildUserFooter() {
    return Container(
      padding: const EdgeInsets.all(12),
      decoration: const BoxDecoration(
        border: Border(top: BorderSide(color: Color(0xFF334155), width: 1)),
      ),
      child: Row(
        children: [
          const CircleAvatar(radius: 14, backgroundColor: AppTheme.sidebarActive,
            child: Icon(Icons.person, size: 16, color: Colors.white),
          ),
          const SizedBox(width: 10),
          const Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisSize: MainAxisSize.min,
              children: [
                Text('User Name', style: TextStyle(color: Colors.white, fontSize: 13)),
                Text('user@example.com',
                     style: TextStyle(color: Color(0xFF94A3B8), fontSize: 11),
                     overflow: TextOverflow.ellipsis),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class _NavItem extends StatefulWidget {
  final IconData icon;
  final String label;
  final bool collapsed;
  final bool isActive;
  final String? badge;
  final VoidCallback onTap;

  const _NavItem({
    required this.icon,
    required this.label,
    required this.collapsed,
    required this.isActive,
    this.badge,
    required this.onTap,
  });

  @override
  State<_NavItem> createState() => _NavItemState();
}

class _NavItemState extends State<_NavItem> {
  bool _hovered = false;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
      child: Material(
        color: _resolveBg(),
        borderRadius: BorderRadius.circular(6),
        child: InkWell(
          onTap: widget.onTap,
          onHover: (h) => setState(() => _hovered = h),
          borderRadius: BorderRadius.circular(6),
          child: Container(
            constraints: const BoxConstraints(minHeight: 40),
            padding: widget.collapsed
                ? EdgeInsets.zero
                : const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
            alignment: widget.collapsed ? Alignment.center : null,
            child: Row(
              mainAxisSize: widget.collapsed ? MainAxisSize.min : MainAxisSize.max,
              children: [
                Stack(
                  children: [
                    Icon(
                      widget.icon,
                      size: 20,
                      color: widget.isActive
                          ? AppTheme.sidebarActive
                          : AppTheme.sidebarText,
                    ),
                    if (widget.badge != null && widget.collapsed)
                      Positioned(
                        right: -4,
                        top: -4,
                        child: Container(
                          width: 14,
                          height: 14,
                          decoration: const BoxDecoration(
                            color: Colors.red,
                            shape: BoxShape.circle,
                          ),
                          child: Center(
                            child: Text(
                              widget.badge!,
                              style: const TextStyle(color: Colors.white, fontSize: 8),
                            ),
                          ),
                        ),
                      ),
                  ],
                ),
                if (!widget.collapsed) ...[
                  const SizedBox(width: 12),
                  Expanded(
                    child: Text(
                      widget.label,
                      style: TextStyle(
                        color: widget.isActive ? AppTheme.sidebarActive : AppTheme.sidebarText,
                        fontSize: 13,
                        fontWeight: widget.isActive ? FontWeight.w600 : FontWeight.w400,
                      ),
                    ),
                  ),
                  if (widget.badge != null)
                    Container(
                      padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                      decoration: BoxDecoration(
                        color: Colors.red.withValues(alpha: 0.15),
                        borderRadius: BorderRadius.circular(10),
                      ),
                      child: Text(
                        widget.badge!,
                        style: const TextStyle(color: Colors.red, fontSize: 10, fontWeight: FontWeight.w600),
                      ),
                    ),
                ],
              ],
            ),
          ),
        ),
      ),
    );
  }

  Color _resolveBg() {
    if (widget.isActive) return AppTheme.sidebarActive.withValues(alpha: 0.2);
    if (_hovered) return AppTheme.sidebarHover.withValues(alpha: 0.6);
    return Colors.transparent;
  }
}
