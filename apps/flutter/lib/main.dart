import 'dart:io' show Platform;
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:window_manager/window_manager.dart';
import 'core/theme/app_theme.dart';
import 'core/i18n/app_localizations.dart';
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

class CloudPlatformApp extends StatefulWidget {
  const CloudPlatformApp({super.key});

  @override
  State<CloudPlatformApp> createState() => _CloudPlatformAppState();
}

class _CloudPlatformAppState extends State<CloudPlatformApp> {
  final _navNotifier = ValueNotifier<int>(0);

  @override
  void dispose() {
    _navNotifier.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'CloudPlatform',
      theme: AppTheme.light,
      darkTheme: AppTheme.dark,
      home: _ShortcutWrapper(
        navNotifier: _navNotifier,
        child: ResponsiveScaffold(navNotifier: _navNotifier),
      ),
      debugShowCheckedModeBanner: false,
      localizationsDelegates: const [
        AppLocalizations.delegate,
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
  final ValueNotifier<int> navNotifier;
  const _ShortcutWrapper({required this.child, required this.navNotifier});

  @override
  Widget build(BuildContext context) {
    return Shortcuts(
      shortcuts: buildShortcuts(),
      child: Actions(
        actions: {
          NavigateIntent: CallbackAction<NavigateIntent>(
            onInvoke: (intent) {
              navNotifier.value = intent.index;
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
