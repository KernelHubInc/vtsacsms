import 'package:flutter/gestures.dart';
import 'package:flutter/material.dart';
import 'package:vtsa_mobile/design_system/components/evcs_access.dart';
import 'package:vtsa_mobile/design_system/theme/vtsa_tokens.dart';

class SwipeToStart extends StatefulWidget {
  const SwipeToStart({required this.onStart, this.enabled = true, super.key});
  final Future<void> Function() onStart;
  final bool enabled;

  @override
  State<SwipeToStart> createState() => _SwipeToStartState();
}

class _SwipeToStartState extends State<SwipeToStart> {
  double _distance = 0;
  bool _dragging = false;
  bool _pointerCancelled = false;
  bool _submitting = false;
  bool _button = false;

  bool get _enabled => widget.enabled && !_submitting;

  Future<void> _submit() async {
    if (!_enabled) return;
    setState(() {
      _submitting = true;
      _dragging = false;
    });
    try {
      await widget.onStart();
    } finally {
      if (mounted) {
        setState(() {
          _submitting = false;
          _distance = 0;
        });
      }
    }
  }

  @override
  void didUpdateWidget(SwipeToStart oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (!widget.enabled) {
      _distance = 0;
      _dragging = false;
    }
  }

  @override
  Widget build(BuildContext context) => Column(
    crossAxisAlignment: CrossAxisAlignment.stretch,
    children: [
      LayoutBuilder(
        builder: (context, constraints) {
          final travel = (constraints.maxWidth - 64).clamp(
            1.0,
            double.infinity,
          );
          final height =
              64 +
              (MediaQuery.textScalerOf(context).scale(1) - 1)
                      .clamp(0.0, 2.0)
                      .toDouble() *
                  32;
          return Semantics(
            label: 'Swipe to start charging',
            hint:
                'Drag the white handle to the right. A button alternative is below.',
            enabled: _enabled,
            child: Listener(
              onPointerDown: (_) => _pointerCancelled = false,
              onPointerCancel: (_) => setState(() {
                _pointerCancelled = true;
                _dragging = false;
                _distance = 0;
              }),
              child: GestureDetector(
                dragStartBehavior: DragStartBehavior.down,
                key: const ValueKey('charging-start-swipe'),
                onHorizontalDragStart: !_enabled
                    ? null
                    : (details) {
                        _dragging = details.localPosition.dx <= 64;
                        setState(() => _distance = 0);
                      },
                onHorizontalDragUpdate: !_enabled
                    ? null
                    : (details) {
                        if (_dragging) {
                          setState(
                            () => _distance = (_distance + details.delta.dx)
                                .clamp(0, travel),
                          );
                        }
                      },
                onHorizontalDragCancel: () => setState(() {
                  _distance = 0;
                  _dragging = false;
                }),
                onHorizontalDragEnd: !_enabled
                    ? null
                    : (_) {
                        if (!_pointerCancelled &&
                            _dragging &&
                            _distance >= travel * .9) {
                          _submit();
                        } else {
                          setState(() {
                            _distance = 0;
                            _dragging = false;
                          });
                        }
                      },
                child: AnimatedOpacity(
                  opacity: _enabled || _submitting ? 1 : .45,
                  duration: const Duration(milliseconds: 150),
                  child: Container(
                    height: height,
                    decoration: BoxDecoration(
                      color: PowerSolutionsColors.brandNavy,
                      borderRadius: BorderRadius.circular(999),
                    ),
                    child: Stack(
                      alignment: Alignment.center,
                      children: [
                        Padding(
                          padding: const EdgeInsets.only(left: 72, right: 16),
                          child: Text(
                            _submitting
                                ? 'Requesting start…'
                                : 'Swipe to start charging',
                            textAlign: TextAlign.center,
                            style: const TextStyle(
                              color: Colors.white,
                              fontSize: 16,
                              fontWeight: FontWeight.w600,
                            ),
                          ),
                        ),
                        Positioned(
                          left: 6 + _distance.clamp(0, travel),
                          top: (height - 52) / 2,
                          child: Container(
                            width: 52,
                            height: 52,
                            decoration: const BoxDecoration(
                              color: Colors.white,
                              shape: BoxShape.circle,
                            ),
                            child: Center(
                              child: _submitting
                                  ? const SizedBox.square(
                                      dimension: 22,
                                      child: CircularProgressIndicator(
                                        strokeWidth: 2,
                                      ),
                                    )
                                  : const EvcsIcon('charging-arrow'),
                            ),
                          ),
                        ),
                      ],
                    ),
                  ),
                ),
              ),
            ),
          );
        },
      ),
      if (_button)
        Padding(
          padding: const EdgeInsets.only(top: 12),
          child: FilledButton(
            onPressed: _enabled ? _submit : null,
            child: const Text('Start charging'),
          ),
        )
      else
        TextButton(
          onPressed: _enabled ? () => setState(() => _button = true) : null,
          child: const Text(
            'Can’t swipe? Start with a button',
            textAlign: TextAlign.center,
          ),
        ),
    ],
  );
}
