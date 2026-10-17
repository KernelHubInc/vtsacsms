import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:vtsa_mobile/app/app_dependencies.dart';
import 'package:vtsa_mobile/core/errors/app_failure.dart';
import 'package:vtsa_mobile/design_system/components/driver_components.dart';
import 'package:vtsa_mobile/design_system/components/vtsa_components.dart';
import 'package:vtsa_mobile/features/accounts/charging_accounts.dart';
import 'package:vtsa_mobile/features/wallet/wallet_repository.dart';

class ChargingAccountSelector extends StatelessWidget {
  const ChargingAccountSelector({required this.dependencies, super.key});
  final AppDependencies dependencies;
  @override
  Widget build(BuildContext context) {
    final controller = dependencies.accounts;
    if (controller == null) return const SizedBox.shrink();
    return AnimatedBuilder(
      animation: controller,
      builder: (context, _) => ListTile(
        contentPadding: EdgeInsets.zero,
        leading: const Icon(Icons.account_balance_outlined),
        title: Text(controller.selected?.name ?? 'Personal charging account'),
        subtitle: Text(
          controller.shared
              ? '${controller.selected!.typeLabel} · ${controller.selected!.roleLabel} · Change account'
              : 'Your private vehicles and wallet',
        ),
        trailing: const Icon(Icons.chevron_right),
        onTap: () => context.push('/charging-accounts'),
      ),
    );
  }
}

class ChargingAccountsScreen extends StatefulWidget {
  const ChargingAccountsScreen({required this.dependencies, super.key});
  final AppDependencies dependencies;
  @override
  State<ChargingAccountsScreen> createState() => _ChargingAccountsScreenState();
}

class _ChargingAccountsScreenState extends State<ChargingAccountsScreen> {
  ChargingAccountsController get controller => widget.dependencies.accounts!;
  List<Map<String, dynamic>> _members = [];
  List<Map<String, dynamic>> _invitations = [];
  List<Map<String, dynamic>> _incoming = [];
  List<Map<String, dynamic>> _limits = [];
  String? _error;
  bool _busy = false;
  bool _refreshing = false;
  bool _loadingDetails = false;
  int _generation = 0;

  @override
  void initState() {
    super.initState();
    // Loading notifies the router and the selector on the previous route.
    // Wait until both routes have finished building before notifying them.
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (mounted && widget.dependencies.accounts != null) _refresh();
    });
  }

  List<Map<String, dynamic>> _rows(Object? value) => (value as List)
      .map((item) => Map<String, dynamic>.from(item as Map))
      .toList();

  Future<void> _run(Future<void> Function() action) async {
    if (_busy) return;
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      await action();
    } on Object catch (error) {
      if (mounted) {
        setState(
          () => _error = error is AppFailure
              ? error.message
              : 'Could not complete this account request. Refresh and try again.',
        );
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _refresh() async {
    if (_refreshing) return;
    setState(() => _refreshing = true);
    try {
      await controller.load();
      if (mounted && controller.failure == null) await _details();
    } finally {
      if (mounted) setState(() => _refreshing = false);
    }
  }

  Future<void> _selectAccount(String? id) async {
    if (id == null ||
        id == controller.accountId ||
        _busy ||
        widget.dependencies.charging.mutationInFlight) {
      return;
    }
    controller.select(id);
    await _details();
  }

  Future<void> _details() async {
    if (!mounted) return;
    final generation = ++_generation;
    final id = controller.accountId;
    final account = controller.selected;
    final repo = controller.repository;
    setState(() {
      _loadingDetails = true;
      _error = null;
      _members = [];
      _invitations = [];
      _limits = [];
      _incoming = [];
    });
    bool current() =>
        mounted && generation == _generation && id == controller.accountId;
    Future<Object?> empty() async => <Map<String, dynamic>>[];
    final shared =
        account != null && !account.personal && account.role != 'revoked';
    try {
      final results = await Future.wait([
        repo.request('/invitations'),
        shared && (account.can('members') || account.can('vehicles'))
            ? repo.request('/$id/members')
            : empty(),
        shared && account.can('members')
            ? repo.request('/$id/invitations')
            : empty(),
        shared && account.can('limits') ? repo.request('/$id/limits') : empty(),
      ]);
      if (current()) {
        setState(() {
          _incoming = _rows(results[0]);
          _members = _rows(results[1]);
          _invitations = _rows(results[2]);
          _limits = _rows(results[3]);
        });
      }
    } on Object catch (error) {
      if (current()) {
        setState(
          () => _error = error is AppFailure
              ? error.message
              : 'Could not load account details. Refresh to try again.',
        );
      }
    } finally {
      if (current()) setState(() => _loadingDetails = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    if (widget.dependencies.accounts == null) {
      return const DriverPage(
        title: 'Charging accounts',
        body: Text('Account services are unavailable.'),
      );
    }
    return AnimatedBuilder(
      animation: Listenable.merge([
        controller,
        widget.dependencies.charging,
        widget.dependencies.vehicles,
      ]),
      builder: (context, _) {
        final account = controller.selected;
        final charging = widget.dependencies.charging;
        final canSwitch = !_busy && !charging.mutationInFlight;
        return DriverPage(
          title: 'Charging accounts',
          selectedIndex: 3,
          body: ListView(
            children: [
              const Text(
                'Choose the vehicles and wallet you want to use. Your login and personal profile stay the same.',
              ),
              const SizedBox(height: 16),
              if (controller.loading || _busy || _loadingDetails)
                const LinearProgressIndicator(),
              if (_error != null || controller.failure != null)
                VtsaErrorState(
                  title: 'Account request failed',
                  description: _error ?? controller.failure!.message,
                ),
              if (charging.hasUnfinishedSession) ...[
                const DriverNotice(
                  title: 'Your current charge keeps its original wallet',
                  message:
                      'You can switch accounts to browse vehicles, manage members or top up. Your current request stays linked to its original account until resolved.',
                ),
                TextButton.icon(
                  onPressed: () =>
                      context.push('/charging/${charging.session!.id}'),
                  icon: const Icon(Icons.ev_station_outlined),
                  label: const Text('View current charging request'),
                ),
              ],
              if (!canSwitch)
                const Text(
                  'Please wait for the current request to finish before switching accounts.',
                ),
              _AccountSection(
                title: 'Choose an account',
                subtitle:
                    'This selection sets the vehicles and wallet for your next charge.',
                children: [
                  RadioGroup<String>(
                    groupValue: account?.id,
                    onChanged: _selectAccount,
                    child: Column(
                      children: [
                        for (final item in controller.accounts)
                          Card(
                            child: RadioListTile<String>(
                              key: ValueKey('charging-account-${item.id}'),
                              value: item.id,
                              enabled: canSwitch,
                              selected: item.id == account?.id,
                              title: Text(item.name),
                              subtitle: Text(
                                '${item.typeLabel} · ${item.roleLabel}\n${item.description}',
                              ),
                              isThreeLine: true,
                            ),
                          ),
                      ],
                    ),
                  ),
                  if (controller.accounts.isEmpty && !controller.loading)
                    const Text(
                      'No accounts could be loaded. Refresh to try again.',
                    ),
                  Wrap(
                    spacing: 8,
                    children: [
                      TextButton.icon(
                        onPressed: canSwitch ? _create : null,
                        icon: const Icon(Icons.add_business_outlined),
                        label: const Text('Create business or fleet'),
                      ),
                      TextButton(
                        onPressed: _busy || _refreshing ? null : _refresh,
                        child: const Text('Refresh accounts'),
                      ),
                    ],
                  ),
                ],
              ),
              const SizedBox(height: 20),
              _AccountSection(
                title: 'Invitations for you',
                subtitle: 'Accept an invitation to join a shared account.',
                children: [
                  if (_incoming.isEmpty) const Text('No pending invitations.'),
                  for (final row in _incoming)
                    ListTile(
                      title: Text(row['account_name'] as String),
                      subtitle: Text(
                        'Join as ${chargingRoleLabel(row['role'] as String)} · expires ${_expiry(context, row['expires_at'] as String)}',
                      ),
                      trailing: TextButton(
                        onPressed: !canSwitch
                            ? null
                            : () => _run(() async {
                                final joinedId = row['account_id'] as String;
                                await controller.repository.request(
                                  '/invitations/${row['id']}/accept',
                                  method: 'POST',
                                );
                                if (!mounted) return;
                                await controller.load();
                                if (!mounted) return;
                                if (controller.accounts.any(
                                  (a) => a.id == joinedId,
                                )) {
                                  controller.select(joinedId);
                                }
                                await _details();
                              }),
                        child: const Text('Accept'),
                      ),
                    ),
                ],
              ),
              if (account != null) ...[
                const SizedBox(height: 24),
                Text(
                  'Using ${account.name}',
                  style: Theme.of(context).textTheme.titleLarge,
                ),
                const SizedBox(height: 8),
                Text(
                  account.personal
                      ? account.description
                      : '${account.roleLabel}: ${chargingRoleDescription(account.role)}',
                ),
                if (!account.personal && account.role != 'revoked') ...[
                  const SizedBox(height: 12),
                  DriverNotice(
                    title: account.can('vehicles')
                        ? 'Set up shared charging'
                        : account.can('wallet_fund')
                        ? 'Fund this account'
                        : 'Charge with this account',
                    message: account.can('vehicles')
                        ? 'Add vehicles and have members accept their invitations. Assign vehicles to drivers. The owner or billing manager funds this account wallet; fleet managers ask an owner or admin to invite members.'
                        : account.can('wallet_fund')
                        ? 'Open Account wallet to view its balance and add prepaid funds. These funds pay for this account’s charging only.'
                        : 'Ask the owner or fleet manager to assign a vehicle to you, and the owner or billing manager to fund the wallet. Then choose that vehicle before starting a charge.',
                  ),
                ],
                if (account.can('wallet_view'))
                  ListTile(
                    leading: const Icon(Icons.account_balance_wallet_outlined),
                    title: const Text('Account wallet'),
                    subtitle: Text(
                      account.personal
                          ? 'Private personal prepaid balance'
                          : 'Shared prepaid balance · funding by owner or billing manager',
                    ),
                    onTap: () => context.go('/wallet'),
                  ),
                if (account.can('vehicles') || account.can('charge'))
                  ListTile(
                    leading: const Icon(Icons.directions_car_outlined),
                    title: const Text('Account vehicles'),
                    subtitle: Text(
                      account.can('vehicles')
                          ? 'Manage vehicles and connector compatibility'
                          : 'View vehicles assigned to you',
                    ),
                    onTap: () => context.push('/vehicles'),
                  ),
                if (account.can('history') || account.can('charge'))
                  ListTile(
                    leading: const Icon(Icons.receipt_long_outlined),
                    title: Text(
                      account.role == 'driver'
                          ? 'My charging in this account'
                          : 'Account charging history',
                    ),
                    onTap: () => context.go('/activity'),
                  ),
                if (_members.isNotEmpty)
                  _AccountSection(
                    title: 'Members',
                    subtitle: 'Each person uses their own login.',
                    children: [
                      for (final row in _members)
                        ListTile(
                          title: Text(row['name'] as String),
                          subtitle: Text(
                            chargingRoleLabel(row['role'] as String),
                          ),
                          trailing:
                              account.role == 'owner' && row['role'] != 'owner'
                              ? PopupMenuButton<String>(
                                  enabled: !_busy,
                                  tooltip: 'Manage member',
                                  itemBuilder: (_) => [
                                    for (final role in [
                                      'admin',
                                      'billing_manager',
                                      'fleet_manager',
                                      'driver',
                                      'revoke',
                                    ])
                                      PopupMenuItem(
                                        value: role,
                                        child: Text(
                                          role == 'revoke'
                                              ? 'Remove member'
                                              : chargingRoleLabel(role),
                                        ),
                                      ),
                                  ],
                                  onSelected: (role) => _run(() async {
                                    if (role == 'revoke' &&
                                        !await showVtsaConfirmationDialog(
                                          context: context,
                                          title: 'Revoke account access?',
                                          description:
                                              'This member loses access immediately. Charging and billing history remain with the account.',
                                          confirmLabel: 'Revoke access',
                                        )) {
                                      return;
                                    }
                                    await controller.repository.request(
                                      '/${account.id}/members/${row['subject_id']}',
                                      method: 'PATCH',
                                      data: {
                                        'role': role == 'revoke' ? null : role,
                                      },
                                    );
                                    await _details();
                                  }),
                                )
                              : null,
                        ),
                    ],
                  ),
                if (!account.personal && account.can('members'))
                  _AccountSection(
                    title: 'Invite a member',
                    subtitle: 'Only registered users can be invited.',
                    children: [
                      TextButton.icon(
                        onPressed: _busy ? null : _invite,
                        icon: const Icon(Icons.person_add_outlined),
                        label: const Text('Invite member'),
                      ),
                      for (final row in _invitations)
                        ListTile(
                          title: Text(row['email'] as String),
                          subtitle: Text(
                            'Pending · ${chargingRoleLabel(row['role'] as String)}',
                          ),
                          trailing: TextButton(
                            onPressed: _busy
                                ? null
                                : () => _run(() async {
                                    await controller.repository.request(
                                      '/${account.id}/invitations/${row['id']}',
                                      method: 'DELETE',
                                    );
                                    await _details();
                                  }),
                            child: const Text('Revoke'),
                          ),
                        ),
                    ],
                  ),
                if (!account.personal && account.can('vehicles'))
                  TextButton.icon(
                    onPressed: _busy ? null : _assign,
                    icon: const Icon(Icons.assignment_ind_outlined),
                    label: const Text('Assign a vehicle to a driver'),
                  ),
                if (!account.personal && account.can('limits')) ...[
                  const SizedBox(height: 16),
                  Text(
                    'Per-session spending caps',
                    style: Theme.of(context).textTheme.titleMedium,
                  ),
                  const Text(
                    'Caps limit the reserved budget for each start. They are not monthly allowances or hardware shutoff guarantees.',
                  ),
                  for (final row in _limits)
                    ListTile(
                      title: Text(
                        '${row['target_type']}: ${_targetName(row['target_id'] as String)}',
                      ),
                      subtitle: Text(
                        '${walletMoney(row['amount_minor'] as int)} per session',
                      ),
                    ),
                  TextButton.icon(
                    onPressed: _busy ? null : _limit,
                    icon: const Icon(Icons.tune),
                    label: const Text('Set or remove a cap'),
                  ),
                ],
              ],
              const SizedBox(height: 24),
              const ExpansionTile(
                tilePadding: EdgeInsets.zero,
                title: Text('Which account should I use?'),
                children: [
                  ListTile(
                    title: Text('Personal'),
                    subtitle: Text(
                      'For your own charging. Your wallet and vehicles are private. You cannot invite members into Personal.',
                    ),
                  ),
                  ListTile(
                    title: Text('Business'),
                    subtitle: Text(
                      'For a company or team sharing vehicles and a company-funded wallet. Each person signs in with their own email.',
                    ),
                  ),
                  ListTile(
                    title: Text('Fleet'),
                    subtitle: Text(
                      'For a managed group of vehicles and drivers. Assign vehicles and set per-session spending caps. Business has these same sharing controls.',
                    ),
                  ),
                  ListTile(
                    subtitle: Text(
                      'These are separate accounts, not login types. Creating or switching accounts does not transfer vehicles, wallet funds or past sessions.',
                    ),
                  ),
                ],
              ),
            ],
          ),
        );
      },
    );
  }

  String _targetName(String id) =>
      _members.where((r) => r['subject_id'] == id).firstOrNull?['name']
          as String? ??
      widget.dependencies.vehicles.vehicles
          .where((v) => v.id == id)
          .firstOrNull
          ?.nickname ??
      id;

  String _expiry(BuildContext context, String value) {
    final date = DateTime.tryParse(value);
    return date == null
        ? 'unavailable'
        : MaterialLocalizations.of(context).formatShortDate(date.toLocal());
  }

  Future<void> _create() async {
    var name = '';
    var type = 'business';
    final form = GlobalKey<FormState>();
    final result = await showDialog<bool>(
      context: context,
      builder: (context) => StatefulBuilder(
        builder: (context, update) => AlertDialog(
          title: const Text('Create charging account'),
          scrollable: true,
          content: Form(
            key: form,
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                TextFormField(
                  onChanged: (value) => name = value,
                  validator: (value) => value == null || value.trim().isEmpty
                      ? 'Enter an account name.'
                      : null,
                  maxLength: 160,
                  decoration: const InputDecoration(labelText: 'Account name'),
                ),
                DropdownButtonFormField<String>(
                  initialValue: type,
                  decoration: const InputDecoration(labelText: 'Account type'),
                  items: [
                    for (final t in ['business', 'fleet'])
                      DropdownMenuItem(
                        value: t,
                        child: Text(t == 'fleet' ? 'Fleet' : 'Business'),
                      ),
                  ],
                  onChanged: (v) => update(() => type = v!),
                ),
                const SizedBox(height: 12),
                Text(
                  type == 'business'
                      ? 'Create a company wallet and vehicle list for your team.'
                      : 'Create a fleet wallet, add vehicles and assign them to drivers.',
                ),
                const SizedBox(height: 8),
                const Text(
                  'You become the owner. Personal funds and vehicles stay in Personal.',
                ),
              ],
            ),
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(context, false),
              child: const Text('Cancel'),
            ),
            TextButton(
              onPressed: () {
                if (form.currentState!.validate()) Navigator.pop(context, true);
              },
              child: const Text('Create'),
            ),
          ],
        ),
      ),
    );
    final text = name.trim();
    if (result == true && mounted) {
      await _run(() async {
        final owner = controller.ownerId();
        final created = await controller.repository.request(
          '',
          method: 'POST',
          data: {'name': text, 'type': type},
        );
        if (!mounted || controller.ownerId() != owner) return;
        controller.addAndSelect(
          ChargingAccount.fromJson(Map<String, dynamic>.from(created! as Map)),
        );
        await _details();
      });
    }
  }

  Future<void> _invite() async {
    var email = '';
    var role = 'driver';
    var sending = false;
    String? emailError;
    String? requestError;
    final form = GlobalKey<FormState>();
    final account = controller.selected!;
    final roles = controller.selected!.role == 'owner'
        ? ['driver', 'fleet_manager', 'billing_manager', 'admin']
        : ['driver', 'fleet_manager'];
    final result = await showDialog<bool>(
      context: context,
      builder: (context) => StatefulBuilder(
        builder: (context, update) => AlertDialog(
          title: const Text('Invite member'),
          scrollable: true,
          content: Form(
            key: form,
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                Text(
                  'Invite a registered Power Solutions user to ${account.name}. They keep their own login and personal wallet.',
                ),
                const SizedBox(height: 12),
                TextFormField(
                  enabled: !sending,
                  onChanged: (value) => update(() {
                    email = value;
                    emailError = null;
                  }),
                  validator: (value) =>
                      value == null ||
                          !RegExp(
                            r'^[^\s@]+@[^\s@]+\.[^\s@]+$',
                          ).hasMatch(value.trim())
                      ? 'Enter their sign-in email address.'
                      : null,
                  keyboardType: TextInputType.emailAddress,
                  decoration: InputDecoration(
                    labelText: 'Registered email address',
                    errorText: emailError,
                    errorMaxLines: 4,
                  ),
                ),
                const SizedBox(height: 16),
                DropdownButtonFormField<String>(
                  initialValue: role,
                  isExpanded: true,
                  decoration: const InputDecoration(labelText: 'Account role'),
                  items: [
                    for (final r in roles)
                      DropdownMenuItem(
                        value: r,
                        child: Text(chargingRoleLabel(r)),
                      ),
                  ],
                  onChanged: sending ? null : (v) => update(() => role = v!),
                ),
                const SizedBox(height: 12),
                Text(chargingRoleDescription(role)),
                const SizedBox(height: 8),
                const Text(
                  'Not registered yet? Ask them to register first. Existing users verify their email and accept in Charging accounts → Invitations for you.',
                ),
                if (requestError != null) ...[
                  const SizedBox(height: 12),
                  Text(
                    requestError!,
                    style: TextStyle(
                      color: Theme.of(context).colorScheme.error,
                    ),
                  ),
                ],
              ],
            ),
          ),
          actions: [
            TextButton(
              onPressed: sending ? null : () => Navigator.pop(context, false),
              child: const Text('Cancel'),
            ),
            TextButton(
              onPressed: sending
                  ? null
                  : () async {
                      if (!form.currentState!.validate()) return;
                      update(() {
                        sending = true;
                        requestError = null;
                        emailError = null;
                      });
                      try {
                        await controller.repository.request(
                          '/${account.id}/invitations',
                          method: 'POST',
                          data: {'email': email.trim(), 'role': role},
                        );
                        if (context.mounted) Navigator.pop(context, true);
                      } on Object catch (error) {
                        if (!context.mounted) return;
                        update(() {
                          sending = false;
                          if (error is AppFailure) {
                            emailError = error.fieldErrors['email']?.join('\n');
                            requestError = emailError == null
                                ? error.message
                                : null;
                          } else {
                            requestError =
                                'Could not send the invitation. Please try again.';
                          }
                        });
                      }
                    },
              child: Text(sending ? 'Sending…' : 'Send invitation'),
            ),
          ],
        ),
      ),
    );
    if (result == true && mounted) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text(
            'Invitation sent. The user must accept it in their app.',
          ),
        ),
      );
      await _details();
    }
  }

  Future<void> _assign() => _run(() async {
    await widget.dependencies.vehicles.load();
    if (!mounted) return;
    final vehicles = widget.dependencies.vehicles.vehicles;
    final drivers = _members.where((r) => r['role'] == 'driver').toList();
    if (vehicles.isEmpty || drivers.isEmpty) {
      setState(
        () => _error =
            'Add a vehicle and have a member accept the Driver role first. Owners, admins and fleet managers already use all account vehicles.',
      );
      return;
    }
    var vehicle = vehicles.first.id;
    var driver = drivers.first['subject_id'] as String;
    final account = controller.accountId;
    Future<List<String>> assignments(String id) async =>
        (await controller.repository.request(
                  '/$account/vehicles/$id/assignments',
                )
                as List)
            .cast<String>();
    var pending = assignments(vehicle);
    bool? assigned;
    final result = await showDialog<bool>(
      context: context,
      builder: (context) => StatefulBuilder(
        builder: (context, update) => AlertDialog(
          title: const Text('Vehicle assignment'),
          content: SingleChildScrollView(
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                DropdownButtonFormField<String>(
                  initialValue: vehicle,
                  isExpanded: true,
                  decoration: const InputDecoration(labelText: 'Vehicle'),
                  items: [
                    for (final v in vehicles)
                      DropdownMenuItem(value: v.id, child: Text(v.nickname)),
                  ],
                  onChanged: (v) => update(() {
                    vehicle = v!;
                    assigned = null;
                    pending = assignments(vehicle);
                  }),
                ),
                DropdownButtonFormField<String>(
                  initialValue: driver,
                  isExpanded: true,
                  decoration: const InputDecoration(labelText: 'Driver'),
                  items: [
                    for (final d in drivers)
                      DropdownMenuItem(
                        value: d['subject_id'] as String,
                        child: Text(d['name'] as String),
                      ),
                  ],
                  onChanged: (v) => update(() {
                    driver = v!;
                    assigned = null;
                  }),
                ),
                FutureBuilder<List<String>>(
                  future: pending,
                  builder: (context, snapshot) {
                    if (snapshot.hasError) {
                      return const Text(
                        'Could not load assignments. Close and try again.',
                      );
                    }
                    if (!snapshot.hasData) {
                      return const LinearProgressIndicator();
                    }
                    return SwitchListTile(
                      title: const Text('Allow this driver'),
                      value: assigned ?? snapshot.data!.contains(driver),
                      onChanged: (v) => update(() => assigned = v),
                    );
                  },
                ),
              ],
            ),
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(context, false),
              child: const Text('Cancel'),
            ),
            TextButton(
              onPressed: assigned == null
                  ? null
                  : () => Navigator.pop(context, true),
              child: const Text('Save'),
            ),
          ],
        ),
      ),
    );
    if (result == true) {
      await controller.repository.request(
        '/$account/vehicles/$vehicle/assignments',
        method: 'PUT',
        data: {'subject_id': driver, 'assigned': assigned},
      );
    }
  });

  Future<void> _limit() => _run(() async {
    await widget.dependencies.vehicles.load();
    if (!mounted) return;
    final targets = <String, String>{
      for (final r in _members.where((r) => r['role'] != 'billing_manager'))
        'driver:${r['subject_id']}': 'Driver: ${r['name']}',
      for (final v in widget.dependencies.vehicles.vehicles)
        'vehicle:${v.id}': 'Vehicle: ${v.nickname}',
    };
    if (targets.isEmpty) return;
    var target = targets.keys.first;
    String currentAmount(String key) {
      final parts = key.split(':');
      final row = _limits
          .where(
            (r) =>
                r['target_type'] == parts.first && r['target_id'] == parts.last,
          )
          .firstOrNull;
      if (row == null) return '';
      final minor = row['amount_minor'] as int;
      return '${minor ~/ 100}.${(minor % 100).toString().padLeft(2, '0')}';
    }

    var amount = currentAmount(target);
    final result = await showDialog<bool>(
      context: context,
      builder: (context) => StatefulBuilder(
        builder: (context, update) => AlertDialog(
          title: const Text('Per-session cap'),
          scrollable: true,
          content: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              DropdownButtonFormField<String>(
                initialValue: target,
                isExpanded: true,
                items: [
                  for (final t in targets.entries)
                    DropdownMenuItem(
                      value: t.key,
                      child: Text(t.value, overflow: TextOverflow.ellipsis),
                    ),
                ],
                onChanged: (v) => update(() {
                  target = v!;
                  amount = currentAmount(target);
                }),
              ),
              TextFormField(
                key: ValueKey(target),
                initialValue: amount,
                onChanged: (value) => amount = value,
                keyboardType: const TextInputType.numberWithOptions(
                  decimal: true,
                ),
                decoration: const InputDecoration(
                  labelText: 'PHP per session',
                  helperText: 'Blank removes cap; 0 blocks starts.',
                ),
              ),
            ],
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(context, false),
              child: const Text('Cancel'),
            ),
            TextButton(
              onPressed: () => Navigator.pop(context, true),
              child: const Text('Save'),
            ),
          ],
        ),
      ),
    );
    final input = amount.trim();
    if (result != true || !mounted) return;
    final minor = input.isEmpty ? null : parseWalletAmount(input);
    if (input.isNotEmpty && minor == null) {
      setState(() => _error = 'Enter a valid PHP amount.');
      return;
    }
    await controller.repository.request(
      '/${controller.accountId}/limits',
      method: 'PUT',
      data: {
        'target_type': target.split(':').first,
        'target_id': target.split(':').last,
        'amount_minor': minor,
      },
    );
    await _details();
  });
}

class _AccountSection extends StatelessWidget {
  const _AccountSection({
    required this.title,
    required this.subtitle,
    required this.children,
  });
  final String title;
  final String subtitle;
  final List<Widget> children;

  @override
  Widget build(BuildContext context) => Container(
    margin: const EdgeInsets.only(bottom: 16),
    padding: const EdgeInsets.all(16),
    decoration: BoxDecoration(
      color: Theme.of(context).colorScheme.surface,
      borderRadius: BorderRadius.circular(20),
      border: Border.all(color: Theme.of(context).colorScheme.outlineVariant),
    ),
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(title, style: Theme.of(context).textTheme.titleMedium),
        const SizedBox(height: 6),
        Text(subtitle, style: Theme.of(context).textTheme.bodyMedium),
        const SizedBox(height: 16),
        ...children,
      ],
    ),
  );
}
