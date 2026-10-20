package com.vtsa.vtsa_mobile

import io.flutter.embedding.android.FlutterFragmentActivity
import io.flutter.embedding.engine.FlutterEngine
import io.flutter.plugin.common.MethodChannel

class MainActivity : FlutterFragmentActivity() {
    override fun configureFlutterEngine(flutterEngine: FlutterEngine) {
        super.configureFlutterEngine(flutterEngine)
        MethodChannel(flutterEngine.dartExecutor.binaryMessenger, "com.vtsa.vtsa_mobile/payment_apps")
            .setMethodCallHandler { call, result ->
                if (call.method != "open") {
                    result.notImplemented()
                    return@setMethodCallHandler
                }

                val packageName = call.argument<String>("package")
                if (packageName !in setOf("com.globe.gcash.android", "com.paymaya")) {
                    result.error("unsupported_app", "Unsupported payment app", null)
                    return@setMethodCallHandler
                }

                val launch = packageManager.getLaunchIntentForPackage(packageName!!)
                if (launch == null) {
                    result.success(false)
                } else {
                    startActivity(launch)
                    result.success(true)
                }
            }
    }
}
