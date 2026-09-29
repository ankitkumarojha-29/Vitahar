import 'package:flutter/material.dart';
import '../services/api_service.dart';

class ResultScreen extends StatefulWidget {
  final String productName;
  final String companyName;
  final String dob;
  final Map<String, dynamic>? initialNutrition;
  final String? initialSource;

  const ResultScreen({
    super.key,
    required this.productName,
    required this.companyName,
    required this.dob,
    this.initialNutrition,
    this.initialSource,
  });

  @override
  State<ResultScreen> createState() => _ResultScreenState();
}

class _ResultScreenState extends State<ResultScreen> {
  bool _isLoading = true;
  String _error = "";
  Map<String, dynamic>? _nutrition;
  String _source = "MySQL Database";
  List<dynamic> _alternatives = [];
  bool _loadingAlternatives = false;

  String _verdict = "safe";
  List<String> _reasons = [];
  int _age = 0;
  String _ageGroup = "age_18_30";

  // AI Recipe generation state on Result Screen
  bool _isGeneratingRecipe = false;
  Map<String, dynamic>? _generatedRecipe;

  @override
  void initState() {
    super.initState();
    _calculateAgeAndFetch();
  }

  void _calculateAgeAndFetch() async {
    // 1. Calculate exact Age
    final parts = widget.dob.split('-');
    if (parts.length == 3) {
      final day = int.tryParse(parts[0]) ?? 1;
      final month = int.tryParse(parts[1]) ?? 1;
      final year = int.tryParse(parts[2]) ?? 2000;
      final birth = DateTime(year, month, day);
      final today = DateTime.now();
      _age = today.year - birth.year;
      if (today.month < birth.month || (today.month == birth.month && today.day < birth.day)) {
        _age--;
      }
      if (_age < 0) _age = 0;
    }

    _ageGroup = _getAgeGroup(_age);

    // 2. Fetch Nutrition
    if (widget.initialNutrition != null && widget.initialNutrition!.isNotEmpty) {
      _nutrition = ApiService.sanitizeNutrition(widget.initialNutrition!);
      _source = widget.initialSource ?? "Online Database";
    } else {
      final data = await ApiService.searchFood(widget.productName);
      if (data == null || data['nutrition'] == null) {
        setState(() {
          _error = "Could not find nutrition data for '${widget.productName}'.\nTry searching another item or scan barcode.";
          _isLoading = false;
        });
        return;
      }
      _nutrition = ApiService.sanitizeNutrition(Map<String, dynamic>.from(data['nutrition']));
      _source = data['source'] ?? 'MySQL Database';
    }

    _computeVerdict();

    // 3. If Caution or Danger, fetch Healthier Alternatives
    if (_verdict != "safe") {
      _fetchAlternatives();
    }

    if (mounted) {
      setState(() {
        _isLoading = false;
      });
    }
  }

  String _getAgeGroup(int age) {
    if (age <= 5) return "age_0_5";
    if (age <= 12) return "age_5_12";
    if (age <= 18) return "age_12_18";
    if (age <= 30) return "age_18_30";
    if (age <= 60) return "age_30_60";
    return "age_60_plus";
  }

  String _getAgeGroupLabel(String key) {
    switch (key) {
      case "age_0_5":
        return "Infant / Toddler (0–5 yrs)";
      case "age_5_12":
        return "Child (5–12 yrs)";
      case "age_12_18":
        return "Teenager (12–18 yrs)";
      case "age_18_30":
        return "Young Adult (18–30 yrs)";
      case "age_30_60":
        return "Adult (30–60 yrs)";
      case "age_60_plus":
        return "Senior (60+ yrs)";
      default:
        return "Adult";
    }
  }

  void _computeVerdict() {
    final limits = ApiService.ageLimits[_ageGroup] ?? {
      'calories': 500,
      'sugar': 30,
      'salt': 1600,
      'fat': 35
    };

    _verdict = "safe";
    _reasons = [];

    _nutrition!.forEach((key, val) {
      if (!limits.containsKey(key)) return;
      num value = val is num ? val : num.tryParse(val.toString()) ?? 0;
      num limit = limits[key]!;

      if (value > limit * 2.5) {
        _reasons.add("High ${key.toUpperCase()} (${value} vs limit ${limit})");
        _verdict = "danger";
      } else if (value > limit && _verdict != "danger") {
        _reasons.add("Moderate ${key.toUpperCase()} (${value} vs limit ${limit})");
        _verdict = "caution";
      }
    });

    if (_reasons.isEmpty) {
      _reasons.add("All nutrients are within safe recommended limits.");
    }
  }

  Future<void> _fetchAlternatives() async {
    setState(() => _loadingAlternatives = true);
    final alts = await ApiService.fetchAlternatives(
      widget.productName,
      _nutrition?['sugar'] ?? 0,
      _nutrition?['salt'] ?? 0,
    );
    if (mounted) {
      setState(() {
        _alternatives = alts;
        _loadingAlternatives = false;
      });
    }
  }

  Future<void> _handleReportFood() async {
    final confirm = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Row(
          children: [
            Icon(Icons.flag, color: Colors.red),
            SizedBox(width: 8),
            Text('Report Food Data'),
          ],
        ),
        content: Text('Report "${widget.productName}" for incorrect nutrition data? Our admin team will review it.'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Cancel')),
          ElevatedButton(
            onPressed: () => Navigator.pop(ctx, true),
            style: ElevatedButton.styleFrom(backgroundColor: Colors.red, foregroundColor: Colors.white),
            child: const Text('Report'),
          ),
        ],
      ),
    );

    if (confirm == true) {
      final ok = await ApiService.reportFood(widget.productName);
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(ok
                ? 'Thanks for reporting! Data will be reviewed by admin.'
                : 'Report submitted successfully.'),
            backgroundColor: ok ? Colors.green : Colors.grey[800],
          ),
        );
      }
    }
  }

  Future<void> _handleGenerateRecipe() async {
    setState(() {
      _isGeneratingRecipe = true;
    });

    final recipe = await ApiService.generateRecipe(widget.productName);
    if (mounted) {
      setState(() {
        _isGeneratingRecipe = false;
        _generatedRecipe = recipe;
      });
      if (recipe == null) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Could not generate recipe at this moment.')),
        );
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_isLoading) {
      return Scaffold(
        backgroundColor: const Color(0xFFF9F7F1),
        appBar: AppBar(
          title: const Text('Verifying Safety...', style: TextStyle(fontWeight: FontWeight.bold)),
          backgroundColor: Colors.white,
          elevation: 1,
        ),
        body: const Center(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              CircularProgressIndicator(color: Color(0xFF2E7D32)),
              SizedBox(height: 16),
              Text(
                'Analyzing nutrition against age standards...',
                style: TextStyle(color: Colors.grey, fontSize: 14),
              ),
            ],
          ),
        ),
      );
    }

    if (_error.isNotEmpty) {
      return Scaffold(
        backgroundColor: const Color(0xFFF9F7F1),
        appBar: AppBar(
          title: const Text('Verification Result', style: TextStyle(fontWeight: FontWeight.bold)),
          backgroundColor: Colors.white,
          elevation: 1,
        ),
        body: Center(
          child: Padding(
            padding: const EdgeInsets.all(24.0),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                const Icon(Icons.error_outline, size: 64, color: Colors.orange),
                const SizedBox(height: 16),
                Text(
                  _error,
                  textAlign: TextAlign.center,
                  style: const TextStyle(fontSize: 16, color: Colors.black87),
                ),
                const SizedBox(height: 24),
                ElevatedButton(
                  onPressed: () => Navigator.pop(context),
                  style: ElevatedButton.styleFrom(
                    backgroundColor: const Color(0xFF2E7D32),
                    foregroundColor: Colors.white,
                    shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                  ),
                  child: const Text('Go Back'),
                ),
              ],
            ),
          ),
        ),
      );
    }

    // Verdict styling
    Color verdictColor;
    Color verdictBg;
    String verdictTitle;
    IconData verdictIcon;

    if (_verdict == "safe") {
      verdictColor = const Color(0xFF2E7D32); // Green
      verdictBg = const Color(0xFFE8F5E9);
      verdictTitle = "Safe to Consume";
      verdictIcon = Icons.check_circle_outline;
    } else if (_verdict == "caution") {
      verdictColor = const Color(0xFFE65100); // Deep Orange
      verdictBg = const Color(0xFFFFF3E0);
      verdictTitle = "Consume with Caution";
      verdictIcon = Icons.warning_amber_rounded;
    } else {
      verdictColor = const Color(0xFFB71C1C); // Red
      verdictBg = const Color(0xFFFFEBEE);
      verdictTitle = "Not Recommended";
      verdictIcon = Icons.cancel_outlined;
    }

    final limits = ApiService.ageLimits[_ageGroup] ?? {};

    return Scaffold(
      backgroundColor: const Color(0xFFF9F7F1),
      appBar: AppBar(
        title: const Text('Safety Analysis', style: TextStyle(fontWeight: FontWeight.bold, color: Colors.black87)),
        backgroundColor: Colors.white,
        elevation: 1,
        iconTheme: const IconThemeData(color: Colors.black87),
      ),
      body: SingleChildScrollView(
        padding: const EdgeInsets.all(16.0),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            // Product info badge
            Card(
              elevation: 0,
              color: Colors.white,
              shape: RoundedRectangleBorder(
                borderRadius: BorderRadius.circular(16),
                side: BorderSide(color: Colors.grey.shade200),
              ),
              child: Padding(
                padding: const EdgeInsets.all(16.0),
                child: Row(
                  children: [
                    Container(
                      padding: const EdgeInsets.all(12),
                      decoration: BoxDecoration(
                        color: const Color(0xFF2E7D32).withValues(alpha: 0.1),
                        borderRadius: BorderRadius.circular(12),
                      ),
                      child: const Icon(Icons.fastfood, color: Color(0xFF2E7D32), size: 28),
                    ),
                    const SizedBox(width: 14),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            widget.productName,
                            style: const TextStyle(fontSize: 20, fontWeight: FontWeight.bold, color: Colors.black87),
                          ),
                          if (widget.companyName.isNotEmpty) ...[
                            const SizedBox(height: 2),
                            Text(
                              widget.companyName,
                              style: TextStyle(fontSize: 14, color: Colors.grey.shade600),
                            ),
                          ],
                          const SizedBox(height: 4),
                          Row(
                            children: [
                              Container(
                                padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                                decoration: BoxDecoration(
                                  color: Colors.blue.shade50,
                                  borderRadius: BorderRadius.circular(6),
                                ),
                                child: Text(
                                  'Age: $_age yrs',
                                  style: TextStyle(fontSize: 12, fontWeight: FontWeight.w600, color: Colors.blue.shade800),
                                ),
                              ),
                              const SizedBox(width: 8),
                              Expanded(
                                child: Text(
                                  _getAgeGroupLabel(_ageGroup),
                                  style: TextStyle(fontSize: 12, color: Colors.grey.shade600),
                                  overflow: TextOverflow.ellipsis,
                                ),
                              ),
                            ],
                          ),
                        ],
                      ),
                    ),
                  ],
                ),
              ),
            ),
            const SizedBox(height: 16),

            // Verdict Card
            Container(
              decoration: BoxDecoration(
                color: verdictBg,
                borderRadius: BorderRadius.circular(16),
                border: Border.all(color: verdictColor.withValues(alpha: 0.4), width: 1.5),
              ),
              padding: const EdgeInsets.all(20.0),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    children: [
                      Icon(verdictIcon, color: verdictColor, size: 32),
                      const SizedBox(width: 12),
                      Expanded(
                        child: Text(
                          verdictTitle,
                          style: TextStyle(fontSize: 22, fontWeight: FontWeight.bold, color: verdictColor),
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 12),
                  const Divider(thickness: 0.5),
                  const SizedBox(height: 8),
                  ..._reasons.map((reason) => Padding(
                        padding: const EdgeInsets.only(bottom: 6.0),
                        child: Row(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text("• ", style: TextStyle(color: verdictColor, fontWeight: FontWeight.bold, fontSize: 16)),
                            Expanded(
                              child: Text(
                                reason,
                                style: const TextStyle(fontSize: 14, color: Colors.black87, fontWeight: FontWeight.w500),
                              ),
                            ),
                          ],
                        ),
                      )),
                  const SizedBox(height: 12),
                  Row(
                    mainAxisAlignment: MainAxisAlignment.spaceBetween,
                    children: [
                      Container(
                        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                        decoration: BoxDecoration(
                          color: Colors.white,
                          borderRadius: BorderRadius.circular(8),
                          border: Border.all(color: Colors.grey.shade300),
                        ),
                        child: Text(
                          'Source: $_source',
                          style: const TextStyle(fontSize: 11, fontWeight: FontWeight.w500, color: Colors.black54),
                        ),
                      ),
                      TextButton.icon(
                        onPressed: _handleReportFood,
                        icon: const Icon(Icons.flag_outlined, size: 16, color: Colors.red),
                        label: const Text('Report Data', style: TextStyle(color: Colors.red, fontSize: 12)),
                        style: TextButton.styleFrom(visualDensity: VisualDensity.compact),
                      ),
                    ],
                  ),
                ],
              ),
            ),
            const SizedBox(height: 20),

            // Healthier Alternatives (if applicable)
            if (_verdict != "safe") ...[
              Container(
                decoration: BoxDecoration(
                  color: const Color(0xFFF0FDF4),
                  borderRadius: BorderRadius.circular(16),
                  border: Border.all(color: const Color(0xFFBBF7D0)),
                ),
                padding: const EdgeInsets.all(16.0),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    const Row(
                      children: [
                        Text('🌿', style: TextStyle(fontSize: 20)),
                        SizedBox(width: 8),
                        Text(
                          'Healthier Alternatives',
                          style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold, color: Color(0xFF166534)),
                        ),
                      ],
                    ),
                    const SizedBox(height: 6),
                    const Text(
                      'Try these options with lower sugar & salt in the same category:',
                      style: TextStyle(fontSize: 13, color: Colors.black54),
                    ),
                    const SizedBox(height: 12),
                    if (_loadingAlternatives)
                      const Center(
                        child: Padding(
                          padding: EdgeInsets.all(12.0),
                          child: CircularProgressIndicator(color: Color(0xFF166534)),
                        ),
                      )
                    else if (_alternatives.isEmpty)
                      const Text('No direct alternatives found yet.', style: TextStyle(fontSize: 13, color: Colors.grey))
                    else
                      Column(
                        children: _alternatives.map((alt) {
                          final altName = alt['productName'] ?? alt['name'] ?? '';
                          final altCompany = alt['companyName'] ?? alt['company'] ?? '';
                          final altNut = alt['nutrition'] ?? {};
                          final sugar = altNut['sugar'] ?? 0;
                          final salt = altNut['salt'] ?? 0;

                          return Container(
                            margin: const EdgeInsets.only(bottom: 8),
                            padding: const EdgeInsets.all(12),
                            decoration: BoxDecoration(
                              color: Colors.white,
                              borderRadius: BorderRadius.circular(12),
                              boxShadow: [
                                BoxShadow(color: Colors.black.withValues(alpha: 0.03), blurRadius: 4, spreadRadius: 1),
                              ],
                            ),
                            child: Row(
                              children: [
                                Expanded(
                                  child: Column(
                                    crossAxisAlignment: CrossAxisAlignment.start,
                                    children: [
                                      Text(
                                        altName,
                                        style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 14, color: Colors.black87),
                                      ),
                                      if (altCompany.toString().isNotEmpty)
                                        Text(altCompany.toString(), style: const TextStyle(fontSize: 12, color: Colors.grey)),
                                      const SizedBox(height: 4),
                                      Text(
                                        'Sugar: ${sugar}g | Salt: ${salt}mg',
                                        style: const TextStyle(fontSize: 12, color: Color(0xFF047857), fontWeight: FontWeight.w600),
                                      ),
                                    ],
                                  ),
                                ),
                                ElevatedButton(
                                  onPressed: () {
                                    Navigator.pushReplacement(
                                      context,
                                      MaterialPageRoute(
                                        builder: (ctx) => ResultScreen(
                                          productName: altName,
                                          companyName: altCompany.toString(),
                                          dob: widget.dob,
                                        ),
                                      ),
                                    );
                                  },
                                  style: ElevatedButton.styleFrom(
                                    backgroundColor: const Color(0xFF22C55E),
                                    foregroundColor: Colors.white,
                                    padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
                                    shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
                                  ),
                                  child: const Text('Check Item', style: TextStyle(fontSize: 12, fontWeight: FontWeight.bold)),
                                ),
                              ],
                            ),
                          );
                        }).toList(),
                      ),
                  ],
                ),
              ),
              const SizedBox(height: 20),
            ],

            // Nutrition Analysis Table & Visual Comparison
            Card(
              elevation: 0,
              color: Colors.white,
              shape: RoundedRectangleBorder(
                borderRadius: BorderRadius.circular(16),
                side: BorderSide(color: Colors.grey.shade200),
              ),
              child: Padding(
                padding: const EdgeInsets.all(16.0),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    const Text(
                      'Nutrient Breakdown vs Limits',
                      style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold, color: Color(0xFF2E7D32)),
                    ),
                    const SizedBox(height: 4),
                    Text(
                      'Based on WHO & FSSAI standards per 100g/serving for your age group.',
                      style: TextStyle(fontSize: 12, color: Colors.grey.shade600),
                    ),
                    const SizedBox(height: 16),
                    _buildNutrientRow('Calories', _nutrition?['calories'], limits['calories'], 'kcal'),
                    _buildNutrientRow('Sugar', _nutrition?['sugar'], limits['sugar'], 'g'),
                    _buildNutrientRow('Salt (Sodium)', _nutrition?['salt'], limits['salt'], 'mg'),
                    _buildNutrientRow('Fat', _nutrition?['fat'], limits['fat'], 'g'),
                    if (_nutrition?['protein'] != null && (_nutrition?['protein'] as num) > 0)
                      _buildNutrientRow('Protein', _nutrition?['protein'], limits['protein'], 'g', isPositive: true),
                    if (_nutrition?['fiber'] != null && (_nutrition?['fiber'] as num) > 0)
                      _buildNutrientRow('Dietary Fiber', _nutrition?['fiber'], limits['fiber'], 'g', isPositive: true),
                  ],
                ),
              ),
            ),
            const SizedBox(height: 20),

            // AI Recipe Generator Section
            Card(
              elevation: 0,
              color: const Color(0xFFFFF8E1),
              shape: RoundedRectangleBorder(
                borderRadius: BorderRadius.circular(16),
                side: BorderSide(color: Colors.orange.shade200),
              ),
              child: Padding(
                padding: const EdgeInsets.all(18.0),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    const Row(
                      children: [
                        Text('✨', style: TextStyle(fontSize: 22)),
                        SizedBox(width: 8),
                        Text(
                          'AI Healthy Recipe Generator',
                          style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold, color: Color(0xFFE65100)),
                        ),
                      ],
                    ),
                    const SizedBox(height: 6),
                    Text(
                      'Transform ${widget.productName} into a nutritious, wholesome dish using AI!',
                      style: const TextStyle(fontSize: 13, color: Colors.black87),
                    ),
                    const SizedBox(height: 14),
                    if (_generatedRecipe == null)
                      ElevatedButton.icon(
                        onPressed: _isGeneratingRecipe ? null : _handleGenerateRecipe,
                        icon: _isGeneratingRecipe
                            ? const SizedBox(
                                width: 18,
                                height: 18,
                                child: CircularProgressIndicator(color: Colors.white, strokeWidth: 2),
                              )
                            : const Icon(Icons.auto_awesome, color: Colors.white),
                        label: Text(
                          _isGeneratingRecipe ? 'AI is Cooking...' : 'Generate Healthy Recipe',
                          style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 15),
                        ),
                        style: ElevatedButton.styleFrom(
                          backgroundColor: const Color(0xFFFF9800),
                          foregroundColor: Colors.white,
                          padding: const EdgeInsets.symmetric(vertical: 14),
                          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                        ),
                      )
                    else ...[
                      // Display Generated Recipe
                      Container(
                        padding: const EdgeInsets.all(16),
                        decoration: BoxDecoration(
                          color: Colors.white,
                          borderRadius: BorderRadius.circular(12),
                          border: Border.all(color: Colors.orange.shade200),
                        ),
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(
                              _generatedRecipe?['recipeName'] ?? 'Healthy Recipe',
                              style: const TextStyle(fontSize: 18, fontWeight: FontWeight.bold, color: Color(0xFFE65100)),
                            ),
                            const SizedBox(height: 4),
                            Text(
                              '⏱️ Prep Time: ${_generatedRecipe?['prepTime'] ?? '15 mins'}',
                              style: const TextStyle(fontSize: 13, color: Colors.black54, fontStyle: FontStyle.italic),
                            ),
                            const SizedBox(height: 12),
                            const Text('Ingredients:', style: TextStyle(fontWeight: FontWeight.bold, color: Color(0xFF2E7D32))),
                            const SizedBox(height: 4),
                            ...((_generatedRecipe?['ingredients'] as List?) ?? []).map((ing) => Padding(
                                  padding: const EdgeInsets.only(bottom: 2.0),
                                  child: Text('• $ing', style: const TextStyle(fontSize: 13)),
                                )),
                            const SizedBox(height: 12),
                            const Text('Instructions:', style: TextStyle(fontWeight: FontWeight.bold, color: Color(0xFF2E7D32))),
                            const SizedBox(height: 4),
                            ...((_generatedRecipe?['instructions'] as List?) ?? []).map((step) => Padding(
                                  padding: const EdgeInsets.only(bottom: 4.0),
                                  child: Text('1. $step', style: const TextStyle(fontSize: 13)),
                                )),
                            if (_generatedRecipe?['healthBenefits'] != null) ...[
                              const SizedBox(height: 12),
                              Container(
                                padding: const EdgeInsets.all(10),
                                decoration: BoxDecoration(
                                  color: const Color(0xFFE8F5E9),
                                  borderRadius: BorderRadius.circular(8),
                                ),
                                child: Text(
                                  '💡 Health Benefit: ${_generatedRecipe!['healthBenefits']}',
                                  style: const TextStyle(fontSize: 12, color: Color(0xFF1B5E20), fontWeight: FontWeight.w600),
                                ),
                              ),
                            ],
                          ],
                        ),
                      ),
                      const SizedBox(height: 10),
                      OutlinedButton(
                        onPressed: _handleGenerateRecipe,
                        style: OutlinedButton.styleFrom(
                          side: const BorderSide(color: Colors.orange),
                          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
                        ),
                        child: const Text('Regenerate Recipe', style: TextStyle(color: Colors.orange)),
                      ),
                    ],
                  ],
                ),
              ),
            ),
            const SizedBox(height: 24),
          ],
        ),
      ),
    );
  }

  Widget _buildNutrientRow(String label, dynamic productVal, dynamic limitVal, String unit, {bool isPositive = false}) {
    num pVal = (productVal is num) ? productVal : (num.tryParse('$productVal') ?? 0);
    num? lVal = (limitVal is num) ? limitVal : (num.tryParse('$limitVal'));

    bool isOver = lVal != null && pVal > lVal && !isPositive;
    double progress = (lVal != null && lVal > 0) ? (pVal / lVal).clamp(0.0, 1.0) : 0.0;

    return Padding(
      padding: const EdgeInsets.only(bottom: 14.0),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              Text(
                label,
                style: TextStyle(fontWeight: isOver ? FontWeight.bold : FontWeight.w500, fontSize: 14),
              ),
              Row(
                children: [
                  Text(
                    '$pVal $unit',
                    style: TextStyle(
                      fontWeight: FontWeight.bold,
                      color: isOver ? Colors.red.shade700 : (isPositive ? Colors.green.shade800 : Colors.black87),
                    ),
                  ),
                  if (lVal != null) ...[
                    Text(' / $lVal $unit', style: TextStyle(color: Colors.grey.shade500, fontSize: 12)),
                  ],
                ],
              ),
            ],
          ),
          const SizedBox(height: 6),
          ClipRRect(
            borderRadius: BorderRadius.circular(6),
            child: LinearProgressIndicator(
              value: progress,
              backgroundColor: Colors.grey.shade200,
              valueColor: AlwaysStoppedAnimation<Color>(
                isOver ? Colors.red.shade600 : (isPositive ? const Color(0xFF2E7D32) : Colors.orange.shade700),
              ),
              minHeight: 7,
            ),
          ),
        ],
      ),
    );
  }
}
