import 'dart:async';
import 'dart:ui';

import 'package:flutter/material.dart';
import 'package:vtsa_mobile/app/app_dependencies.dart';
import 'package:vtsa_mobile/app/vtsa_app.dart';
import 'package:vtsa_mobile/core/logging/json_logger.dart';
import 'package:vtsa_mobile/design_system/branding/power_solutions_backdrop.dart';
import 'package:vtsa_mobile/design_system/branding/power_solutions_logo.dart';
import 'package:vtsa_mobile/design_system/theme/vtsa_theme.dart';
import 'package:vtsa_mobile/design_system/theme/vtsa_tokens.dart';

typedef DependencyLoader = Future<AppDependencies> Function();

class AppBootstrap extends StatefulWidget {
  const AppBootstrap({
    super.key,
    this.loadDependencies = AppDependencies.create,
  });

  final DependencyLoader loadDependencies;

  @override
  State<AppBootstrap> createState() => _AppBootstrapState();
}

class _AppBootstrapState extends State<AppBootstrap> {
  late Future<AppDependencies> _dependencies;

  @override
  void initState() {
    super.initState();
    _start();
  }

  void _start() {
    _dependencies = _load();
  }

  Future<AppDependencies> _load() async {
    final dependencies = await widget.loadDependencies();

    FlutterError.onError = (details) {
      FlutterError.presentError(details);
      unawaited(
        dependencies.crashReporting.record(
          details.exception,
          details.stack ?? StackTrace.current,
        ),
      );
    };
    PlatformDispatcher.instance.onError = (error, stack) {
      unawaited(dependencies.crashReporting.record(error, stack));
      return true;
    };

    JsonLogger().info('mobile_app_started');
    unawaited(dependencies.initialize());

    return dependencies;
  }

  void _retry() {
    setState(_start);
  }

  @override
  Widget build(BuildContext context) {
    return FutureBuilder<AppDependencies>(
      future: _dependencies,
      builder: (context, snapshot) {
        if (snapshot.hasData) {
          return VtsaApp(dependencies: snapshot.requireData);
        }

        return MaterialApp(
          debugShowCheckedModeBanner: false,
          title: 'Power Solutions',
          theme: VtsaTheme.light(),
          darkTheme: VtsaTheme.dark(),
          themeMode: ThemeMode.system,
          home: _StartupPage(
            failed: snapshot.hasError,
            onRetry: snapshot.hasError ? _retry : null,
          ),
        );
      },
    );
  }
}

class _StartupPage extends StatelessWidget {
  const _StartupPage({required this.failed, this.onRetry});

  final bool failed;
  final VoidCallback? onRetry;

  @override
  Widget build(BuildContext context) {
    final colors = Theme.of(context).colorScheme;

    return Scaffold(
      body: DecoratedBox(
        decoration: BoxDecoration(
          gradient: LinearGradient(
            begin: Alignment.topLeft,
            end: Alignment.bottomRight,
            colors: Theme.of(context).brightness == Brightness.dark
                ? const [Color(0xFF07172C), Color(0xFF0B2448)]
                : const [Color(0xFFF5F8FB), Color(0xFFEAF8F8)],
          ),
        ),
        child: PowerSolutionsBackdrop(
          child: SafeArea(
            child: Center(
              child: ConstrainedBox(
                constraints: const BoxConstraints(maxWidth: 460),
                child: Padding(
                  padding: const EdgeInsets.all(32),
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    crossAxisAlignment: CrossAxisAlignment.center,
                    children: [
                      if (failed)
                        Container(
                          width: 72,
                          height: 72,
                          decoration: BoxDecoration(
                            color: colors.errorContainer,
                            borderRadius: BorderRadius.circular(22),
                          ),
                          child: Icon(
                            Icons.power_off_rounded,
                            color: colors.error,
                          ),
                        )
                      else
                        const PowerSolutionsLogo(height: 76),
                      const SizedBox(height: VtsaSpacing.xl),
                      Text(
                        failed
                            ? 'Power Solutions could not start'
                            : 'Starting Power Solutions',
                        textAlign: TextAlign.center,
                        style: Theme.of(context).textTheme.headlineLarge,
                      ),
                      const SizedBox(height: 10),
                      Text(
                        failed
                            ? 'Check that the local platform is running and that '
                                  'API_BASE_URL is correct, then try again.'
                            : 'Connecting to the charging network and preparing '
                                  'your map.',
                        textAlign: TextAlign.center,
                        style: Theme.of(context).textTheme.bodyLarge?.copyWith(
                          color: colors.onSurfaceVariant,
                        ),
                      ),
                      const SizedBox(height: 28),
                      if (failed)
                        FilledButton.icon(
                          onPressed: onRetry,
                          icon: const Icon(Icons.refresh_rounded),
                          label: const Text('Try again'),
                        )
                      else
                        ClipRRect(
                          borderRadius: BorderRadius.circular(VtsaRadii.pill),
                          child: const LinearProgressIndicator(
                            minHeight: 6,
                            semanticsLabel: 'Starting Power Solutions',
                          ),
                        ),
                    ],
                  ),
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}
