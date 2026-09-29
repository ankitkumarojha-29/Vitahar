import 'dart:async';
import 'package:flutter/material.dart';
import 'scanner_screen.dart';
import 'result_screen.dart';
import 'food_detail_screen.dart';
import '../services/api_service.dart';

class DashboardScreen extends StatefulWidget {
  const DashboardScreen({super.key});

  @override
  State<DashboardScreen> createState() => _DashboardScreenState();
}

class _DashboardScreenState extends State<DashboardScreen> {
  final _productNameController = TextEditingController();
  final _companyNameController = TextEditingController();
  final _dobController = TextEditingController();
  final _headerSearchController = TextEditingController();

  DateTime? _selectedDate;
  int? _calculatedAge;

  // Header Search Autocomplete state (Normal Food Search)
  Timer? _headerDebounce;
  List<String> _headerSuggestions = [];
  bool _isSearchingHeader = false;
  bool _showHeaderSuggestions = false;
  int _headerRequestId = 0;

  // Food Safety Verification Autocomplete state
  Timer? _productDebounce;
  List<String> _productSuggestions = [];
  bool _isSearchingProduct = false;
  bool _showProductSuggestions = false;
  int _productRequestId = 0;

  // Company Recommendation state
  bool _isDetectingCompany = false;
  String _autoDetectedCompany = "";

  // Company Suggestions state
  Timer? _companyDebounce;
  List<String> _companySuggestions = [];
  bool _showCompanySuggestions = false;

  @override
  void initState() {
    super.initState();
    // Warm up bootstrap from MySQL on dashboard load
    ApiService.fetchBootstrapData();
  }

  @override
  void dispose() {
    _headerDebounce?.cancel();
    _productDebounce?.cancel();
    _companyDebounce?.cancel();
    _productNameController.dispose();
    _companyNameController.dispose();
    _dobController.dispose();
    _headerSearchController.dispose();
    super.dispose();
  }

  // --- Normal Food Search (Header Search) ---
  void _onHeaderSearchChanged(String text) {
    _headerDebounce?.cancel();
    final query = text.trim();

    if (query.length < 2) {
      setState(() {
        _headerSuggestions = [];
        _isSearchingHeader = false;
        _showHeaderSuggestions = false;
      });
      return;
    }

    setState(() => _isSearchingHeader = true);
    final reqId = ApiService.nextRequestId();
    _headerRequestId = reqId;

    // 350ms debounce
    _headerDebounce = Timer(const Duration(milliseconds: 350), () async {
      final results = await ApiService.fetchFoodSuggestions(query, sequenceId: reqId);
      if (reqId == _headerRequestId && mounted) {
        setState(() {
          _headerSuggestions = results;
          _isSearchingHeader = false;
          _showHeaderSuggestions = results.isNotEmpty;
        });
      }
    });
  }

  void _selectHeaderSuggestion(String product) {
    _headerSearchController.text = product;
    setState(() {
      _showHeaderSuggestions = false;
    });
    _runHeaderSearch(product);
  }

  void _runHeaderSearch([String? selectedQuery]) {
    final query = (selectedQuery ?? _headerSearchController.text).trim();
    if (query.isEmpty) return;

    setState(() {
      _showHeaderSuggestions = false;
    });

    // Normal Food Search: Only show nutrition facts without age-based safety verdict
    Navigator.push(
      context,
      MaterialPageRoute(
        builder: (context) => FoodDetailScreen(
          productName: query,
        ),
      ),
    );
  }

  // --- Food Safety Verification: Product Input ---
  void _onProductInputChanged(String text) {
    _productDebounce?.cancel();
    final query = text.trim();

    if (query.length < 2) {
      setState(() {
        _productSuggestions = [];
        _isSearchingProduct = false;
        _showProductSuggestions = false;
      });
      return;
    }

    setState(() => _isSearchingProduct = true);
    final reqId = ApiService.nextRequestId();
    _productRequestId = reqId;

    _productDebounce = Timer(const Duration(milliseconds: 350), () async {
      final results = await ApiService.fetchFoodSuggestions(query, sequenceId: reqId);
      if (reqId == _productRequestId && mounted) {
        setState(() {
          _productSuggestions = results;
          _isSearchingProduct = false;
          _showProductSuggestions = results.isNotEmpty;
        });
      }
    });
  }

  void _selectProductSuggestion(String product) {
    _productNameController.text = product;
    setState(() {
      _showProductSuggestions = false;
    });
    _autoDetectCompany(product);
  }

  // --- Automatic Company Detection (Web Synchronization) ---
  Future<void> _autoDetectCompany(String productName) async {
    setState(() {
      _isDetectingCompany = true;
      _autoDetectedCompany = "";
    });

    final company = await ApiService.getCompanyForProduct(productName);

    if (mounted) {
      setState(() {
        _isDetectingCompany = false;
        if (company.isNotEmpty) {
          _companyNameController.text = company;
          _autoDetectedCompany = company;
        }
      });
    }
  }

  // --- Company Input Autocomplete ---
  void _onCompanyInputChanged(String text) {
    _companyDebounce?.cancel();
    final query = text.trim();

    if (query.length < 2) {
      setState(() {
        _companySuggestions = [];
        _showCompanySuggestions = false;
      });
      return;
    }

    _companyDebounce = Timer(const Duration(milliseconds: 300), () async {
      final results = await ApiService.fetchCompanySuggestions(query);
      if (mounted) {
        setState(() {
          _companySuggestions = results;
          _showCompanySuggestions = results.isNotEmpty;
        });
      }
    });
  }

  // --- Date of Birth Picker ---
  Future<void> _selectDate(BuildContext context) async {
    final DateTime now = DateTime.now();
    final DateTime? picked = await showDatePicker(
      context: context,
      initialDate: _selectedDate ?? DateTime(2002, 1, 1),
      firstDate: DateTime(1920),
      lastDate: now,
      helpText: 'Select Date of Birth for Food Safety Verification',
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

    if (picked != null) {
      setState(() {
        _selectedDate = picked;
        _dobController.text =
            "${picked.day.toString().padLeft(2, '0')}-${picked.month.toString().padLeft(2, '0')}-${picked.year}";
        _calculatedAge = now.year - picked.year;
        if (now.month < picked.month || (now.month == picked.month && now.day < picked.day)) {
          _calculatedAge = _calculatedAge! - 1;
        }
      });
    }
  }

  // --- Food Safety Verification Action ---
  void _verifySafety() {
    final product = _productNameController.text.trim();
    final dob = _dobController.text.trim();

    if (product.isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Please enter or select a product name.'),
          backgroundColor: Color(0xFFE65100),
        ),
      );
      return;
    }

    if (dob.isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Please select your Date of Birth to check age safety.'),
          backgroundColor: Color(0xFFE65100),
        ),
      );
      return;
    }

    Navigator.push(
      context,
      MaterialPageRoute(
        builder: (context) => ResultScreen(
          productName: product,
          companyName: _companyNameController.text.trim(),
          dob: dob,
        ),
      ),
    );
  }

  // --- Quick Scan Barcode ---
  void _openScanner() async {
    final result = await Navigator.push<Map<String, dynamic>>(
      context,
      MaterialPageRoute(builder: (context) => const ScannerScreen()),
    );

    if (result != null) {
      final name = result['productName']?.toString() ?? '';
      final company = result['companyName']?.toString() ?? '';

      setState(() {
        if (name.isNotEmpty) {
          _productNameController.text = name;
        }
        if (company.isNotEmpty) {
          _companyNameController.text = company;
          _autoDetectedCompany = company;
        }
      });

      if (context.mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text('Barcode detected: $name'),
            backgroundColor: const Color(0xFF2E7D32),
            duration: const Duration(seconds: 2),
          ),
        );
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: const Color(0xFFF9F7F1),
      appBar: AppBar(
        backgroundColor: Colors.white,
        elevation: 0.5,
        titleSpacing: 16,
        title: Row(
          children: [
            Image.asset(
              'assets/vitahar-logo.png',
              height: 38,
              errorBuilder: (_, __, ___) => const Icon(Icons.eco, color: Color(0xFF2E7D32), size: 36),
            ),
            const SizedBox(width: 10),
            const Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  'Vitahar',
                  style: TextStyle(fontWeight: FontWeight.bold, fontSize: 20, color: Color(0xFF2E7D32)),
                ),
                Text(
                  'Verified Food for Every Age',
                  style: TextStyle(fontSize: 11, color: Colors.grey, fontWeight: FontWeight.w500),
                ),
              ],
            ),
          ],
        ),
      ),
      body: GestureDetector(
        onTap: () {
          // Dismiss suggestion overlays when tapping outside
          if (_showHeaderSuggestions || _showProductSuggestions || _showCompanySuggestions) {
            setState(() {
              _showHeaderSuggestions = false;
              _showProductSuggestions = false;
              _showCompanySuggestions = false;
            });
          }
        },
        child: SingleChildScrollView(
          padding: const EdgeInsets.symmetric(horizontal: 16.0, vertical: 12.0),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              // 1. Normal Food Search Bar (Header Search)
              Container(
                decoration: BoxDecoration(
                  color: Colors.white,
                  borderRadius: BorderRadius.circular(16),
                  boxShadow: [
                    BoxShadow(
                      color: Colors.black.withValues(alpha: 0.04),
                      blurRadius: 10,
                      offset: const Offset(0, 2),
                    ),
                  ],
                ),
                padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 4),
                child: Row(
                  children: [
                    const Icon(Icons.search, color: Color(0xFF2E7D32), size: 22),
                    const SizedBox(width: 8),
                    Expanded(
                      child: TextField(
                        controller: _headerSearchController,
                        textInputAction: TextInputAction.search,
                        onChanged: _onHeaderSearchChanged,
                        onSubmitted: (_) => _runHeaderSearch(),
                        decoration: const InputDecoration(
                          hintText: 'Search food (e.g. Maggi, Parle-G, Chips)...',
                          hintStyle: TextStyle(fontSize: 13, color: Colors.grey),
                          border: InputBorder.none,
                          isDense: true,
                          contentPadding: EdgeInsets.symmetric(vertical: 10),
                        ),
                      ),
                    ),
                    if (_isSearchingHeader)
                      const SizedBox(
                        width: 18,
                        height: 18,
                        child: CircularProgressIndicator(strokeWidth: 2, color: Color(0xFF2E7D32)),
                      )
                    else if (_headerSearchController.text.isNotEmpty)
                      IconButton(
                        icon: const Icon(Icons.clear, color: Colors.grey, size: 18),
                        onPressed: () {
                          _headerSearchController.clear();
                          setState(() {
                            _headerSuggestions = [];
                            _showHeaderSuggestions = false;
                          });
                        },
                      ),
                    IconButton(
                      icon: const Icon(Icons.arrow_forward_rounded, color: Color(0xFF2E7D32), size: 20),
                      onPressed: () => _runHeaderSearch(),
                    ),
                  ],
                ),
              ),

              // Header Search Autocomplete Dropdown
              if (_showHeaderSuggestions && _headerSuggestions.isNotEmpty)
                Container(
                  margin: const EdgeInsets.only(top: 4),
                  decoration: BoxDecoration(
                    color: Colors.white,
                    borderRadius: BorderRadius.circular(14),
                    boxShadow: [
                      BoxShadow(
                        color: Colors.black.withValues(alpha: 0.08),
                        blurRadius: 12,
                        offset: const Offset(0, 4),
                      ),
                    ],
                  ),
                  constraints: const BoxConstraints(maxHeight: 220),
                  child: ListView.separated(
                    shrinkWrap: true,
                    padding: const EdgeInsets.symmetric(vertical: 6),
                    itemCount: _headerSuggestions.length,
                    separatorBuilder: (_, __) => const Divider(height: 1, indent: 16, endIndent: 16),
                    itemBuilder: (context, index) {
                      final item = _headerSuggestions[index];
                      return ListTile(
                        dense: true,
                        leading: const Icon(Icons.restaurant_outlined, color: Color(0xFF2E7D32), size: 18),
                        title: Text(item, style: const TextStyle(fontSize: 14, fontWeight: FontWeight.w500)),
                        trailing: const Icon(Icons.north_west, size: 14, color: Colors.grey),
                        onTap: () => _selectHeaderSuggestion(item),
                      );
                    },
                  ),
                ),
              const SizedBox(height: 16),

              // Hero Banner Card
              Container(
                decoration: BoxDecoration(
                  gradient: const LinearGradient(
                    colors: [Color(0xFF2E7D32), Color(0xFF43A047)],
                    begin: Alignment.topLeft,
                    end: Alignment.bottomRight,
                  ),
                  borderRadius: BorderRadius.circular(20),
                  boxShadow: [
                    BoxShadow(
                      color: const Color(0xFF2E7D32).withValues(alpha: 0.25),
                      blurRadius: 12,
                      offset: const Offset(0, 4),
                    ),
                  ],
                ),
                padding: const EdgeInsets.all(20.0),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Container(
                      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                      decoration: BoxDecoration(
                        color: Colors.white.withValues(alpha: 0.2),
                        borderRadius: BorderRadius.circular(20),
                      ),
                      child: const Row(
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          Icon(Icons.shield_outlined, color: Colors.white, size: 14),
                          SizedBox(width: 4),
                          Text('FSSAI & WHO Aligned', style: TextStyle(color: Colors.white, fontSize: 11, fontWeight: FontWeight.bold)),
                        ],
                      ),
                    ),
                    const SizedBox(height: 12),
                    const Text(
                      'Is your packaged food safe for you?',
                      style: TextStyle(
                        fontSize: 20,
                        fontWeight: FontWeight.bold,
                        color: Colors.white,
                        height: 1.2,
                      ),
                    ),
                    const SizedBox(height: 6),
                    Text(
                      'Analyze sugar, salt, and fat instantly based on exact age health standards.',
                      style: TextStyle(
                        fontSize: 13,
                        color: Colors.white.withValues(alpha: 0.9),
                      ),
                    ),
                  ],
                ),
              ),
              const SizedBox(height: 16),

              // Quick Scan Barcode Button
              InkWell(
                onTap: _openScanner,
                borderRadius: BorderRadius.circular(16),
                child: Container(
                  decoration: BoxDecoration(
                    color: Colors.white,
                    borderRadius: BorderRadius.circular(16),
                    border: Border.all(color: const Color(0xFFFF9800).withValues(alpha: 0.5), width: 1.5),
                    boxShadow: [
                      BoxShadow(
                        color: const Color(0xFFFF9800).withValues(alpha: 0.08),
                        blurRadius: 10,
                        offset: const Offset(0, 2),
                      ),
                    ],
                  ),
                  padding: const EdgeInsets.all(16.0),
                  child: Row(
                    children: [
                      Container(
                        padding: const EdgeInsets.all(10),
                        decoration: BoxDecoration(
                          color: const Color(0xFFFFF3E0),
                          borderRadius: BorderRadius.circular(12),
                        ),
                        child: const Icon(Icons.qr_code_scanner, color: Color(0xFFE65100), size: 26),
                      ),
                      const SizedBox(width: 14),
                      const Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(
                              'Scan Food Barcode / QR Code',
                              style: TextStyle(fontSize: 15, fontWeight: FontWeight.bold, color: Color(0xFFE65100)),
                            ),
                            SizedBox(height: 2),
                            Text(
                              'Instant auto-fill via 6-source food database',
                              style: TextStyle(fontSize: 12, color: Colors.grey),
                            ),
                          ],
                        ),
                      ),
                      const Icon(Icons.chevron_right, color: Color(0xFFE65100)),
                    ],
                  ),
                ),
              ),
              const SizedBox(height: 16),

              // Food Safety Verification Card
              Container(
                decoration: BoxDecoration(
                  color: Colors.white,
                  borderRadius: BorderRadius.circular(20),
                  boxShadow: [
                    BoxShadow(
                      color: Colors.black.withValues(alpha: 0.05),
                      blurRadius: 15,
                      offset: const Offset(0, 4),
                    ),
                  ],
                ),
                padding: const EdgeInsets.all(22.0),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    const Text(
                      'Food Safety Verification',
                      style: TextStyle(
                        fontSize: 22,
                        fontWeight: FontWeight.bold,
                        color: Color(0xFF2E7D32),
                      ),
                      textAlign: TextAlign.center,
                    ),
                    const SizedBox(height: 4),
                    const Text(
                      'Check whether a packaged food is safe for your age',
                      style: TextStyle(color: Colors.grey, fontSize: 13),
                      textAlign: TextAlign.center,
                    ),
                    const SizedBox(height: 22),

                    // 1. Product Name Input with Smart Live Dropdown
                    Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        TextField(
                          controller: _productNameController,
                          onChanged: _onProductInputChanged,
                          decoration: InputDecoration(
                            labelText: 'Product Name',
                            hintText: 'e.g. Maggi, Oreo, Parle-G',
                            prefixIcon: const Icon(Icons.shopping_bag_outlined, color: Color(0xFF2E7D32)),
                            suffixIcon: _isSearchingProduct
                                ? const SizedBox(
                                    width: 20,
                                    height: 20,
                                    child: Center(
                                      child: SizedBox(
                                        width: 18,
                                        height: 18,
                                        child: CircularProgressIndicator(strokeWidth: 2, color: Color(0xFF2E7D32)),
                                      ),
                                    ),
                                  )
                                : null,
                            border: OutlineInputBorder(borderRadius: BorderRadius.circular(14)),
                            focusedBorder: OutlineInputBorder(
                              borderRadius: BorderRadius.circular(14),
                              borderSide: const BorderSide(color: Color(0xFF2E7D32), width: 2),
                            ),
                            filled: true,
                            fillColor: const Color(0xFFFBFBFB),
                            contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 16),
                          ),
                        ),

                        // Live Product Suggestions Dropdown
                        if (_showProductSuggestions && _productSuggestions.isNotEmpty)
                          Container(
                            margin: const EdgeInsets.only(top: 4),
                            decoration: BoxDecoration(
                              color: Colors.white,
                              borderRadius: BorderRadius.circular(14),
                              border: Border.all(color: Colors.grey.shade200),
                              boxShadow: [
                                BoxShadow(
                                  color: Colors.black.withValues(alpha: 0.08),
                                  blurRadius: 10,
                                  offset: const Offset(0, 4),
                                ),
                              ],
                            ),
                            constraints: const BoxConstraints(maxHeight: 200),
                            child: ListView.separated(
                              shrinkWrap: true,
                              padding: const EdgeInsets.symmetric(vertical: 4),
                              itemCount: _productSuggestions.length,
                              separatorBuilder: (_, __) => const Divider(height: 1, indent: 16, endIndent: 16),
                              itemBuilder: (context, index) {
                                final item = _productSuggestions[index];
                                return ListTile(
                                  dense: true,
                                  leading: const Icon(Icons.check_circle_outline, color: Color(0xFF2E7D32), size: 18),
                                  title: Text(item, style: const TextStyle(fontSize: 14, fontWeight: FontWeight.w500)),
                                  onTap: () => _selectProductSuggestion(item),
                                );
                              },
                            ),
                          ),
                      ],
                    ),
                    const SizedBox(height: 16),

                    // Automatic Company Recommendation Display (Requirements 15 & 16)
                    if (_isDetectingCompany)
                      Padding(
                        padding: const EdgeInsets.only(bottom: 8.0),
                        child: Row(
                          children: [
                            const SizedBox(
                              width: 14,
                              height: 14,
                              child: CircularProgressIndicator(strokeWidth: 2, color: Color(0xFF2E7D32)),
                            ),
                            const SizedBox(width: 8),
                            Text(
                              'Recommending company for ${_productNameController.text}...',
                              style: const TextStyle(fontSize: 12, color: Color(0xFF2E7D32), fontStyle: FontStyle.italic),
                            ),
                          ],
                        ),
                      )
                    else if (_autoDetectedCompany.isNotEmpty)
                      Padding(
                        padding: const EdgeInsets.only(bottom: 8.0),
                        child: Container(
                          padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
                          decoration: BoxDecoration(
                            color: const Color(0xFFE8F5E9),
                            borderRadius: BorderRadius.circular(8),
                            border: Border.all(color: const Color(0xFFA5D6A7)),
                          ),
                          child: Row(
                            mainAxisSize: MainAxisSize.min,
                            children: [
                              const Icon(Icons.business, size: 14, color: Color(0xFF2E7D32)),
                              const SizedBox(width: 6),
                              Text(
                                'Recommended Company: $_autoDetectedCompany',
                                style: const TextStyle(fontSize: 12, fontWeight: FontWeight.bold, color: Color(0xFF2E7D32)),
                              ),
                            ],
                          ),
                        ),
                      ),

                    // 2. Company Name Input
                    Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        TextField(
                          controller: _companyNameController,
                          onChanged: _onCompanyInputChanged,
                          decoration: InputDecoration(
                            labelText: 'Company / Brand',
                            hintText: 'Auto-recommended or enter brand',
                            prefixIcon: const Icon(Icons.business_outlined, color: Color(0xFF2E7D32)),
                            border: OutlineInputBorder(borderRadius: BorderRadius.circular(14)),
                            focusedBorder: OutlineInputBorder(
                              borderRadius: BorderRadius.circular(14),
                              borderSide: const BorderSide(color: Color(0xFF2E7D32), width: 2),
                            ),
                            filled: true,
                            fillColor: const Color(0xFFFBFBFB),
                            contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 16),
                          ),
                        ),

                        // Company Suggestions Dropdown
                        if (_showCompanySuggestions && _companySuggestions.isNotEmpty)
                          Container(
                            margin: const EdgeInsets.only(top: 4),
                            decoration: BoxDecoration(
                              color: Colors.white,
                              borderRadius: BorderRadius.circular(14),
                              border: Border.all(color: Colors.grey.shade200),
                              boxShadow: [
                                BoxShadow(
                                  color: Colors.black.withValues(alpha: 0.08),
                                  blurRadius: 10,
                                  offset: const Offset(0, 4),
                                ),
                              ],
                            ),
                            constraints: const BoxConstraints(maxHeight: 180),
                            child: ListView.separated(
                              shrinkWrap: true,
                              padding: const EdgeInsets.symmetric(vertical: 4),
                              itemCount: _companySuggestions.length,
                              separatorBuilder: (_, __) => const Divider(height: 1, indent: 16, endIndent: 16),
                              itemBuilder: (context, index) {
                                final item = _companySuggestions[index];
                                return ListTile(
                                  dense: true,
                                  leading: const Icon(Icons.apartment, color: Color(0xFF2E7D32), size: 18),
                                  title: Text(item, style: const TextStyle(fontSize: 14)),
                                  onTap: () {
                                    setState(() {
                                      _companyNameController.text = item;
                                      _showCompanySuggestions = false;
                                    });
                                  },
                                );
                              },
                            ),
                          ),
                      ],
                    ),
                    const SizedBox(height: 16),

                    // 3. Date of Birth Picker (DOB-based verification only)
                    TextField(
                      controller: _dobController,
                      readOnly: true,
                      onTap: () => _selectDate(context),
                      decoration: InputDecoration(
                        labelText: 'Date of Birth',
                        hintText: 'Select your birthday (dd-mm-yyyy)',
                        prefixIcon: const Icon(Icons.cake_outlined, color: Color(0xFF2E7D32)),
                        suffixIcon: _calculatedAge != null
                            ? Container(
                                padding: const EdgeInsets.all(8),
                                child: Chip(
                                  label: Text('$_calculatedAge yrs', style: const TextStyle(fontSize: 11, fontWeight: FontWeight.bold, color: Color(0xFF2E7D32))),
                                  backgroundColor: const Color(0xFFE8F5E9),
                                  padding: EdgeInsets.zero,
                                ),
                              )
                            : const Icon(Icons.calendar_month, color: Color(0xFF2E7D32)),
                        border: OutlineInputBorder(borderRadius: BorderRadius.circular(14)),
                        focusedBorder: OutlineInputBorder(
                          borderRadius: BorderRadius.circular(14),
                          borderSide: const BorderSide(color: Color(0xFF2E7D32), width: 2),
                        ),
                        filled: true,
                        fillColor: const Color(0xFFFBFBFB),
                        contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 16),
                      ),
                    ),
                    const SizedBox(height: 24),

                    // Action Buttons
                    Row(
                      children: [
                        Expanded(
                          child: ElevatedButton(
                            onPressed: _verifySafety,
                            style: ElevatedButton.styleFrom(
                              backgroundColor: const Color(0xFF2E7D32),
                              foregroundColor: Colors.white,
                              padding: const EdgeInsets.symmetric(vertical: 16),
                              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
                              elevation: 2,
                              shadowColor: const Color(0xFF2E7D32).withValues(alpha: 0.4),
                            ),
                            child: const Row(
                              mainAxisAlignment: MainAxisAlignment.center,
                              children: [
                                Icon(Icons.health_and_safety_outlined, size: 20),
                                SizedBox(width: 8),
                                Text(
                                  'Verify Food Safety',
                                  style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold),
                                ),
                              ],
                            ),
                          ),
                        ),
                        const SizedBox(width: 12),
                        InkWell(
                          onTap: () {
                            setState(() {
                              _productNameController.clear();
                              _companyNameController.clear();
                              _dobController.clear();
                              _selectedDate = null;
                              _calculatedAge = null;
                              _autoDetectedCompany = "";
                              _headerSuggestions = [];
                              _showHeaderSuggestions = false;
                              _productSuggestions = [];
                              _showProductSuggestions = false;
                            });
                          },
                          borderRadius: BorderRadius.circular(14),
                          child: Container(
                            padding: const EdgeInsets.all(16),
                            decoration: BoxDecoration(
                              color: const Color(0xFFFFEBEE),
                              borderRadius: BorderRadius.circular(14),
                              border: Border.all(color: Colors.red.shade200),
                            ),
                            child: const Icon(Icons.delete_outline, color: Color(0xFFD32F2F), size: 24),
                          ),
                        ),
                      ],
                    ),
                  ],
                ),
              ),
              const SizedBox(height: 24),
            ],
          ),
        ),
      ),
    );
  }
}
