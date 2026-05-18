import 'dart:io' show Platform;
import 'package:flutter/material.dart';
import 'package:window_manager/window_manager.dart';

class DesktopTitleBar extends StatelessWidget {
  final String title;

  const DesktopTitleBar({super.key, this.title = 'CloudPlatform'});

  @override
  Widget build(BuildContext context) {
    if (!Platform.isMacOS) {
      // Windows/Linux: use native title bar, just add a thin custom area below it
      return const SizedBox.shrink();
    }

    return SizedBox(
      height: 40,
      child: Row(
        children: [
          // macOS traffic light spacer
          const SizedBox(width: 12),
          // ...traffic lights are handled natively by window_manager...
          const SizedBox(width: 64),

          // Title
          Expanded(
            child: Center(
              child: Text(
                title,
                style: TextStyle(
                  fontSize: 12,
                  color: Colors.grey[600],
                  fontWeight: FontWeight.w500,
                ),
              ),
            ),
          ),

          // Window controls (fallback for non-macOS platforms when using frameless)
          _WindowControlButtons(),
        ],
      ),
    );
  }
}

class _WindowControlButtons extends StatelessWidget {
  @override
  Widget build(BuildContext context) {
    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        _ControlButton(
          icon: Icons.minimize_rounded,
          tooltip: 'Minimize',
          onTap: () => windowManager.minimize(),
        ),
        _ControlButton(
          icon: Icons.crop_square_rounded,
          tooltip: 'Maximize',
          onTap: () async {
            if (await windowManager.isMaximized()) {
              await windowManager.unmaximize();
            } else {
              await windowManager.maximize();
            }
          },
        ),
        _ControlButton(
          icon: Icons.close_rounded,
          tooltip: 'Close',
          color: Colors.red,
          onTap: () => windowManager.close(),
        ),
      ],
    );
  }
}

class _ControlButton extends StatefulWidget {
  final IconData icon;
  final String tooltip;
  final Color? color;
  final VoidCallback onTap;

  const _ControlButton({
    required this.icon,
    required this.tooltip,
    this.color,
    required this.onTap,
  });

  @override
  State<_ControlButton> createState() => _ControlButtonState();
}

class _ControlButtonState extends State<_ControlButton> {
  bool _hovered = false;

  @override
  Widget build(BuildContext context) {
    return MouseRegion(
      onEnter: (_) => setState(() => _hovered = true),
      onExit: (_) => setState(() => _hovered = false),
      child: GestureDetector(
        behavior: HitTestBehavior.opaque,
        onTap: widget.onTap,
        child: Container(
          width: 44,
          height: 32,
          alignment: Alignment.center,
          color: _hovered
              ? (widget.color ?? Colors.grey).withValues(alpha: 0.15)
              : Colors.transparent,
          child: Tooltip(
            message: widget.tooltip,
            child: Icon(
              widget.icon,
              size: 16,
              color: _hovered ? (widget.color ?? Colors.grey[600]) : Colors.grey[500],
            ),
          ),
        ),
      ),
    );
  }
}
