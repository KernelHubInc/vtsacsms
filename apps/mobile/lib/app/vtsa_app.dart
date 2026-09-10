import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:go_router/go_router.dart';
import 'package:vtsa_mobile/app/app_dependencies.dart';
import 'package:vtsa_mobile/app/app_router.dart';
import 'package:vtsa_mobile/design_system/catalog/design_system_catalog_page.dart';
import 'package:vtsa_mobile/design_system/theme/vtsa_theme.dart';
import 'package:vtsa_mobile/l10n/app_localizations.dart';

class VtsaApp extends StatefulWidget {
  const VtsaApp({required this.dependencies, super.key});

  final AppDependencies dependencies;

  @override
  State<VtsaApp> createState() => _VtsaAppState();
}

class _VtsaAppState extends State<VtsaApp> with WidgetsBindingObserver {
  late final GoRouter _router;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    _router = createAppRouter(widget.dependencies);
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    _router.dispose();
    widget.dependencies.dispose();
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) {
      unawaited(widget.dependencies.charging.onResumed());
    }
  }

  @override
  Widget build(BuildContext context) {
    const showCatalog = bool.fromEnvironment('SHOW_DESIGN_CATALOG');
    if (showCatalog) {
      return MaterialApp(
        debugShowCheckedModeBanner: false,
        title: 'Power Solutions design system',
        theme: VtsaTheme.light(),
        darkTheme: VtsaTheme.dark(),
        home: const DesignSystemCatalogPage(),
      );
    }
    return MaterialApp.router(
      debugShowCheckedModeBanner: false,
      title: 'Power Solutions',
      theme: VtsaTheme.light(),
      darkTheme: VtsaTheme.dark(),
      themeMode: ThemeMode.system,
      routerConfig: _router,
      localizationsDelegates: const [
        AppLocalizations.delegate,
        GlobalMaterialLocalizations.delegate,
        GlobalWidgetsLocalizations.delegate,
        GlobalCupertinoLocalizations.delegate,
      ],
      supportedLocales: AppLocalizations.supportedLocales,
    );
  }
}
