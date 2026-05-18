import 'dart:io' show Platform;
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:window_manager/window_manager.dart';
import 'core/theme/app_theme.dart';
import 'shared/widgets/responsive_scaffold.dart';
import 'shared/widgets/command_palette.dart';
import 'shared/shortcuts/keyboard_shortcuts.dart';

void main() async {
  WidgetsFlutterBinding.ensureInitialized();

  if (_isDesktopPlatform) {
    await windowManager.ensureInitialized();

    const minSize = Size(800, 600);
    const defaultSize = Size(1280, 820);

    await windowManager.setMinimumSize(minSize);

    if (Platform.isMacOS) {
      // Frameless with custom title bar (like VS Code)
      await windowManager.setTitleBarStyle(
        TitleBarStyle.hidden,
        windowButtonVisibility: true,
      );
    }

    await windowManager.setSize(defaultSize);
    await windowManager.center();
    await windowManager.show();
    await windowManager.focus();
  }

  runApp(const CloudPlatformApp());
}

bool get _isDesktopPlatform =>
    Platform.isMacOS || Platform.isWindows || Platform.isLinux;

class CloudPlatformApp extends StatelessWidget {
  const CloudPlatformApp({super.key});

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'CloudPlatform',
      theme: AppTheme.light,
      darkTheme: AppTheme.dark,
      home: const _ShortcutWrapper(child: ResponsiveScaffold()),
      debugShowCheckedModeBanner: false,
      localizationsDelegates: const [
        GlobalMaterialLocalizations.delegate,
        GlobalWidgetsLocalizations.delegate,
        GlobalCupertinoLocalizations.delegate,
      ],
      supportedLocales: const [
        Locale('en'),
        Locale('zh'),
      ],
    );
  }
}

class _ShortcutWrapper extends StatelessWidget {
  final Widget child;
  const _ShortcutWrapper({required this.child});

  @override
  Widget build(BuildContext context) {
    return Shortcuts(
      shortcuts: buildShortcuts(),
      child: Actions(
        actions: {
          NavigateIntent: CallbackAction<NavigateIntent>(
            onInvoke: (_) {
              // Navigation is handled inside ResponsiveScaffold; this is a no-op here
              // In a real app, this would trigger a router.
              return null;
            },
          ),
          CommandPaletteIntent: CallbackAction<CommandPaletteIntent>(
            onInvoke: (_) {
              CommandPalette.show(context);
              return null;
            },
          ),
          QuitIntent: CallbackAction<QuitIntent>(
            onInvoke: (_) {
              if (_isDesktopPlatform) {
                windowManager.close();
              }
              return null;
            },
          ),
        },
        child: child,
      ),
    );
  }
}
