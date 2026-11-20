import 'package:flutter/material.dart';
import 'package:image_picker/image_picker.dart';
import 'package:vtsa_mobile/design_system/components/vtsa_components.dart';
import 'package:vtsa_mobile/features/charging/domain/support_image.dart';

class SupportImagePicker extends StatefulWidget {
  const SupportImagePicker({
    required this.onChanged,
    this.enabled = true,
    this.picker,
    super.key,
  });

  final ValueChanged<List<SupportImage>> onChanged;
  final bool enabled;
  final ImagePicker? picker;

  @override
  State<SupportImagePicker> createState() => _SupportImagePickerState();
}

class _SupportImagePickerState extends State<SupportImagePicker> {
  final _images = <SupportImage>[];
  bool _picking = false;

  Future<void> _pick() async {
    if (_picking || !widget.enabled) return;
    setState(() => _picking = true);
    try {
      final files = await (widget.picker ?? ImagePicker()).pickMultiImage(
        maxWidth: 3000,
        maxHeight: 3000,
        imageQuality: 85,
        requestFullMetadata: false,
      );
      if (!mounted) return;
      if (_images.length + files.length > 5) {
        showVtsaAlert(context, message: 'Choose up to five images per ticket.');
        return;
      }
      final selected = <SupportImage>[];
      for (final file in files) {
        if (await file.length() > 2 * 1024 * 1024) {
          if (mounted) {
            showVtsaAlert(
              context,
              message: 'Each image must be 2 MB or smaller.',
            );
          }
          return;
        }
        final bytes = await file.readAsBytes();
        final jpeg = bytes.length > 2 && bytes[0] == 0xff && bytes[1] == 0xd8;
        final png =
            bytes.length > 8 &&
            bytes[0] == 0x89 &&
            bytes[1] == 0x50 &&
            bytes[2] == 0x4e &&
            bytes[3] == 0x47;
        if (!jpeg && !png) {
          if (mounted) {
            showVtsaAlert(context, message: 'Choose JPEG or PNG images.');
          }
          return;
        }
        selected.add(
          SupportImage(
            bytes: bytes,
            name: 'support-image.${png ? 'png' : 'jpg'}',
          ),
        );
      }
      if (!mounted) return;
      setState(() => _images.addAll(selected));
      widget.onChanged(List.unmodifiable(_images));
    } on Object {
      if (mounted) {
        showVtsaAlert(
          context,
          message: 'Images could not be opened. Please try again.',
        );
      }
    } finally {
      if (mounted) setState(() => _picking = false);
    }
  }

  @override
  Widget build(BuildContext context) => Column(
    crossAxisAlignment: CrossAxisAlignment.start,
    children: [
      TextButton.icon(
        onPressed: widget.enabled && !_picking && _images.length < 5
            ? _pick
            : null,
        icon: const Icon(Icons.add_photo_alternate_outlined),
        label: Text('Add images (${_images.length}/5)'),
      ),
      const Text(
        'Optional: JPEG or PNG, up to 2 MB each. Avoid passwords, payment details and identity documents.',
      ),
      if (_images.isNotEmpty)
        Wrap(
          spacing: 10,
          runSpacing: 10,
          children: [
            for (var index = 0; index < _images.length; index++)
              SizedBox(
                width: 100,
                child: Column(
                  children: [
                    ClipRRect(
                      borderRadius: BorderRadius.circular(12),
                      child: Image.memory(
                        _images[index].bytes,
                        width: 100,
                        height: 90,
                        fit: BoxFit.cover,
                        cacheWidth: 200,
                      ),
                    ),
                    TextButton(
                      onPressed: widget.enabled
                          ? () {
                              setState(() => _images.removeAt(index));
                              widget.onChanged(List.unmodifiable(_images));
                            }
                          : null,
                      child: Text('Remove ${index + 1}'),
                    ),
                  ],
                ),
              ),
          ],
        ),
    ],
  );
}
