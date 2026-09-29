import 'package:flutter/material.dart';
import '../services/api_service.dart';

class ComparisonScreen extends StatefulWidget {
  const ComparisonScreen({super.key});

  @override
  State<ComparisonScreen> createState() => _ComparisonScreenState();
}

class _ComparisonScreenState extends State<ComparisonScreen> {
  final _food1Controller = TextEditingController();
  final _food2Controller = TextEditingController();

  bool _isComparing = false;
  String _errorMsg = "";

  Map<String, dynamic>? _food1Result;
  Map<String, dynamic>? _food2Result;

  String? _winner;
  String _winnerReason = "";
  int _formResetCounter = 0;

  void _clearComparison() {
    _food1Controller.clear();
    _food2Controller.clear();
    setState(() {
      _formResetCounter++;
      _isComparing = false;
      _errorMsg = "";
      _food1Result = null;
      _food2Result = null;
      _winner = null;
      _winnerReason = "";
    });
  }

  @override
  void dispose() {
    _food1Controller.dispose();
    _food2Controller.dispose();
    super.dispose();
  }

  void _runComparison() async {
    final name1 = _food1Controller.text.trim();
    final name2 = _food2Controller.text.trim();

    if (name1.isEmpty || name2.isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Please enter both foods to compare.'),
          backgroundColor: Color(0xFFE65100),
        ),
      );
      return;
    }

    setState(() {
      _isComparing = true;
      _errorMsg = "";
      _winner = null;
      _winnerReason = "";
    });

    try {
      // Concurrently fetch both from API / MySQL / External
      final results = await Future.wait([
        ApiService.searchFood(name1),
        ApiService.searchFood(name2),
      ]);

      final res1 = results[0];
      final res2 = results[1];

      if (res1 == null || res1['nutrition'] == null) {
        setState(() {
          _errorMsg = "Could not find nutrition data for '$name1'. Try another item.";
          _isComparing = false;
        });
        return;
      }

      if (res2 == null || res2['nutrition'] == null) {
        setState(() {
          _errorMsg = "Could not find nutrition data for '$name2'. Try another item.";
          _isComparing = false;
        });
        return;
      }

      final n1 = res1['nutrition'] as Map<String, dynamic>;
      final n2 = res2['nutrition'] as Map<String, dynamic>;

      num sugar1 = n1['sugar'] ?? 0;
      num sugar2 = n2['sugar'] ?? 0;
      num salt1 = n1['salt'] ?? 0;
      num salt2 = n2['salt'] ?? 0;
      num fat1 = n1['fat'] ?? 0;
      num fat2 = n2['fat'] ?? 0;
      num cal1 = n1['calories'] ?? 0;
      num cal2 = n2['calories'] ?? 0;

      int score1 = 0;
      int score2 = 0;
      List<String> reasons = [];

      // Lower sugar wins
      if (sugar1 < sugar2) {
        score1++;
        reasons.add("${res1['productName']} has ${sugar2 - sugar1}g less sugar");
      } else if (sugar2 < sugar1) {
        score2++;
        reasons.add("${res2['productName']} has ${sugar1 - sugar2}g less sugar");
      }

      // Lower salt wins
      if (salt1 < salt2) {
        score1++;
        reasons.add("${res1['productName']} has ${salt2 - salt1}mg less sodium");
      } else if (salt2 < salt1) {
        score2++;
        reasons.add("${res2['productName']} has ${salt1 - salt2}mg less sodium");
      }

      // Lower fat wins
      if (fat1 < fat2) {
        score1++;
        reasons.add("${res1['productName']} has ${fat2 - fat1}g less fat");
      } else if (fat2 < fat1) {
        score2++;
        reasons.add("${res2['productName']} has ${fat1 - fat2}g less fat");
      }

      // Lower calories slight advantage
      if (cal1 < cal2) {
        score1++;
      } else if (cal2 < cal1) {
        score2++;
      }

      String? winner;
      String reason;

      if (score1 > score2) {
        winner = res1['productName'];
        reason = reasons.isNotEmpty
            ? reasons.join(" • ")
            : "It has overall better nutritional values (lower sugar/salt/fat).";
      } else if (score2 > score1) {
        winner = res2['productName'];
        reason = reasons.isNotEmpty
            ? reasons.join(" • ")
            : "It has overall better nutritional values (lower sugar/salt/fat).";
      } else {
        winner = "TIE";
        reason = "Both products have very comparable nutritional profiles.";
      }

      setState(() {
        _food1Result = res1;
        _food2Result = res2;
        _winner = winner;
        _winnerReason = reason;
        _isComparing = false;
      });
    } catch (e) {
      setState(() {
        _errorMsg = "Failed to compare foods. Please check your internet connection.";
        _isComparing = false;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: const Color(0xFFF9F7F1),
      appBar: AppBar(
        title: const Row(
          children: [
            Text('⚖️', style: TextStyle(fontSize: 22)),
            SizedBox(width: 8),
            Text(
              'Food Comparison Tool',
              style: TextStyle(fontWeight: FontWeight.bold, color: Colors.black87, fontSize: 19),
            ),
          ],
        ),
        backgroundColor: Colors.white,
        elevation: 0.5,
      ),
      body: SingleChildScrollView(
        padding: const EdgeInsets.all(16.0),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            // Header Instruction
            Container(
              padding: const EdgeInsets.all(14),
              decoration: BoxDecoration(
                color: const Color(0xFFE8F5E9),
                borderRadius: BorderRadius.circular(14),
                border: Border.all(color: const Color(0xFFC8E6C9)),
              ),
              child: const Row(
                children: [
                  Icon(Icons.info_outline, color: Color(0xFF2E7D32), size: 22),
                  SizedBox(width: 10),
                  Expanded(
                    child: Text(
                      'Compare two packaged foods side-by-side to find the healthier option based on sugar, salt, and fat.',
                      style: TextStyle(fontSize: 13, color: Color(0xFF1B5E20), height: 1.3),
                    ),
                  ),
                ],
              ),
            ),
            const SizedBox(height: 16),

            // Comparison Input Form
            Container(
              key: ValueKey('compare_form_$_formResetCounter'),
              decoration: BoxDecoration(
                color: Colors.white,
                borderRadius: BorderRadius.circular(20),
                boxShadow: [
                  BoxShadow(
                    color: Colors.black.withValues(alpha: 0.04),
                    blurRadius: 10,
                    offset: const Offset(0, 2),
                  ),
                ],
              ),
              padding: const EdgeInsets.all(18.0),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  // Food 1 Input
                  const Text('Product 1', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 14, color: Colors.black87)),
                  const SizedBox(height: 6),
                  Autocomplete<String>(
                    optionsBuilder: (textVal) async {
                      if (textVal.text.length < 2) return const Iterable<String>.empty();
                      return await ApiService.fetchFoodSuggestions(textVal.text);
                    },
                    onSelected: (val) => _food1Controller.text = val,
                    fieldViewBuilder: (ctx, ctrl, focus, _) {
                      if (_food1Controller.text.isNotEmpty && ctrl.text.isEmpty) {
                        ctrl.text = _food1Controller.text;
                      }
                      ctrl.addListener(() => _food1Controller.text = ctrl.text);
                      return TextField(
                        controller: ctrl,
                        focusNode: focus,
                        decoration: InputDecoration(
                          hintText: 'e.g. Maggi Noodles',
                          prefixIcon: const Icon(Icons.fastfood_outlined, color: Colors.orange),
                          filled: true,
                          fillColor: const Color(0xFFFBFBFB),
                          border: OutlineInputBorder(borderRadius: BorderRadius.circular(12)),
                          contentPadding: const EdgeInsets.symmetric(horizontal: 14, vertical: 14),
                        ),
                      );
                    },
                  ),
                  const SizedBox(height: 14),

                  // VS Divider
                  const Center(
                    child: CircleAvatar(
                      radius: 18,
                      backgroundColor: Color(0xFFFF9800),
                      child: Text(
                        'VS',
                        style: TextStyle(color: Colors.white, fontWeight: FontWeight.bold, fontSize: 13),
                      ),
                    ),
                  ),
                  const SizedBox(height: 14),

                  // Food 2 Input
                  const Text('Product 2', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 14, color: Colors.black87)),
                  const SizedBox(height: 6),
                  Autocomplete<String>(
                    optionsBuilder: (textVal) async {
                      if (textVal.text.length < 2) return const Iterable<String>.empty();
                      return await ApiService.fetchFoodSuggestions(textVal.text);
                    },
                    onSelected: (val) => _food2Controller.text = val,
                    fieldViewBuilder: (ctx, ctrl, focus, _) {
                      if (_food2Controller.text.isNotEmpty && ctrl.text.isEmpty) {
                        ctrl.text = _food2Controller.text;
                      }
                      ctrl.addListener(() => _food2Controller.text = ctrl.text);
                      return TextField(
                        controller: ctrl,
                        focusNode: focus,
                        decoration: InputDecoration(
                          hintText: 'e.g. Top Ramen or Yippee',
                          prefixIcon: const Icon(Icons.fastfood_outlined, color: Color(0xFF2E7D32)),
                          filled: true,
                          fillColor: const Color(0xFFFBFBFB),
                          border: OutlineInputBorder(borderRadius: BorderRadius.circular(12)),
                          contentPadding: const EdgeInsets.symmetric(horizontal: 14, vertical: 14),
                        ),
                      );
                    },
                  ),
                  const SizedBox(height: 18),

                  // Action Buttons: [ Compare Now ] [ Clear ]
                  Row(
                    children: [
                      Expanded(
                        flex: 3,
                        child: ElevatedButton.icon(
                          onPressed: _isComparing ? null : _runComparison,
                          icon: _isComparing
                              ? const SizedBox(
                                  width: 18,
                                  height: 18,
                                  child: CircularProgressIndicator(color: Colors.white, strokeWidth: 2),
                                )
                              : const Icon(Icons.compare_arrows_rounded),
                          label: Text(
                            _isComparing ? 'Comparing...' : 'Compare Now',
                            style: const TextStyle(fontSize: 15, fontWeight: FontWeight.bold),
                          ),
                          style: ElevatedButton.styleFrom(
                            backgroundColor: const Color(0xFF2E7D32),
                            foregroundColor: Colors.white,
                            padding: const EdgeInsets.symmetric(vertical: 14),
                            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                            elevation: 2,
                          ),
                        ),
                      ),
                      const SizedBox(width: 10),
                      Expanded(
                        flex: 2,
                        child: OutlinedButton.icon(
                          onPressed: _isComparing ? null : _clearComparison,
                          icon: const Icon(Icons.delete_sweep_outlined, size: 20),
                          label: const Text(
                            'Clear',
                            style: TextStyle(fontSize: 15, fontWeight: FontWeight.bold),
                          ),
                          style: OutlinedButton.styleFrom(
                            foregroundColor: const Color(0xFFD32F2F),
                            side: BorderSide(color: Colors.red.shade300, width: 1.5),
                            padding: const EdgeInsets.symmetric(vertical: 14),
                            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                            backgroundColor: Colors.white,
                          ),
                        ),
                      ),
                    ],
                  ),
                ],
              ),
            ),
            const SizedBox(height: 16),

            // Error Message
            if (_errorMsg.isNotEmpty)
              Container(
                padding: const EdgeInsets.all(14),
                decoration: BoxDecoration(
                  color: const Color(0xFFFFEBEE),
                  borderRadius: BorderRadius.circular(12),
                  border: Border.all(color: Colors.red.shade200),
                ),
                child: Row(
                  children: [
                    const Icon(Icons.error_outline, color: Colors.red),
                    const SizedBox(width: 10),
                    Expanded(
                      child: Text(_errorMsg, style: const TextStyle(color: Colors.red, fontSize: 13)),
                    ),
                  ],
                ),
              ),

            // Winner Declaration
            if (_winner != null && _food1Result != null && _food2Result != null) ...[
              Container(
                decoration: BoxDecoration(
                  color: _winner == "TIE" ? const Color(0xFFFFF8E1) : const Color(0xFFE8F5E9),
                  borderRadius: BorderRadius.circular(16),
                  border: Border.all(
                    color: _winner == "TIE" ? Colors.orange.shade300 : const Color(0xFF4CAF50),
                    width: 1.5,
                  ),
                  boxShadow: [
                    BoxShadow(
                      color: Colors.black.withValues(alpha: 0.03),
                      blurRadius: 10,
                      offset: const Offset(0, 2),
                    ),
                  ],
                ),
                padding: const EdgeInsets.all(18),
                child: Column(
                  children: [
                    Row(
                      mainAxisAlignment: MainAxisAlignment.center,
                      children: [
                        Text(_winner == "TIE" ? "🤝" : "🏆", style: const TextStyle(fontSize: 28)),
                        const SizedBox(width: 8),
                        Expanded(
                          child: Text(
                            _winner == "TIE" ? "It's a Tie!" : "Healthier Winner: $_winner",
                            style: TextStyle(
                              fontSize: 18,
                              fontWeight: FontWeight.bold,
                              color: _winner == "TIE" ? Colors.orange.shade900 : const Color(0xFF1B5E20),
                            ),
                          ),
                        ),
                      ],
                    ),
                    const SizedBox(height: 6),
                    Text(
                      _winnerReason,
                      textAlign: TextAlign.center,
                      style: TextStyle(
                        fontSize: 13,
                        color: _winner == "TIE" ? Colors.orange.shade900 : const Color(0xFF2E7D32),
                        fontWeight: FontWeight.w500,
                      ),
                    ),
                  ],
                ),
              ),
              const SizedBox(height: 16),

              // Side by Side Cards
              Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Expanded(
                    child: _buildFoodCard(
                      _food1Result!,
                      isWinner: _winner == _food1Result!['productName'],
                      otherNutrition: _food2Result!['nutrition'],
                    ),
                  ),
                  const SizedBox(width: 12),
                  Expanded(
                    child: _buildFoodCard(
                      _food2Result!,
                      isWinner: _winner == _food2Result!['productName'],
                      otherNutrition: _food1Result!['nutrition'],
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 24),
            ],
          ],
        ),
      ),
    );
  }

  Widget _buildFoodCard(Map<String, dynamic> foodData,
      {required bool isWinner, required Map<String, dynamic> otherNutrition}) {
    final name = foodData['productName'] ?? 'Product';
    final company = foodData['companyName'] ?? '';
    final n = foodData['nutrition'] as Map<String, dynamic>;

    return Container(
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(
          color: isWinner ? const Color(0xFF2E7D32) : Colors.grey.shade200,
          width: isWinner ? 2 : 1,
        ),
        boxShadow: [
          BoxShadow(
            color: Colors.black.withValues(alpha: 0.04),
            blurRadius: 8,
            offset: const Offset(0, 2),
          ),
        ],
      ),
      padding: const EdgeInsets.all(14),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          if (isWinner)
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
              margin: const EdgeInsets.only(bottom: 6),
              decoration: BoxDecoration(
                color: const Color(0xFF2E7D32),
                borderRadius: BorderRadius.circular(6),
              ),
              child: const Text('🏆 WINNER', style: TextStyle(color: Colors.white, fontSize: 10, fontWeight: FontWeight.bold)),
            ),
          Text(
            name,
            style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 14, color: Colors.black87),
            maxLines: 2,
            overflow: TextOverflow.ellipsis,
          ),
          if (company.isNotEmpty)
            Text(
              company,
              style: TextStyle(fontSize: 11, color: Colors.grey.shade600),
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
            ),
          const SizedBox(height: 10),
          const Divider(height: 1),
          const SizedBox(height: 10),
          _buildCompareNutrientLine('Sugar', '${n['sugar'] ?? 0}g', (n['sugar'] ?? 0) <= (otherNutrition['sugar'] ?? 0)),
          _buildCompareNutrientLine('Salt', '${n['salt'] ?? 0}mg', (n['salt'] ?? 0) <= (otherNutrition['salt'] ?? 0)),
          _buildCompareNutrientLine('Fat', '${n['fat'] ?? 0}g', (n['fat'] ?? 0) <= (otherNutrition['fat'] ?? 0)),
          _buildCompareNutrientLine('Calories', '${n['calories'] ?? 0} kcal', (n['calories'] ?? 0) <= (otherNutrition['calories'] ?? 0)),
          if ((n['protein'] ?? 0) > 0)
            _buildCompareNutrientLine('Protein', '${n['protein']}g', (n['protein'] ?? 0) >= (otherNutrition['protein'] ?? 0)),
          if ((n['fiber'] ?? 0) > 0)
            _buildCompareNutrientLine('Fiber', '${n['fiber']}g', (n['fiber'] ?? 0) >= (otherNutrition['fiber'] ?? 0)),
        ],
      ),
    );
  }

  Widget _buildCompareNutrientLine(String label, String value, bool isBetter) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 8.0),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        children: [
          Text(label, style: const TextStyle(fontSize: 12, color: Colors.black54)),
          Row(
            children: [
              Text(
                value,
                style: TextStyle(
                  fontSize: 12,
                  fontWeight: FontWeight.bold,
                  color: isBetter ? const Color(0xFF2E7D32) : Colors.black87,
                ),
              ),
              if (isBetter) ...[
                const SizedBox(width: 3),
                const Icon(Icons.check, size: 12, color: Color(0xFF2E7D32)),
              ],
            ],
          ),
        ],
      ),
    );
  }
}
