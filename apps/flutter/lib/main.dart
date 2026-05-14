import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'core/theme/app_theme.dart';
import 'shared/widgets/responsive_scaffold.dart';

void main() {
  runApp(const CloudPlatformApp());
}

class CloudPlatformApp extends StatelessWidget {
  const CloudPlatformApp({super.key});

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'CloudPlatform',
      theme: AppTheme.light,
      darkTheme: AppTheme.dark,
      home: const ResponsiveScaffold(),
      debugShowCheckedModeBanner: false,
    );
  }
}
