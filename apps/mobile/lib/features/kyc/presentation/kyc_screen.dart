import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:image_picker/image_picker.dart';
import 'package:url_launcher/url_launcher.dart';
import 'package:vtsa_mobile/design_system/components/vtsa_components.dart';
import 'package:vtsa_mobile/design_system/theme/vtsa_tokens.dart';
import 'package:vtsa_mobile/features/kyc/application/kyc_controller.dart';
import 'package:vtsa_mobile/features/kyc/data/capture_cleanup.dart';
import 'package:vtsa_mobile/features/kyc/domain/kyc_repository.dart';
import 'package:vtsa_mobile/features/kyc/domain/kyc_verification.dart';
import 'package:vtsa_mobile/features/kyc/presentation/live_capture_screen.dart';

enum _Step { overview, consent, personal, document, capture, review }

class KycScreen extends StatefulWidget {
  const KycScreen({
    required this.repository,
    this.name = '',
    this.picker,
    super.key,
  });
  final KycRepository? repository;
  final String name;
  final ImagePicker? picker;

  @override
  State<KycScreen> createState() => _KycScreenState();
}

class _KycScreenState extends State<KycScreen> with WidgetsBindingObserver {
  KycController? controller;
  late final ImagePicker picker;
  final name = TextEditingController();
  final birth = TextEditingController();
  final country = TextEditingController();
  final nationality = TextEditingController();
  final number = TextEditingController();
  final expiration = TextEditingController();
  _Step step = _Step.overview;
  String? document;
  bool consented = false;
  bool capturing = false;
  String? captureError;
  String? captureKind;
  Uint8List? preview;
  Timer? poll;

  @override
  void initState() {
    super.initState();
    picker = widget.picker ?? ImagePicker();
    name.text = widget.name;
    WidgetsBinding.instance.addObserver(this);
    if (widget.repository case final repository?) {
      controller = KycController(repository)..addListener(changed);
      unawaited(controller!.refresh());
    }
    unawaited(discardLostCapture());
    poll = Timer.periodic(const Duration(seconds: 15), (_) {
      if (WidgetsBinding.instance.lifecycleState == AppLifecycleState.resumed &&
          controller?.verification?.status.pending == true) {
        unawaited(controller!.refresh());
      }
    });
  }

  void changed() {
    if (mounted) setState(() {});
  }

  Future<void> discardLostCapture() async {
    if (kIsWeb || defaultTargetPlatform != TargetPlatform.android) return;
    try {
      final lost = await picker.retrieveLostData();
      for (final file in lost.files ?? <XFile>[]) {
        await deleteTemporaryCapture(file.path);
      }
      if (!lost.isEmpty && mounted) {
        setState(
          () => captureError =
              'Your camera session was interrupted. Please retake the photo.',
        );
      }
    } catch (_) {
      if (mounted) {
        setState(
          () => captureError =
              'Camera recovery was unavailable. Please retake your photo.',
        );
      }
    }
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed &&
        !capturing &&
        controller != null) {
      unawaited(controller!.refresh());
    }
    if (state == AppLifecycleState.paused && !capturing) {
      if (preview != null) unawaited(MemoryImage(preview!).evict());
      preview?.fillRange(0, preview!.length, 0);
      preview = null;
    }
  }

  @override
  void dispose() {
    poll?.cancel();
    WidgetsBinding.instance.removeObserver(this);
    controller?.removeListener(changed);
    controller?.dispose();
    if (preview != null) unawaited(MemoryImage(preview!).evict());
    preview?.fillRange(0, preview!.length, 0);
    for (final field in [
      name,
      birth,
      country,
      nationality,
      number,
      expiration,
    ]) {
      field.dispose();
    }
    super.dispose();
  }

  Map<String, dynamic> get selectedDocument => Map<String, dynamic>.from(
    controller?.documents[step == _Step.document
                ? document
                : controller?.verification?.documentType ?? document]
            as Map? ??
        {},
  );

  List<String> get requiredPhotos => [
    if (selectedDocument['front'] == true) 'front',
    if (selectedDocument['back'] == true) 'back',
    'selfie',
  ];

  Future<void> capture(String kind) async {
    setState(() {
      capturing = true;
      captureError = null;
      captureKind = kind;
    });
    try {
      final file = await picker.pickImage(
        source: ImageSource.camera,
        preferredCameraDevice: kind == 'selfie'
            ? CameraDevice.front
            : CameraDevice.rear,
        maxWidth: 2400,
        maxHeight: 2400,
        imageQuality: 90,
        requestFullMetadata: false,
      );
      if (file == null) return;
      Uint8List bytes;
      try {
        if (await file.length() > 6 * 1024 * 1024) {
          throw const FormatException(
            'The photo is too large. Retake it at a lower resolution.',
          );
        }
        bytes = await file.readAsBytes();
      } finally {
        await deleteTemporaryCapture(file.path);
      }
      if (!mounted) {
        bytes.fillRange(0, bytes.length, 0);
        return;
      }
      setState(() => preview = bytes);
    } on PlatformException {
      if (mounted) {
        setState(
          () => captureError =
              'Camera access is unavailable. Allow camera permission in your device settings, then try again.',
        );
      }
    } on FormatException catch (error) {
      if (mounted) setState(() => captureError = error.message);
    } catch (_) {
      if (mounted) {
        setState(
          () => captureError =
              'The photo could not be read securely. Please try again.',
        );
      }
    } finally {
      if (mounted) setState(() => capturing = false);
    }
  }

  Future<void> upload() async {
    final bytes = preview;
    if (bytes == null) return;
    final success = await controller!.upload(captureKind!, bytes);
    if (success) {
      await MemoryImage(bytes).evict();
      bytes.fillRange(0, bytes.length, 0);
      if (mounted) setState(() => preview = null);
    }
  }

  Future<void> openPolicy(Object? value) async {
    final uri = Uri.tryParse(value?.toString() ?? '');
    if (uri != null && uri.scheme == 'https') {
      await launchUrl(uri, mode: LaunchMode.externalApplication);
    }
  }

  @override
  Widget build(BuildContext context) {
    final kyc = controller;
    return VtsaPageShell(
      eyebrow: 'POWER SOLUTIONS · IDENTITY',
      title: 'Identity verification',
      body: kyc == null
          ? const Center(
              child: Text(
                'Identity verification is unavailable in this environment.',
              ),
            )
          : ListView(
              padding: const EdgeInsets.only(bottom: 32),
              children: [
                if (step != _Step.overview) ...[
                  LinearProgressIndicator(value: step.index / 5),
                  const SizedBox(height: VtsaSpacing.lg),
                ],
                if (kyc.error != null || captureError != null)
                  Semantics(
                    liveRegion: true,
                    child: Padding(
                      padding: const EdgeInsets.only(bottom: 16),
                      child: Text(
                        captureError ?? kyc.error!,
                        style: TextStyle(
                          color: Theme.of(context).colorScheme.error,
                        ),
                      ),
                    ),
                  ),
                if (kyc.verification == null && kyc.busy)
                  const Center(child: CircularProgressIndicator())
                else
                  switch (step) {
                    _Step.overview => overview(kyc),
                    _Step.consent => consentPage(kyc),
                    _Step.personal => personalPage(),
                    _Step.document => documentPage(kyc),
                    _Step.capture => capturePage(kyc),
                    _Step.review => reviewPage(kyc),
                  },
                if (step != _Step.overview)
                  TextButton(
                    onPressed: kyc.busy
                        ? null
                        : () => setState(() => step = _Step.overview),
                    child: const Text('Return to verification overview'),
                  ),
              ],
            ),
    );
  }

  Widget overview(KycController kyc) {
    final status = kyc.verification?.status ?? KycStatus.notStarted;
    return VtsaCard(
      eyebrow: 'YOUR VERIFICATION',
      title: status.label,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(
            status == KycStatus.approved
                ? Icons.verified_outlined
                : Icons.shield_outlined,
            size: 48,
            color: Theme.of(context).colorScheme.primary,
          ),
          const SizedBox(height: 20),
          Text(switch (status) {
            KycStatus.approved =>
              'Your identity verification has been approved.',
            KycStatus.needsReview =>
              'Your verification is with our review team. You can return here to see the result.',
            KycStatus.processing || KycStatus.submitted =>
              'Your photos are being checked securely. You may leave this screen and return later.',
            KycStatus.actionRequired =>
              'We need new information or clearer photos. Start a new submission below.',
            KycStatus.rejected =>
              'Your verification was not approved. You can submit a new application with updated information.',
            KycStatus.expired =>
              'Your verification has expired. Complete a new application to renew it.',
            KycStatus.inProgress =>
              'Your previous setup was interrupted. Cancel this draft and start again to review your consent and information.',
            _ =>
              'Have a government-issued identity document ready. We will ask for clear photos of the document and a selfie.',
          }),
          if (kyc.verification?.unavailable == true)
            const Padding(
              padding: EdgeInsets.only(top: 16),
              child: Text(
                'The verification service is temporarily unavailable. Your last saved status is shown.',
              ),
            ),
          const SizedBox(height: 24),
          if (!kyc.enabled)
            const Text('Identity verification is not enabled yet.'),
          if (kyc.enabled &&
              (status == KycStatus.notStarted || status.canResubmit))
            VtsaButton(
              label: status.canResubmit
                  ? 'Start a new submission'
                  : 'Get started',
              onPressed: () => setState(() {
                kyc.beginAttempt();
                consented = false;
                document = null;
                step = _Step.consent;
              }),
            ),
          if (status == KycStatus.pendingUpload)
            VtsaButton(
              label: 'Continue with photos',
              onPressed: () => setState(() => step = _Step.capture),
            ),
          const SizedBox(height: 12),
          VtsaButton(
            label: 'Refresh status',
            variant: VtsaButtonVariant.secondary,
            loading: kyc.busy,
            onPressed: kyc.refresh,
          ),
          if (kyc.verification?.id != null &&
              !const {KycStatus.cancelled, KycStatus.expired}.contains(status))
            TextButton(
              onPressed: kyc.busy
                  ? null
                  : () async {
                      final confirmed = await showVtsaConfirmationDialog(
                        context: context,
                        title: 'Withdraw verification consent?',
                        description:
                            'Your verification will be cancelled and evidence scheduled for deletion. You can start again later.',
                        confirmLabel: 'Withdraw consent',
                      );
                      if (confirmed) await kyc.cancel();
                    },
              child: const Text('Withdraw consent and delete evidence'),
            ),
        ],
      ),
    );
  }

  Widget consentPage(KycController kyc) => VtsaCard(
    title: 'Your information, handled with care',
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(kyc.consent['text']?.toString() ?? ''),
        const SizedBox(height: 16),
        Text(
          'Identity images are held privately for up to ${kyc.consent['retention_days']} days under the configured policy. Verification and audit records may be retained separately. You can withdraw consent from the overview.',
        ),
        Wrap(
          children: [
            for (final entry in {
              'privacy_url': 'Privacy policy',
              'terms_url': 'Terms',
              'consent_url': 'KYC consent',
            }.entries)
              if (kyc.consent[entry.key] != null)
                TextButton(
                  onPressed: () => openPolicy(kyc.consent[entry.key]),
                  child: Text(entry.value),
                ),
          ],
        ),
        CheckboxListTile(
          contentPadding: EdgeInsets.zero,
          value: consented,
          title: const Text(
            'I have read and agree to this verification consent.',
          ),
          onChanged: (value) => setState(() => consented = value ?? false),
        ),
        VtsaButton(
          label: 'Continue',
          onPressed: consented
              ? () => setState(() => step = _Step.personal)
              : null,
        ),
      ],
    ),
  );

  Widget personalPage() => VtsaCard(
    title: 'Review your details',
    child: Column(
      children: [
        VtsaTextField(
          label: 'Full legal name',
          controller: name,
          required: true,
        ),
        const SizedBox(height: 16),
        VtsaTextField(
          label: 'Date of birth',
          controller: birth,
          hint: 'YYYY-MM-DD',
          required: true,
        ),
        const SizedBox(height: 16),
        VtsaTextField(
          label: 'Issuing country',
          controller: country,
          hint: 'Two-letter code, for example PH',
          required: true,
        ),
        const SizedBox(height: 16),
        VtsaTextField(
          label: 'Nationality',
          controller: nationality,
          hint: 'Two-letter code, required for a passport',
        ),
        const SizedBox(height: 24),
        VtsaButton(
          label: 'Choose identity document',
          onPressed: () {
            if (name.text.trim().length < 2 ||
                DateTime.tryParse(birth.text) == null ||
                country.text.trim().length != 2) {
              setState(
                () => captureError =
                    'Enter your name, birth date (YYYY-MM-DD), and two-letter country code.',
              );
              return;
            }
            setState(() {
              captureError = null;
              step = _Step.document;
            });
          },
        ),
      ],
    ),
  );

  Widget documentPage(KycController kyc) => VtsaCard(
    title: 'Choose your document',
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        for (final entry in kyc.documents.entries)
          Padding(
            padding: const EdgeInsets.only(bottom: 12),
            child: OutlinedButton(
              onPressed: kyc.busy
                  ? null
                  : () => setState(() => document = entry.key),
              child: Row(
                children: [
                  Icon(
                    document == entry.key
                        ? Icons.radio_button_checked
                        : Icons.radio_button_off,
                  ),
                  const SizedBox(width: 12),
                  Expanded(
                    child: Text((entry.value as Map)['label'] as String),
                  ),
                ],
              ),
            ),
          ),
        VtsaTextField(
          label: 'Document number',
          controller: number,
          required: selectedDocument['document_number'] == true,
        ),
        const SizedBox(height: 16),
        if (selectedDocument['expiration_date'] == true)
          VtsaTextField(
            label: 'Expiration date',
            controller: expiration,
            hint: 'YYYY-MM-DD',
            required: true,
          ),
        const SizedBox(height: 24),
        VtsaButton(
          label: 'Continue to photos',
          loading: kyc.busy,
          onPressed: document == null
              ? null
              : () async {
                  final success = await kyc.start(document!, {
                    'full_name': name.text.trim(),
                    'birth_date': birth.text.trim(),
                    'issuing_country': country.text.trim().toUpperCase(),
                    if (nationality.text.isNotEmpty)
                      'nationality': nationality.text.trim().toUpperCase(),
                    if (number.text.isNotEmpty)
                      'document_number': number.text.trim(),
                    if (selectedDocument['expiration_date'] == true)
                      'expiration_date': expiration.text.trim(),
                  });
                  if (success && mounted) {
                    for (final field in [
                      name,
                      birth,
                      country,
                      nationality,
                      number,
                      expiration,
                    ]) {
                      field.clear();
                    }
                    setState(() => step = _Step.capture);
                  }
                },
        ),
      ],
    ),
  );

  Widget capturePage(KycController kyc) {
    final uploaded = kyc.verification?.uploaded ?? {};
    return VtsaCard(
      title: preview != null ? 'Check your photo' : 'Add clear photos',
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            kyc.verification?.liveCaptureRequired == true
                ? 'Use even lighting and keep every document edge visible. Then complete a short live camera check by following the head-turn prompts.'
                : 'Use even lighting and keep every edge visible. For your selfie, face the camera without covering your face.',
          ),
          const SizedBox(height: 20),
          if (preview case final bytes?) ...[
            ClipRRect(
              borderRadius: BorderRadius.circular(16),
              child: Image.memory(
                bytes,
                height: 280,
                width: double.infinity,
                fit: BoxFit.contain,
                gaplessPlayback: true,
              ),
            ),
            const SizedBox(height: 20),
            VtsaButton(
              label: 'Use photo and upload',
              loading: kyc.busy,
              onPressed: upload,
            ),
            TextButton(
              onPressed: kyc.busy
                  ? null
                  : () {
                      unawaited(MemoryImage(bytes).evict());
                      bytes.fillRange(0, bytes.length, 0);
                      setState(() => preview = null);
                    },
              child: const Text('Retake photo'),
            ),
          ] else ...[
            for (final kind in requiredPhotos)
              ListTile(
                contentPadding: EdgeInsets.zero,
                leading: Icon(
                  uploaded.contains(kind)
                      ? Icons.check_circle_outline
                      : kind == 'selfie'
                      ? Icons.face_outlined
                      : Icons.badge_outlined,
                ),
                title: Text(
                  kind == 'selfie'
                      ? (kyc.verification?.liveCaptureRequired == true
                            ? 'Live camera check'
                            : 'Selfie')
                      : 'Document $kind',
                ),
                subtitle: Text(
                  uploaded.contains(kind)
                      ? 'Uploaded securely'
                      : 'Ready to capture',
                ),
                trailing: TextButton(
                  onPressed: capturing || kyc.busy
                      ? null
                      : () async {
                          if (kind == 'selfie' &&
                              kyc.verification?.liveCaptureRequired == true) {
                            setState(() => capturing = true);
                            try {
                              await Navigator.of(context).push<bool>(
                                MaterialPageRoute(
                                  builder: (_) => LiveCaptureScreen(
                                    repository: kyc.repository,
                                    verificationId: kyc.verification!.id!,
                                  ),
                                ),
                              );
                              await kyc.refresh();
                            } finally {
                              if (mounted) setState(() => capturing = false);
                            }
                          } else {
                            await capture(kind);
                          }
                        },
                  child: Text(uploaded.contains(kind) ? 'Retake' : 'Capture'),
                ),
              ),
            if (capturing) const LinearProgressIndicator(),
            const SizedBox(height: 20),
            VtsaButton(
              label: 'Review submission',
              onPressed: requiredPhotos.every(uploaded.contains)
                  ? () => setState(() => step = _Step.review)
                  : null,
            ),
          ],
        ],
      ),
    );
  }

  Widget reviewPage(KycController kyc) => VtsaCard(
    title: 'Ready to submit',
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        const Text(
          'Your document photos and selfie have been uploaded privately. Submit them to begin verification. We will show the result here.',
        ),
        const SizedBox(height: 20),
        for (final kind in requiredPhotos)
          ListTile(
            leading: const Icon(Icons.check_circle_outline),
            title: Text(
              kind == 'selfie' ? 'Selfie uploaded' : 'Document $kind uploaded',
            ),
          ),
        const SizedBox(height: 20),
        VtsaButton(
          label: 'Submit verification',
          loading: kyc.busy,
          onPressed: () async {
            if (await kyc.submit() && mounted) {
              setState(() => step = _Step.overview);
            }
          },
        ),
      ],
    ),
  );
}
