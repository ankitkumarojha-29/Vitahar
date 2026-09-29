# Flutter Wrapper
-keep class io.flutter.app.** { *; }
-keep class io.flutter.plugin.**  { *; }
-keep class io.flutter.util.**  { *; }
-keep class io.flutter.view.**  { *; }
-keep class io.flutter.**  { *; }
-keep class io.flutter.plugins.**  { *; }

# Mobile Scanner Plugin
-keep class dev.steenbakker.mobile_scanner.** { *; }
-keepclassmembers class dev.steenbakker.mobile_scanner.** { *; }
-dontwarn dev.steenbakker.mobile_scanner.**

# Google ML Kit Barcode Scanning & Common
-keep class com.google.mlkit.** { *; }
-keep interface com.google.mlkit.** { *; }
-keep public class * extends com.google.mlkit.common.sdkinternal.ComponentRegistrar {
    public <init>();
}
-keep class com.google.mlkit.common.internal.** { *; }
-keep class com.google.mlkit.common.sdkinternal.** { *; }
-keep class com.google.mlkit.vision.barcode.** { *; }
-keep class com.google.mlkit.vision.barcode.internal.** { *; }
-keep class com.google.mlkit.vision.common.** { *; }
-keep class com.google.android.gms.internal.mlkit_vision_barcode.** { *; }
-keep class com.google.android.gms.vision.** { *; }
-dontwarn com.google.mlkit.**
-dontwarn com.google.android.gms.**

# CameraX
-keep class androidx.camera.** { *; }
-keep interface androidx.camera.** { *; }
-keep class androidx.camera.core.** { *; }
-keep class androidx.camera.lifecycle.** { *; }
-keep class androidx.camera.camera2.** { *; }
-dontwarn androidx.camera.**
