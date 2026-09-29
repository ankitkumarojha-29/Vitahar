import 'dart:async';
import 'package:flutter/material.dart';
import 'package:mobile_scanner/mobile_scanner.dart';
import '../services/api_service.dart';

/// Scanner operational states for strict lifecycle management
enum ScannerFlowState {
  idle,
  checkingPermission,
  initializing,
  ready,
  error,
}

class ScannerScreen extends StatefulWidget {
  const ScannerScreen({super.key});

  @override
  State<ScannerScreen> createState() => _ScannerScreenState();
}

class _ScannerScreenState extends State<ScannerScreen>
    with WidgetsBindingObserver, SingleTickerProviderStateMixin {
  // Single active camera controller
  MobileScannerController? _controller;
  StreamSubscription<BarcodeCapture>? _barcodeSubscription;

  ScannerFlowState _flowState = ScannerFlowState.idle;
  bool _isInitializing = false;
  bool _isProcessingBarcode = false;
  bool _isTorchOn = false;
  bool _isPermissionDenied = false;
  String _errorMessage = "";
  String _statusText = "Align barcode within the frame";

  // Animated scan line in warm amber-orange
  late AnimationController _animationController;
  late Animation<double> _scanLineAnimation;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);

    _animationController = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 2200),
    )..repeat(reverse: true);

    _scanLineAnimation = Tween<double>(begin: 0.05, end: 0.95).animate(
      CurvedAnimation(parent: _animationController, curve: Curves.easeInOut),
    );

    // Initialize camera once widget is mounted
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (mounted) {
        _startCameraInit();
      }
    });
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    // Prevent unneeded operations if controller is not initialized
    final controller = _controller;
    if (controller == null || !controller.value.isInitialized) {
      return;
    }

    switch (state) {
      case AppLifecycleState.detached:
      case AppLifecycleState.hidden:
      case AppLifecycleState.paused:
        _stopCamera();
        break;
      case AppLifecycleState.inactive:
        // App is losing focus (e.g. system permission dialog or phone call)
        _stopCamera();
        break;
      case AppLifecycleState.resumed:
        // App came back to foreground - safely restart camera
        if (_flowState == ScannerFlowState.ready && !_isProcessingBarcode) {
          _restartCamera();
        }
        break;
    }
  }

  Future<void> _cleanupController() async {
    try {
      await _barcodeSubscription?.cancel();
    } catch (_) {}
    _barcodeSubscription = null;

    final controller = _controller;
    _controller = null;

    if (controller != null) {
      try {
        await controller.stop();
      } catch (_) {}
      try {
        await controller.dispose();
      } catch (_) {}
    }
  }

  Future<void> _stopCamera() async {
    try {
      await _barcodeSubscription?.cancel();
    } catch (_) {}
    _barcodeSubscription = null;

    try {
      if (_controller != null && _controller!.value.isRunning) {
        await _controller!.stop();
      }
    } catch (_) {}
  }

  Future<void> _restartCamera() async {
    if (!mounted || _isInitializing || _isProcessingBarcode) return;
    try {
      if (_controller != null && !_controller!.value.isRunning) {
        await _controller!.start();
        _barcodeSubscription = _controller!.barcodes.listen(_handleBarcodeCapture);
      } else if (_controller == null) {
        await _startCameraInit();
      }
    } catch (_) {
      // If restart fails, do a clean reinitialization
      _startCameraInit();
    }
  }

  /// Bulletproof camera initialization flow:
  /// IDLE -> CHECK PERMISSION -> INITIALIZING -> READY -> SCANNING
  Future<void> _startCameraInit() async {
    if (!mounted) return;

    // Prevent concurrent initialization attempts
    if (_isInitializing) return;

    setState(() {
      _isInitializing = true;
      _flowState = ScannerFlowState.initializing;
      _errorMessage = "";
      _isPermissionDenied = false;
      _statusText = "Starting camera...";
    });

    try {
      // Step 1: Safely dispose and release any previous camera locks
      await _cleanupController();

      if (!mounted) return;

      // Step 2: Create ONE fresh controller
      final controller = MobileScannerController(
        autoStart: false,
        facing: CameraFacing.back,
        torchEnabled: false,
        detectionSpeed: DetectionSpeed.normal,
        useNewCameraSelector: true,
        formats: const [
          BarcodeFormat.ean13,
          BarcodeFormat.ean8,
          BarcodeFormat.upcA,
          BarcodeFormat.upcE,
          BarcodeFormat.code128,
          BarcodeFormat.code39,
          BarcodeFormat.qrCode,
        ],
      );

      _controller = controller;

      // Step 3: Listen to barcodes stream before start
      _barcodeSubscription = controller.barcodes.listen(_handleBarcodeCapture);

      // Step 4: Initialize and start native camera
      await controller.start();

      if (!mounted) return;

      setState(() {
        _flowState = ScannerFlowState.ready;
        _isInitializing = false;
        _statusText = "Align barcode within the frame";
      });
    } on MobileScannerException catch (e) {
      if (!mounted) return;

      final bool isPermDenied = e.errorCode == MobileScannerErrorCode.permissionDenied;
      final String msg = isPermDenied
          ? "Camera permission is required to scan packaged food barcodes. Please allow camera access."
          : (e.errorDetails?.message ?? "Camera is temporarily busy. Tap Retry to activate camera.");

      await _cleanupController();

      if (!mounted) return;
      setState(() {
        _flowState = ScannerFlowState.error;
        _isPermissionDenied = isPermDenied;
        _errorMessage = msg;
        _isInitializing = false;
        _statusText = "Camera unavailable";
      });
    } catch (e) {
      if (!mounted) return;
      await _cleanupController();

      if (!mounted) return;
      setState(() {
        _flowState = ScannerFlowState.error;
        _errorMessage = "Unable to start camera: $e";
        _isInitializing = false;
        _statusText = "Camera error";
      });
    }
  }

  void _handleBarcodeCapture(BarcodeCapture capture) async {
    if (_isProcessingBarcode || !mounted) return;

    final List<Barcode> barcodes = capture.barcodes;
    if (barcodes.isEmpty) return;

    final String? rawCode = barcodes.first.rawValue;
    if (rawCode == null || rawCode.trim().isEmpty) return;

    final String cleanCode = rawCode.trim().replaceAll(RegExp(r'\D'), '');
    if (cleanCode.length < 6) return;

    setState(() {
      _isProcessingBarcode = true;
      _statusText = "Barcode: $cleanCode\nSearching 6 food databases...";
    });

    try {
      final result = await ApiService.resolveBarcode(cleanCode);

      if (!mounted) return;

      if (result != null && (result['productName'] ?? '').toString().isNotEmpty) {
        // Successfully resolved product -> Return to caller
        Navigator.pop(context, result);
      } else {
        // Not found dialog
        await showDialog(
          context: context,
          builder: (ctx) => AlertDialog(
            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
            title: const Row(
              children: [
                Icon(Icons.search_off, color: Color(0xFFFF9800)),
                SizedBox(width: 8),
                Text('Product Not Found', style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold)),
              ],
            ),
            content: Text(
              'No food details found for barcode $cleanCode in the database.\n\nPlease search by food name manually.',
              style: const TextStyle(fontSize: 14, height: 1.4),
            ),
            actions: [
              TextButton(
                onPressed: () => Navigator.pop(ctx),
                child: const Text('Try Again', style: TextStyle(color: Color(0xFFFF9800), fontWeight: FontWeight.bold)),
              ),
              ElevatedButton(
                onPressed: () {
                  Navigator.pop(ctx);
                  Navigator.pop(context);
                },
                style: ElevatedButton.styleFrom(
                  backgroundColor: const Color(0xFF2E7D32),
                  foregroundColor: Colors.white,
                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
                ),
                child: const Text('Search Manually'),
              ),
            ],
          ),
        );

        if (mounted) {
          setState(() {
            _isProcessingBarcode = false;
            _statusText = "Align barcode within the frame";
          });
        }
      }
    } catch (e) {
      if (mounted) {
        setState(() {
          _isProcessingBarcode = false;
          _statusText = "Error resolving barcode. Try manual search.";
        });
      }
    }
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    _animationController.dispose();

    // Clean up controller synchronously & asynchronously
    _barcodeSubscription?.cancel();
    _barcodeSubscription = null;

    final controller = _controller;
    _controller = null;
    if (controller != null) {
      controller.stop();
      controller.dispose();
    }

    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    // Warm Amber-Orange Theme Palette (#2 Barcode/QR Scanner UI Color Requirement)
    const Color warmOrange = Color(0xFFFF9800);
    const Color amberOrange = Color(0xFFFFA000);
    const Color deepAmber = Color(0xFFF57C00);

    return Scaffold(
      backgroundColor: Colors.black,
      appBar: AppBar(
        title: const Text(
          'Scan Food Barcode',
          style: TextStyle(color: Colors.white, fontWeight: FontWeight.bold, fontSize: 18),
        ),
        backgroundColor: Colors.black,
        iconTheme: const IconThemeData(color: Colors.white),
        elevation: 0,
        actions: [
          if (_flowState == ScannerFlowState.ready && _controller != null) ...[
            IconButton(
              icon: Icon(
                _isTorchOn ? Icons.flash_on_rounded : Icons.flash_off_rounded,
                color: _isTorchOn ? amberOrange : Colors.white70,
              ),
              tooltip: 'Toggle Flashlight',
              onPressed: () async {
                try {
                  await _controller?.toggleTorch();
                  setState(() => _isTorchOn = !_isTorchOn);
                } catch (_) {}
              },
            ),
            IconButton(
              icon: const Icon(Icons.flip_camera_android_rounded, color: Colors.white70),
              tooltip: 'Switch Camera',
              onPressed: () async {
                try {
                  await _controller?.switchCamera();
                } catch (_) {}
              },
            ),
          ],
        ],
      ),
      body: Stack(
        fit: StackFit.expand,
        children: [
          // Camera Preview or Error/Loading UI
          if (_flowState == ScannerFlowState.ready && _controller != null)
            MobileScanner(
              controller: _controller!,
              fit: BoxFit.cover,
              errorBuilder: (context, error, child) {
                return _buildErrorState(error.errorDetails?.message ?? "Camera error");
              },
            )
          else if (_flowState == ScannerFlowState.error)
            _buildErrorState(_errorMessage)
          else
            _buildLoadingState(),

          // Warm Amber-Orange Viewfinder Overlay (Shown when ready)
          if (_flowState == ScannerFlowState.ready)
            Center(
              child: SizedBox(
                width: 290,
                height: 210,
                child: Stack(
                  children: [
                    // Corner brackets in warm amber-orange
                    CustomPaint(
                      size: const Size(290, 210),
                      painter: _WarmAmberCornerPainter(
                        cornerColor: _isProcessingBarcode ? deepAmber : warmOrange,
                      ),
                    ),

                    // Animated warm amber-orange scan line
                    if (!_isProcessingBarcode)
                      AnimatedBuilder(
                        animation: _scanLineAnimation,
                        builder: (context, child) {
                          return Positioned(
                            top: 210 * _scanLineAnimation.value,
                            left: 12,
                            right: 12,
                            child: Container(
                              height: 3,
                              decoration: BoxDecoration(
                                gradient: const LinearGradient(
                                  colors: [
                                    Colors.transparent,
                                    amberOrange,
                                    warmOrange,
                                    amberOrange,
                                    Colors.transparent,
                                  ],
                                ),
                                boxShadow: [
                                  BoxShadow(
                                    color: warmOrange.withValues(alpha: 0.75),
                                    blurRadius: 8,
                                    spreadRadius: 2,
                                  ),
                                ],
                                borderRadius: BorderRadius.circular(2),
                              ),
                            ),
                          );
                        },
                      ),
                  ],
                ),
              ),
            ),

          // Bottom status bar with warm amber-orange accents
          Positioned(
            bottom: 36,
            left: 20,
            right: 20,
            child: Container(
              padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 14),
              decoration: BoxDecoration(
                color: const Color(0xFF1E1E1E).withValues(alpha: 0.92),
                borderRadius: BorderRadius.circular(16),
                border: Border.all(
                  color: warmOrange.withValues(alpha: 0.35),
                  width: 1.5,
                ),
                boxShadow: [
                  BoxShadow(
                    color: Colors.black.withValues(alpha: 0.5),
                    blurRadius: 12,
                    offset: const Offset(0, 4),
                  ),
                ],
              ),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  if (_isProcessingBarcode || _flowState == ScannerFlowState.initializing)
                    const Padding(
                      padding: EdgeInsets.only(bottom: 10),
                      child: SizedBox(
                        width: 22,
                        height: 22,
                        child: CircularProgressIndicator(
                          color: warmOrange,
                          strokeWidth: 2.5,
                        ),
                      ),
                    ),
                  Text(
                    _statusText,
                    textAlign: TextAlign.center,
                    style: const TextStyle(
                      color: Colors.white,
                      fontSize: 14,
                      fontWeight: FontWeight.w600,
                      height: 1.3,
                    ),
                  ),
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildLoadingState() {
    const Color warmOrange = Color(0xFFFF9800);
    return Center(
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          Container(
            padding: const EdgeInsets.all(16),
            decoration: BoxDecoration(
              color: warmOrange.withValues(alpha: 0.12),
              shape: BoxShape.circle,
            ),
            child: const CircularProgressIndicator(
              color: warmOrange,
              strokeWidth: 3,
            ),
          ),
          const SizedBox(height: 20),
          const Text(
            'Initializing Camera...',
            style: TextStyle(color: Colors.white, fontSize: 16, fontWeight: FontWeight.bold),
          ),
          const SizedBox(height: 8),
          const Text(
            'Setting up hardware preview & barcode scanner',
            style: TextStyle(color: Colors.white60, fontSize: 13),
          ),
        ],
      ),
    );
  }

  Widget _buildErrorState(String message) {
    const Color warmOrange = Color(0xFFFF9800);
    const Color amberOrange = Color(0xFFFFA000);

    return Center(
      child: Padding(
        padding: const EdgeInsets.all(28.0),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Container(
              padding: const EdgeInsets.all(18),
              decoration: BoxDecoration(
                color: warmOrange.withValues(alpha: 0.15),
                shape: BoxShape.circle,
              ),
              child: Icon(
                _isPermissionDenied ? Icons.no_photography_rounded : Icons.videocam_off_rounded,
                color: amberOrange,
                size: 52,
              ),
            ),
            const SizedBox(height: 20),
            Text(
              _isPermissionDenied ? 'Camera Permission Required' : 'Camera Unavailable',
              textAlign: TextAlign.center,
              style: const TextStyle(
                color: Colors.white,
                fontSize: 18,
                fontWeight: FontWeight.bold,
              ),
            ),
            const SizedBox(height: 10),
            Text(
              message,
              textAlign: TextAlign.center,
              style: const TextStyle(
                color: Colors.white70,
                fontSize: 14,
                height: 1.4,
              ),
            ),
            const SizedBox(height: 24),
            Row(
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                ElevatedButton.icon(
                  onPressed: _isInitializing ? null : _startCameraInit,
                  icon: const Icon(Icons.refresh_rounded, size: 20),
                  label: const Text('Retry Camera', style: TextStyle(fontWeight: FontWeight.bold)),
                  style: ElevatedButton.styleFrom(
                    backgroundColor: warmOrange,
                    foregroundColor: Colors.black,
                    padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 12),
                    shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
                    elevation: 3,
                  ),
                ),
                const SizedBox(width: 14),
                OutlinedButton.icon(
                  onPressed: () => Navigator.pop(context),
                  icon: const Icon(Icons.arrow_back_rounded, color: Colors.white70, size: 18),
                  label: const Text('Go Back', style: TextStyle(color: Colors.white70)),
                  style: OutlinedButton.styleFrom(
                    side: const BorderSide(color: Colors.white30),
                    padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
                    shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
                  ),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}

/// Custom painter for warm orange-yellow / amber-orange viewfinder corners (#2 Requirement)
class _WarmAmberCornerPainter extends CustomPainter {
  final Color cornerColor;
  static const double cornerLength = 32.0;
  static const double strokeWidth = 3.5;

  const _WarmAmberCornerPainter({
    required this.cornerColor,
  });

  @override
  void paint(Canvas canvas, Size size) {
    final paint = Paint()
      ..color = cornerColor
      ..strokeWidth = strokeWidth
      ..style = PaintingStyle.stroke
      ..strokeCap = StrokeCap.round;

    final w = size.width;
    final h = size.height;

    // Top-Left Corner
    canvas.drawLine(const Offset(0, 0), Offset(cornerLength, 0), paint);
    canvas.drawLine(const Offset(0, 0), Offset(0, cornerLength), paint);

    // Top-Right Corner
    canvas.drawLine(Offset(w, 0), Offset(w - cornerLength, 0), paint);
    canvas.drawLine(Offset(w, 0), Offset(w, cornerLength), paint);

    // Bottom-Left Corner
    canvas.drawLine(Offset(0, h), Offset(cornerLength, h), paint);
    canvas.drawLine(Offset(0, h), Offset(0, h - cornerLength), paint);

    // Bottom-Right Corner
    canvas.drawLine(Offset(w, h), Offset(w - cornerLength, h), paint);
    canvas.drawLine(Offset(w, h), Offset(w, h - cornerLength), paint);
  }

  @override
  bool shouldRepaint(covariant _WarmAmberCornerPainter oldDelegate) {
    return oldDelegate.cornerColor != cornerColor;
  }
}
