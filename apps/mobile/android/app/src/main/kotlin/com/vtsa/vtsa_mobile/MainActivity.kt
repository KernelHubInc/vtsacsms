package com.vtsa.vtsa_mobile

import android.app.NotificationChannel
import android.app.NotificationManager
import android.os.Build

import io.flutter.embedding.android.FlutterFragmentActivity
import io.flutter.embedding.engine.FlutterEngine
import io.flutter.plugin.common.MethodChannel

class MainActivity : FlutterFragmentActivity() {
    override fun configureFlutterEngine(flutterEngine: FlutterEngine) {
        super.configureFlutterEngine(flutterEngine)
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            val channel = NotificationChannel("driver_notices", "Driver updates", NotificationManager.IMPORTANCE_HIGH)
            channel.description = "Maintenance, announcements and promotions from Power Solutions"
            getSystemService(NotificationManager::class.java).createNotificationChannel(channel)
        }
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
