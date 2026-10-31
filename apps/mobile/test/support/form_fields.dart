import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:vtsa_mobile/design_system/components/vtsa_components.dart';

Finder textInput(String label) => find.descendant(
  of: find.byWidgetPredicate(
    (widget) => widget is VtsaTextField && widget.label == label,
  ),
  matching: find.byType(TextField),
);
