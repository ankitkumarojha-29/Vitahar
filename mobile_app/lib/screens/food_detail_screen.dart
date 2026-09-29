import 'package:flutter/material.dart';
import '../services/api_service.dart';
import 'result_screen.dart';

class FoodDetailScreen extends StatefulWidget {
  final String productName;
  final String? companyName;
  final Map<String, dynamic>? initialNutrition;
  final String? initialSource;

  const FoodDetailScreen({
    super.key,
    required this.productName,
    this.companyName,
    this.initialNutrition,
    this.initialSource,
  });

  @override
  State<FoodDetailScreen> createState() => _FoodDetailScreenState();
}

class _FoodDetailScreenState extends State<FoodDetailScreen> {
  bool _isLoading = true;
  String _errorMessage = "";
  Map<String, dynamic>? _nutrition;
  String _company = "";
  String _source = "MySQL Database";

  @override
  void initState() {
    super.initState();
    _company = widget.companyName ?? "";
    _loadFoodData();
  }

  Future<void> _loadFoodData() async {
    if (widget.initialNutrition != null && widget.initialNutrition!.isNotEmpty) {
      setState(() {
        _nutrition = ApiService.sanitizeNutrition(widget.initialNutrition!);
        _source = widget.initialSource ?? "MySQL Database";
        _isLoading = false;
      });
      return;
    }

    try {
      final data = await ApiService.searchFood(widget.productName);
      if (!mounted) return;

      if (data != null && data['nutrition'] != null) {
        setState(() {
          _nutrition = ApiService.sanitizeNutrition(Map<String, dynamic>.from(data['nutrition']));
          if (_company.isEmpty && data['companyName'] != null) {
            _company = data['companyName'].toString();
          }
          _source = data['source'] ?? 'MySQL Database';
          _isLoading = false;
        });
      } else {
        setState(() {
          _errorMessage = "No nutritional details found for '${widget.productName}'.\nPlease check the spelling or scan the food packet barcode.";
          _isLoading = false;
        });
      }
    } catch (e) {
      if (mounted) {
        setState(() {
          _errorMessage = "Could not connect to food database. Please check your internet connection.";
          _isLoading = false;
        });
      }
    }
  }

  void _promptDobForSafetyCheck() async {
    final DateTime now = DateTime.now();
    final DateTime? picked = await showDatePicker(
      context: context,
      initialDate: DateTime(2000, 1, 1),
      firstDate: DateTime(1920),
      lastDate: now,
      helpText: 'Select Date of Birth for Food Safety Verification',
      confirmText: 'Verify Safety',
      builder: (context, child) {
        return Theme(
          data: Theme.of(context).copyWith(
            colorScheme: const ColorScheme.light(
              primary: Color(0xFF2E7D32),
              onPrimary: Colors.white,
              onSurface: Colors.black87,
            ),
          ),
          child: child!,
        );
      },
    );

    if (picked != null && mounted) {
      final dobStr = "${picked.day.toString().padLeft(2, '0')}-${picked.month.toString().padLeft(2, '0')}-${picked.year}";
      Navigator.push(
        context,
        MaterialPageRoute(
          builder: (context) => ResultScreen(
            productName: widget.productName,
            companyName: _company,
            dob: dobStr,
            initialNutrition: _nutrition,
            initialSource: _source,
          ),
        ),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: const Color(0xFFF9F7F1),
      appBar: AppBar(
        title: const Text('Nutritional Facts', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 18)),
        backgroundColor: Colors.white,
        foregroundColor: const Color(0xFF2E7D32),
        elevation: 0.5,
      ),
      body: _isLoading
          ? const Center(
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  CircularProgressIndicator(color: Color(0xFF2E7D32)),
                  SizedBox(height: 16),
                  Text('Fetching accurate nutrition data...', style: TextStyle(color: Colors.grey, fontSize: 14)),
                ],
              ),
            )
          : _errorMessage.isNotEmpty
              ? Center(
                  child: Padding(
                    padding: const EdgeInsets.all(24.0),
                    child: Column(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        const Icon(Icons.search_off, size: 64, color: Colors.orange),
                        const SizedBox(height: 16),
                        Text(
                          _errorMessage,
                          textAlign: TextAlign.center,
                          style: const TextStyle(fontSize: 15, color: Colors.black87),
                        ),
                        const SizedBox(height: 24),
                        ElevatedButton.icon(
                          onPressed: () => Navigator.pop(context),
                          icon: const Icon(Icons.arrow_back),
                          label: const Text('Go Back'),
                          style: ElevatedButton.styleFrom(
                            backgroundColor: const Color(0xFF2E7D32),
                            foregroundColor: Colors.white,
                          ),
                        ),
                      ],
                    ),
                  ),
                )
              : SingleChildScrollView(
                  padding: const EdgeInsets.all(16.0),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      // Header Product Card
                      Container(
                        decoration: BoxDecoration(
                          color: Colors.white,
                          borderRadius: BorderRadius.circular(18),
                          boxShadow: [
                            BoxShadow(
                              color: Colors.black.withValues(alpha: 0.04),
                              blurRadius: 10,
                              offset: const Offset(0, 2),
                            ),
                          ],
                        ),
                        padding: const EdgeInsets.all(20.0),
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Row(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Container(
                                  padding: const EdgeInsets.all(12),
                                  decoration: BoxDecoration(
                                    color: const Color(0xFFE8F5E9),
                                    borderRadius: BorderRadius.circular(14),
                                  ),
                                  child: const Icon(Icons.restaurant, color: Color(0xFF2E7D32), size: 30),
                                ),
                                const SizedBox(width: 14),
                                Expanded(
                                  child: Column(
                                    crossAxisAlignment: CrossAxisAlignment.start,
                                    children: [
                                      Text(
                                        widget.productName,
                                        style: const TextStyle(
                                          fontSize: 20,
                                          fontWeight: FontWeight.bold,
                                          color: Colors.black87,
                                        ),
                                      ),
                                      if (_company.isNotEmpty) ...[
                                        const SizedBox(height: 4),
                                        Row(
                                          children: [
                                            const Icon(Icons.business, size: 14, color: Colors.grey),
                                            const SizedBox(width: 4),
                                            Text(
                                              _company,
                                              style: const TextStyle(
                                                fontSize: 14,
                                                color: Colors.black54,
                                                fontWeight: FontWeight.w500,
                                              ),
                                            ),
                                          ],
                                        ),
                                      ],
                                    ],
                                  ),
                                ),
                              ],
                            ),
                            const SizedBox(height: 14),
                            Wrap(
                              spacing: 8,
                              children: [
                                Chip(
                                  avatar: const Icon(Icons.verified, size: 16, color: Color(0xFF2E7D32)),
                                  label: Text(_source, style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w600, color: Color(0xFF2E7D32))),
                                  backgroundColor: const Color(0xFFE8F5E9),
                                  side: BorderSide.none,
                                  visualDensity: VisualDensity.compact,
                                ),
                                const Chip(
                                  label: Text('Per 100g / 100ml', style: TextStyle(fontSize: 12, color: Colors.black87)),
                                  backgroundColor: Color(0xFFF5F5F5),
                                  side: BorderSide.none,
                                  visualDensity: VisualDensity.compact,
                                ),
                              ],
                            ),
                          ],
                        ),
                      ),
                      const SizedBox(height: 16),

                      // Nutritional Facts Card
                      Container(
                        decoration: BoxDecoration(
                          color: Colors.white,
                          borderRadius: BorderRadius.circular(18),
                          boxShadow: [
                            BoxShadow(
                              color: Colors.black.withValues(alpha: 0.04),
                              blurRadius: 10,
                              offset: const Offset(0, 2),
                            ),
                          ],
                        ),
                        padding: const EdgeInsets.all(20.0),
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            const Row(
                              children: [
                                Icon(Icons.analytics_outlined, color: Color(0xFF2E7D32), size: 22),
                                SizedBox(width: 8),
                                Text(
                                  'Nutritional Values (Per 100g)',
                                  style: TextStyle(fontSize: 17, fontWeight: FontWeight.bold, color: Colors.black87),
                                ),
                              ],
                            ),
                            const Divider(height: 24),
                            _buildNutrientRow('Calories', '${_nutrition?['calories'] ?? 0} kcal', Icons.local_fire_department, Colors.orange),
                            _buildNutrientRow('Sugar', '${_nutrition?['sugar'] ?? 0} g', Icons.cookie_outlined, Colors.purple),
                            _buildNutrientRow('Total Fat', '${_nutrition?['fat'] ?? 0} g', Icons.water_drop_outlined, Colors.amber.shade800),
                            _buildNutrientRow('Sodium / Salt', '${_nutrition?['salt'] ?? 0} mg', Icons.grain, Colors.blue),
                            _buildNutrientRow('Protein', '${_nutrition?['protein'] ?? 0} g', Icons.fitness_center, Colors.teal),
                            _buildNutrientRow('Dietary Fiber', '${_nutrition?['fiber'] ?? 0} g', Icons.eco_outlined, Colors.green),
                            if (_nutrition?['carbs'] != null)
                              _buildNutrientRow('Carbohydrates', '${_nutrition?['carbs']} g', Icons.lunch_dining_outlined, Colors.brown),
                          ],
                        ),
                      ),
                      const SizedBox(height: 20),

                      // Food Safety Verification CTA
                      Container(
                        decoration: BoxDecoration(
                          gradient: const LinearGradient(
                            colors: [Color(0xFF2E7D32), Color(0xFF388E3C)],
                            begin: Alignment.topLeft,
                            end: Alignment.bottomRight,
                          ),
                          borderRadius: BorderRadius.circular(18),
                          boxShadow: [
                            BoxShadow(
                              color: const Color(0xFF2E7D32).withValues(alpha: 0.25),
                              blurRadius: 10,
                              offset: const Offset(0, 4),
                            ),
                          ],
                        ),
                        padding: const EdgeInsets.all(18),
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            const Row(
                              children: [
                                Icon(Icons.shield_outlined, color: Colors.white, size: 22),
                                SizedBox(width: 8),
                                Text(
                                  'Verify Safety for Your Age',
                                  style: TextStyle(color: Colors.white, fontSize: 16, fontWeight: FontWeight.bold),
                                ),
                              ],
                            ),
                            const SizedBox(height: 6),
                            const Text(
                              'Calculate exact age safety thresholds (FSSAI/WHO aligned) to check if this product is safe for you or your child.',
                              style: TextStyle(color: Colors.white70, fontSize: 13),
                            ),
                            const SizedBox(height: 14),
                            SizedBox(
                              width: double.infinity,
                              child: ElevatedButton.icon(
                                onPressed: _promptDobForSafetyCheck,
                                icon: const Icon(Icons.cake_outlined, size: 18),
                                label: const Text('Check Food Safety for My Age'),
                                style: ElevatedButton.styleFrom(
                                  backgroundColor: Colors.white,
                                  foregroundColor: const Color(0xFF2E7D32),
                                  padding: const EdgeInsets.symmetric(vertical: 14),
                                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                                  elevation: 0,
                                  textStyle: const TextStyle(fontWeight: FontWeight.bold, fontSize: 14),
                                ),
                              ),
                            ),
                          ],
                        ),
                      ),
                      const SizedBox(height: 16),

                      // Report Button
                      Center(
                        child: TextButton.icon(
                          onPressed: () async {
                            final success = await ApiService.reportFood(widget.productName);
                            if (context.mounted) {
                              ScaffoldMessenger.of(context).showSnackBar(
                                SnackBar(
                                  content: Text(success ? 'Report submitted. Our team will verify.' : 'Could not submit report.'),
                                  backgroundColor: success ? const Color(0xFF2E7D32) : Colors.orange,
                                ),
                              );
                            }
                          },
                          icon: const Icon(Icons.flag_outlined, size: 16, color: Colors.grey),
                          label: const Text('Report incorrect nutrition data', style: TextStyle(color: Colors.grey, fontSize: 12)),
                        ),
                      ),
                    ],
                  ),
                ),
    );
  }

  Widget _buildNutrientRow(String label, String value, IconData icon, Color color) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 8.0),
      child: Row(
        children: [
          Container(
            padding: const EdgeInsets.all(8),
            decoration: BoxDecoration(
              color: color.withValues(alpha: 0.1),
              borderRadius: BorderRadius.circular(10),
            ),
            child: Icon(icon, color: color, size: 20),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Text(
              label,
              style: const TextStyle(fontSize: 15, fontWeight: FontWeight.w500, color: Colors.black87),
            ),
          ),
          Text(
            value,
            style: const TextStyle(fontSize: 16, fontWeight: FontWeight.bold, color: Colors.black87),
          ),
        ],
      ),
    );
  }
}
