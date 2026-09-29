import 'dart:convert';
import 'dart:typed_data';
import 'package:http/http.dart' as http;
import 'package:encrypt/encrypt.dart' as enc;

class CacheEntry<T> {
  final T data;
  final DateTime expiry;
  CacheEntry(this.data, {Duration ttl = const Duration(minutes: 5)})
      : expiry = DateTime.now().add(ttl);
  bool get isExpired => DateTime.now().isAfter(expiry);
}

class ApiService {
  static const String baseUrl = 'https://vitahar.kesug.com';

  // InfinityFree Anti-Bot Challenge Session Token
  static String? _testCookie;

  // In-memory lightweight caches with 5-minute TTL to prevent redundant network calls
  static final Map<String, CacheEntry<List<String>>> _suggestionCache = {};
  static final Map<String, CacheEntry<Map<String, dynamic>?>> _foodSearchCache = {};
  static final Map<String, CacheEntry<String>> _companyCache = {};
  static int _searchRequestId = 0;

  static int nextRequestId() => ++_searchRequestId;
  static int get currentRequestId => _searchRequestId;

  // Dynamic Age Limits from MySQL database (site_settings: age_limits)
  static Map<String, Map<String, num>> ageLimits = {
    'age_0_5': {'calories': 200, 'sugar': 8, 'salt': 300, 'fat': 12, 'protein': 13, 'fiber': 14},
    'age_5_12': {'calories': 350, 'sugar': 15, 'salt': 700, 'fat': 20, 'protein': 20, 'fiber': 18},
    'age_12_18': {'calories': 450, 'sugar': 25, 'salt': 1200, 'fat': 28, 'protein': 34, 'fiber': 22},
    'age_18_30': {'calories': 500, 'sugar': 30, 'salt': 1600, 'fat': 35, 'protein': 50, 'fiber': 25},
    'age_30_60': {'calories': 450, 'sugar': 25, 'salt': 1400, 'fat': 30, 'protein': 46, 'fiber': 25},
    'age_60_plus': {'calories': 400, 'sugar': 20, 'salt': 1000, 'fat': 25, 'protein': 40, 'fiber': 21},
  };

  // Cached foods and companies from bootstrap
  static Map<String, dynamic> cachedFoods = {};
  static Map<String, List<String>> cachedCompanies = {};
  static List<dynamic> cachedNutritionColumns = [];
  static bool isBootstrapped = false;

  // ─────────────────────────────────────────────────────────────
  // INFINITYFREE ANTI-BOT COOKIE SOLVER & HTTP ENGINE
  // ─────────────────────────────────────────────────────────────
  static String _bytesToHex(List<int> bytes) {
    return bytes.map((b) => b.toRadixString(16).padLeft(2, '0')).join().toLowerCase();
  }

  static Uint8List _hexToBytes(String hex) {
    final result = Uint8List(hex.length ~/ 2);
    for (var i = 0; i < hex.length; i += 2) {
      result[i ~/ 2] = int.parse(hex.substring(i, i + 2), radix: 16);
    }
    return result;
  }

  /// Solves the InfinityFree slowAES challenge dynamically in pure Dart
  static String? _solveInfinityFreeChallenge(String html) {
    try {
      final regA = RegExp(r'var a=toNumbers\("([0-9a-fA-F]+)"\)');
      final regB = RegExp(r'b=toNumbers\("([0-9a-fA-F]+)"\)');
      final regC = RegExp(r'c=toNumbers\("([0-9a-fA-F]+)"\)');

      final matchA = regA.firstMatch(html);
      final matchB = regB.firstMatch(html);
      final matchC = regC.firstMatch(html);

      if (matchA != null && matchB != null && matchC != null) {
        final aHex = matchA.group(1)!;
        final bHex = matchB.group(1)!;
        final cHex = matchC.group(1)!;

        final key = enc.Key(_hexToBytes(aHex));
        final iv = enc.IV(_hexToBytes(bHex));
        final encrypter = enc.Encrypter(enc.AES(key, mode: enc.AESMode.cbc, padding: null));
        final decrypted = encrypter.decryptBytes(enc.Encrypted(_hexToBytes(cHex)), iv: iv);
        return _bytesToHex(decrypted);
      }
    } catch (e) {
      print('Challenge solve error: $e');
    }
    return null;
  }

  static Map<String, String> _buildHeaders({Map<String, String>? extra}) {
    final headers = {
      'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
      'Referer': '$baseUrl/',
      'Accept': 'application/json, text/html, */*',
    };
    if (_testCookie != null) {
      headers['Cookie'] = '__test=$_testCookie';
    }
    if (extra != null) {
      headers.addAll(extra);
    }
    return headers;
  }

  /// Internal GET with transparent InfinityFree challenge resolution
  static Future<http.Response> _get(Uri uri, {Duration timeout = const Duration(seconds: 10)}) async {
    http.Response res = await http.get(uri, headers: _buildHeaders()).timeout(timeout);
    if (res.body.contains('slowAES.decrypt')) {
      final cookie = _solveInfinityFreeChallenge(res.body);
      if (cookie != null) {
        _testCookie = cookie;
        res = await http.get(uri, headers: _buildHeaders()).timeout(timeout);
      }
    }
    return res;
  }

  /// Internal POST with transparent InfinityFree challenge resolution
  static Future<http.Response> _post(
    Uri uri, {
    Object? body,
    Map<String, String>? headers,
    Duration timeout = const Duration(seconds: 15),
  }) async {
    final allHeaders = _buildHeaders(extra: headers);
    http.Response res = await http.post(uri, headers: allHeaders, body: body).timeout(timeout);
    if (res.body.contains('slowAES.decrypt')) {
      final cookie = _solveInfinityFreeChallenge(res.body);
      if (cookie != null) {
        _testCookie = cookie;
        res = await http.post(uri, headers: _buildHeaders(extra: headers), body: body).timeout(timeout);
      }
    }
    return res;
  }

  // ─────────────────────────────────────────────────────────────
  // BOOTSTRAP & SYNC WITH MYSQL BACKEND
  // ─────────────────────────────────────────────────────────────
  /// Load bootstrap data from MySQL on app start
  static Future<Map<String, dynamic>?> fetchBootstrapData() async {
    try {
      final response = await _get(
        Uri.parse('$baseUrl/api.php?action=bootstrap'),
        timeout: const Duration(seconds: 10),
      );

      if (response.statusCode == 200) {
        final data = jsonDecode(response.body);
        if (data is Map<String, dynamic>) {
          if (data['foods'] is Map) {
            cachedFoods = Map<String, dynamic>.from(data['foods']);
          }
          if (data['companies'] is Map) {
            cachedCompanies = (data['companies'] as Map).map(
              (k, v) => MapEntry(k.toString(), (v as List).map((e) => e.toString()).toList()),
            );
          }
          if (data['nutritionColumns'] is List) {
            cachedNutritionColumns = data['nutritionColumns'];
          }
          if (data['ageLimits'] is Map) {
            final limitsMap = data['ageLimits'] as Map;
            limitsMap.forEach((ageKey, val) {
              if (val is Map) {
                final Map<String, num> inner = {};
                val.forEach((nutKey, nutVal) {
                  inner[nutKey.toString()] = (nutVal is num)
                      ? nutVal
                      : (num.tryParse(nutVal.toString()) ?? 0);
                });
                ageLimits[ageKey.toString()] = inner;
              }
            });
          }
          isBootstrapped = true;
          return data;
        }
      }
    } catch (e) {
      print('Bootstrap fetch error: $e');
    }
    return null;
  }

  // ─────────────────────────────────────────────────────────────
  // NUTRITION DATA ACCURACY & SANITIZATION (MAGGI / SODIUM FIX)
  // ─────────────────────────────────────────────────────────────
  /// Sanitizes nutrition values and avoids false sodium/salt multiplication
  static Map<String, dynamic> sanitizeNutrition(Map<String, dynamic> n, {String productName = ''}) {
    num sugar = (n['sugar'] is num) ? n['sugar'] : (num.tryParse('${n['sugar']}') ?? 0);
    num salt = (n['salt'] is num) ? n['salt'] : (num.tryParse('${n['salt']}') ?? 0);
    num fat = (n['fat'] is num) ? n['fat'] : (num.tryParse('${n['fat']}') ?? 0);
    num calories = (n['calories'] is num) ? n['calories'] : (num.tryParse('${n['calories']}') ?? 0);
    num protein = (n['protein'] is num) ? n['protein'] : (num.tryParse('${n['protein']}') ?? 0);
    num fiber = (n['fiber'] is num) ? n['fiber'] : (num.tryParse('${n['fiber']}') ?? 0);

    if (sugar > 100) sugar = 100;
    if (fat > 100) fat = 100;
    if (protein > 100) protein = 100;
    if (fiber > 100) fiber = 100;
    if (calories > 900) calories = 900;

    // Realistic salt caps:
    // Pure salt / seasonings can have up to 38,000 mg salt/100g.
    // General packaged foods (like Maggi noodles, chips, biscuits) have between 100 - 3,500 mg salt/100g.
    final isPureSaltProduct = productName.toLowerCase().contains('salt') ||
        productName.toLowerCase().contains('namak') ||
        productName.toLowerCase().contains('seasoning') ||
        productName.toLowerCase().contains('brine');

    if (isPureSaltProduct) {
      if (salt > 38800) salt = 38800;
    } else {
      if (salt > 3500) salt = 3500;
    }

    sugar = sugar < 0 ? 0 : sugar;
    salt = salt < 0 ? 0 : salt;
    fat = fat < 0 ? 0 : fat;
    calories = calories < 0 ? 0 : calories;
    protein = protein < 0 ? 0 : protein;
    fiber = fiber < 0 ? 0 : fiber;

    final result = Map<String, dynamic>.from(n);
    result['sugar'] = double.parse(sugar.toStringAsFixed(1));
    result['salt'] = double.parse(salt.toStringAsFixed(1));
    result['fat'] = double.parse(fat.toStringAsFixed(1));
    result['calories'] = double.parse(calories.toStringAsFixed(1));
    if (protein > 0) result['protein'] = double.parse(protein.toStringAsFixed(1));
    if (fiber > 0) result['fiber'] = double.parse(fiber.toStringAsFixed(1));
    return result;
  }

  /// Save newly discovered product to MySQL database via api.php
  static Future<void> saveProductToSql({
    required String name,
    required Map<String, dynamic> nutrition,
    String source = 'external',
    String barcode = '',
    String company = '',
  }) async {
    try {
      await _post(
        Uri.parse('$baseUrl/api.php?action=save_food'),
        headers: {'Content-Type': 'application/json'},
        body: jsonEncode({
          'name': name,
          'nutrition': nutrition,
          'source': source,
          'barcode': barcode,
          'company': company,
          'companyName': company,
        }),
        timeout: const Duration(seconds: 8),
      );
      // Update local memory cache
      cachedFoods[name] = nutrition;
      if (company.isNotEmpty) {
        cachedCompanies.putIfAbsent(company, () => []).add(name);
      }
    } catch (e) {
      print('Error saving product to SQL: $e');
    }
  }

  // ─────────────────────────────────────────────────────────────
  // FOOD SEARCH (SEARCH WITHOUT DOB / NO DEFAULT AGE)
  // ─────────────────────────────────────────────────────────────
  /// Unified Food Search:
  /// 1. Local Memory Cache
  /// 2. Local Cached MySQL Database (instant 0ms)
  /// 3. Remote MySQL Database (api.php?action=search)
  /// 4. Multi-API Online Search (Open Food Facts, Datakick, USDA)
  static Future<Map<String, dynamic>?> searchFood(String query) async {
    final q = query.trim();
    if (q.isEmpty) return null;

    final cacheKey = q.toLowerCase();
    final cached = _foodSearchCache[cacheKey];
    if (cached != null && !cached.isExpired) {
      return cached.data;
    }

    if (!isBootstrapped) {
      await fetchBootstrapData();
    }

    // 1. Check local bootstrap cache
    final localKey = cachedFoods.keys.firstWhere(
      (k) => k.toLowerCase() == q.toLowerCase() || k.toLowerCase().contains(q.toLowerCase()),
      orElse: () => '',
    );
    if (localKey.isNotEmpty) {
      String company = '';
      for (final entry in cachedCompanies.entries) {
        if (entry.value.any((p) => p.toLowerCase() == localKey.toLowerCase())) {
          company = entry.key;
          break;
        }
      }
      final res = {
        'found': true,
        'productName': localKey,
        'companyName': company,
        'source': 'MySQL',
        'nutrition': sanitizeNutrition(Map<String, dynamic>.from(cachedFoods[localKey]), productName: localKey),
      };
      _foodSearchCache[cacheKey] = CacheEntry(res, ttl: const Duration(minutes: 5));
      if (company.isNotEmpty) {
        _companyCache[localKey.toLowerCase()] = CacheEntry(company);
      }
      return res;
    }

    // 2. Query MySQL API directly
    try {
      final response = await _get(
        Uri.parse('$baseUrl/api.php?action=search&q=${Uri.encodeComponent(q)}'),
        timeout: const Duration(seconds: 6),
      );
      if (response.statusCode == 200) {
        final data = jsonDecode(response.body);
        if (data is Map && data['found'] == true && data['nutrition'] != null) {
          final res = {
            'found': true,
            'productName': data['productName'],
            'companyName': data['companyName'] ?? '',
            'nutrition': sanitizeNutrition(Map<String, dynamic>.from(data['nutrition']), productName: data['productName'] ?? ''),
            'source': data['source'] ?? 'MySQL',
          };
          _foodSearchCache[cacheKey] = CacheEntry(res, ttl: const Duration(minutes: 5));
          if ((data['companyName'] ?? '').toString().isNotEmpty) {
            _companyCache[res['productName'].toString().toLowerCase()] = CacheEntry(data['companyName'].toString());
          }
          return res;
        }
      }
    } catch (_) {}

    // 3. Fallback to Multi-API Online Search
    final onlineResult = await _searchOnlineAPIs(q);
    if (onlineResult != null) {
      _foodSearchCache[cacheKey] = CacheEntry(onlineResult, ttl: const Duration(minutes: 5));
      // Save product asynchronously into MySQL backend
      saveProductToSql(
        name: onlineResult['productName'] ?? q,
        nutrition: onlineResult['nutrition'] ?? {},
        company: onlineResult['companyName'] ?? '',
        source: onlineResult['source'] ?? 'api',
      );
      return onlineResult;
    }

    return null;
  }

  // ─────────────────────────────────────────────────────────────
  // 6-API MASTER BARCODE RESOLVER
  // ─────────────────────────────────────────────────────────────
  /// Complete Barcode Lookup pipeline synchronized with Web
  static Future<Map<String, dynamic>?> resolveBarcode(String barcode) async {
    final cleanCode = barcode.trim().replaceAll(RegExp(r'\D'), '');
    if (cleanCode.isEmpty) return null;

    // 1. MySQL Database on InfinityFree (fastest & authoritative)
    try {
      final response = await _get(
        Uri.parse('$baseUrl/api.php?action=barcode&code=$cleanCode'),
        timeout: const Duration(seconds: 5),
      );
      if (response.statusCode == 200) {
        final data = jsonDecode(response.body);
        if (data is Map && data['found'] == true) {
          return {
            'productName': data['productName'],
            'companyName': data['companyName'] ?? '',
            'nutrition': sanitizeNutrition(Map<String, dynamic>.from(data['nutrition'] ?? {}), productName: data['productName'] ?? ''),
            'source': data['source'] ?? 'MySQL',
            'barcode': cleanCode,
          };
        }
      }
    } catch (_) {}

    // 2. Open Food Facts - India (high priority for Indian goods)
    var result = await _lookupOFFIndia(cleanCode);

    // 3. Open Food Facts - World
    result ??= await _lookupOFFWorld(cleanCode);

    // 4. Open Products Facts
    result ??= await _lookupOpenProductsFacts(cleanCode);

    // 5. Datakick API
    result ??= await _lookupDatakick(cleanCode);

    // 6. UPC Item DB
    result ??= await _lookupUPCItemDB(cleanCode);

    // If resolved from external API, automatically cache into MySQL
    if (result != null && (result['productName'] ?? '').toString().isNotEmpty) {
      result['barcode'] = cleanCode;
      saveProductToSql(
        name: result['productName'],
        nutrition: result['nutrition'] ?? {},
        company: result['companyName'] ?? '',
        barcode: cleanCode,
        source: result['source'] ?? 'barcode',
      );
    }

    return result;
  }

  static Future<Map<String, dynamic>?> _lookupOFFIndia(String barcode) async {
    try {
      final url = 'https://in.openfoodfacts.org/api/v2/product/$barcode.json?fields=product_name,product_name_en,brands,nutriments';
      final res = await http.get(Uri.parse(url)).timeout(const Duration(seconds: 6));
      if (res.statusCode == 200) {
        final data = jsonDecode(res.body);
        if (data['status'] == 1 && data['product'] != null) {
          final p = data['product'];
          final name = (p['product_name'] ?? p['product_name_en'] ?? '').toString().trim();
          if (name.isNotEmpty) {
            return {
              'productName': name,
              'companyName': (p['brands'] ?? '').toString().split(',').first.trim(),
              'nutrition': _extractOFFNutrition(p['nutriments'] ?? {}, productName: name),
              'source': 'Open Food Facts (India)',
            };
          }
        }
      }
    } catch (_) {}
    return null;
  }

  static Future<Map<String, dynamic>?> _lookupOFFWorld(String barcode) async {
    try {
      final url = 'https://world.openfoodfacts.org/api/v2/product/$barcode.json?fields=product_name,product_name_en,brands,nutriments';
      final res = await http.get(Uri.parse(url)).timeout(const Duration(seconds: 6));
      if (res.statusCode == 200) {
        final data = jsonDecode(res.body);
        if (data['status'] == 1 && data['product'] != null) {
          final p = data['product'];
          final name = (p['product_name'] ?? p['product_name_en'] ?? '').toString().trim();
          if (name.isNotEmpty) {
            return {
              'productName': name,
              'companyName': (p['brands'] ?? '').toString().split(',').first.trim(),
              'nutrition': _extractOFFNutrition(p['nutriments'] ?? {}, productName: name),
              'source': 'Open Food Facts',
            };
          }
        }
      }
    } catch (_) {}
    return null;
  }

  static Future<Map<String, dynamic>?> _lookupOpenProductsFacts(String barcode) async {
    try {
      final url = 'https://world.openproductsfacts.org/api/v2/product/$barcode.json?fields=product_name,brands,nutriments';
      final res = await http.get(Uri.parse(url)).timeout(const Duration(seconds: 6));
      if (res.statusCode == 200) {
        final data = jsonDecode(res.body);
        if (data['status'] == 1 && data['product'] != null) {
          final p = data['product'];
          final name = (p['product_name'] ?? '').toString().trim();
          if (name.isNotEmpty) {
            return {
              'productName': name,
              'companyName': (p['brands'] ?? '').toString().split(',').first.trim(),
              'nutrition': _extractOFFNutrition(p['nutriments'] ?? {}, productName: name),
              'source': 'Open Products Facts',
            };
          }
        }
      }
    } catch (_) {}
    return null;
  }

  static Future<Map<String, dynamic>?> _lookupDatakick(String barcode) async {
    try {
      final url = 'https://www.datakick.org/api/items/$barcode';
      final res = await http.get(Uri.parse(url)).timeout(const Duration(seconds: 6));
      if (res.statusCode == 200) {
        final data = jsonDecode(res.body);
        final name = (data['name'] ?? '').toString().trim();
        if (name.isNotEmpty) {
          num rawSodium = (data['sodium'] ?? 0);
          num sodiumMg = (rawSodium > 100) ? rawSodium : (rawSodium * 1000);
          return {
            'productName': name,
            'companyName': (data['brand_name'] ?? data['brand'] ?? '').toString().trim(),
            'nutrition': sanitizeNutrition({
              'calories': (data['serving_calories'] ?? 0),
              'sugar': (data['sugars'] ?? 0),
              'fat': (data['fat'] ?? 0),
              'salt': (sodiumMg * 2.54).round(),
              'protein': (data['protein'] ?? 0),
              'fiber': (data['dietary_fiber'] ?? 0),
            }, productName: name),
            'source': 'Datakick',
          };
        }
      }
    } catch (_) {}
    return null;
  }

  static Future<Map<String, dynamic>?> _lookupUPCItemDB(String barcode) async {
    try {
      final url = 'https://api.upcitemdb.com/prod/trial/lookup?upc=$barcode';
      final res = await http.get(Uri.parse(url)).timeout(const Duration(seconds: 6));
      if (res.statusCode == 200) {
        final data = jsonDecode(res.body);
        final items = data['items'] as List?;
        if (items != null && items.isNotEmpty) {
          final item = items[0];
          final name = (item['title'] ?? item['description'] ?? '').toString().trim();
          if (name.isNotEmpty) {
            return {
              'productName': name,
              'companyName': (item['brand'] ?? item['manufacturer'] ?? '').toString().trim(),
              'nutrition': {'calories': 0, 'sugar': 0, 'salt': 0, 'fat': 0},
              'source': 'UPC Item DB',
            };
          }
        }
      }
    } catch (_) {}
    return null;
  }

  /// Scientifically accurate Open Food Facts nutrition extractor
  static Map<String, dynamic> _extractOFFNutrition(Map nutriments, {String productName = ''}) {
    num cal = (nutriments['energy-kcal_100g'] ?? nutriments['energy-kcal'] ?? 0);
    if (cal == 0) {
      num kj = (nutriments['energy_100g'] ?? nutriments['energy'] ?? 0);
      if (kj > 0) cal = (kj / 4.184).round();
    }

    num sugar = (nutriments['sugars_100g'] ?? nutriments['sugars'] ?? 0);
    num fat = (nutriments['fat_100g'] ?? nutriments['fat'] ?? 0);

    // Scientifically separate sodium from salt
    num saltMg = 0;
    if (nutriments['salt_100g'] != null || nutriments['salt'] != null) {
      num rawSalt = (nutriments['salt_100g'] ?? nutriments['salt'] ?? 0);
      // If > 100, already logged in mg; else grams to mg
      saltMg = (rawSalt > 100) ? rawSalt : (rawSalt * 1000);
    } else if (nutriments['sodium_100g'] != null || nutriments['sodium'] != null) {
      num rawSodium = (nutriments['sodium_100g'] ?? nutriments['sodium'] ?? 0);
      num sodiumMg = (rawSodium > 100) ? rawSodium : (rawSodium * 1000);
      // Exact scientific conversion: Salt (NaCl) = Sodium * 2.54
      saltMg = sodiumMg * 2.54;
    }

    num protein = (nutriments['proteins_100g'] ?? nutriments['proteins'] ?? 0);
    num fiber = (nutriments['fiber_100g'] ?? nutriments['fiber'] ?? 0);

    return sanitizeNutrition({
      'calories': cal,
      'sugar': sugar,
      'fat': fat,
      'salt': saltMg.round(),
      'protein': protein,
      'fiber': fiber,
    }, productName: productName);
  }

  // ─────────────────────────────────────────────────────────────
  // MULTI-API ONLINE SEARCH HELPER
  // ─────────────────────────────────────────────────────────────
  static Future<Map<String, dynamic>?> _searchOnlineAPIs(String query) async {
    try {
      final offUrl = 'https://world.openfoodfacts.org/cgi/search.pl?search_terms=${Uri.encodeComponent(query)}&search_simple=1&json=1&page_size=3&cc=in';
      final offRes = await http.get(Uri.parse(offUrl)).timeout(const Duration(seconds: 7));
      if (offRes.statusCode == 200) {
        final data = jsonDecode(offRes.body);
        final products = data['products'] as List?;
        if (products != null && products.isNotEmpty) {
          for (final p in products) {
            final name = (p['product_name'] ?? p['product_name_en'] ?? '').toString().trim();
            if (name.isNotEmpty) {
              final nut = _extractOFFNutrition(p['nutriments'] ?? {}, productName: name);
              return {
                'productName': name,
                'companyName': (p['brands'] ?? '').toString().split(',').first.trim(),
                'nutrition': nut,
                'source': 'Open Food Facts',
              };
            }
          }
        }
      }
    } catch (_) {}

    try {
      final usdaUrl = 'https://api.nal.usda.gov/fdc/v1/foods/search?query=${Uri.encodeComponent(query)}&api_key=DEMO_KEY&pageSize=3';
      final usdaRes = await http.get(Uri.parse(usdaUrl)).timeout(const Duration(seconds: 7));
      if (usdaRes.statusCode == 200) {
        final data = jsonDecode(usdaRes.body);
        final foods = data['foods'] as List?;
        if (foods != null && foods.isNotEmpty) {
          final f = foods[0];
          final nutrients = f['foodNutrients'] as List? ?? [];
          num getNutrient(List<String> names) {
            for (final name in names) {
              for (final n in nutrients) {
                final nName = (n['nutrientName'] ?? '').toString().toLowerCase();
                if (nName.contains(name.toLowerCase())) {
                  return (n['value'] is num) ? n['value'] : (num.tryParse('${n['value']}') ?? 0);
                }
              }
            }
            return 0;
          }

          final name = (f['description'] ?? query).toString().trim();
          num sodiumMg = getNutrient(['sodium']);

          return {
            'productName': name,
            'companyName': (f['brandOwner'] ?? f['brandName'] ?? '').toString().trim(),
            'nutrition': sanitizeNutrition({
              'calories': getNutrient(['energy', 'calories']),
              'sugar': getNutrient(['sugars, total', 'sugars']),
              'salt': (sodiumMg * 2.54).round(),
              'fat': getNutrient(['total lipid', 'fat']),
              'protein': getNutrient(['protein']),
              'fiber': getNutrient(['fiber, total dietary']),
            }, productName: name),
            'source': 'USDA FoodData Central',
          };
        }
      }
    } catch (_) {}

    return null;
  }

  // ─────────────────────────────────────────────────────────────
  // AUTOCOMPLETE SUGGESTIONS & COMPANY RESOLUTION
  // ─────────────────────────────────────────────────────────────
  /// Autocomplete Suggestions: In-memory cache + Local cached foods + MySQL suggest + External APIs
  static Future<List<String>> fetchFoodSuggestions(String query, {int? sequenceId}) async {
    final q = query.trim().toLowerCase();
    if (q.length < 2) return [];

    final cached = _suggestionCache[q];
    if (cached != null && !cached.isExpired) {
      return cached.data;
    }

    final Set<String> results = {};

    // 1. Local cached items from MySQL bootstrap (instant 0ms)
    for (final name in cachedFoods.keys) {
      if (name.toLowerCase().contains(q)) {
        results.add(name);
        if (results.length >= 8) break;
      }
    }

    // 2. MySQL suggest API (authoritative backend)
    try {
      final response = await _get(
        Uri.parse('$baseUrl/api.php?action=suggest&q=${Uri.encodeComponent(q)}'),
        timeout: const Duration(seconds: 4),
      );
      if (response.statusCode == 200) {
        final List<dynamic> data = jsonDecode(response.body);
        for (final item in data) {
          results.add(item.toString());
        }
      }
    } catch (_) {}

    // Discard result if newer query was dispatched
    if (sequenceId != null && sequenceId < _searchRequestId) {
      return results.take(10).toList();
    }

    // 3. If very few, supplement from Open Food Facts
    if (results.length < 4) {
      try {
        final url = 'https://world.openfoodfacts.org/cgi/search.pl?search_terms=${Uri.encodeComponent(q)}&search_simple=1&json=1&page_size=6&cc=in';
        final res = await http.get(Uri.parse(url)).timeout(const Duration(seconds: 4));
        if (res.statusCode == 200) {
          final data = jsonDecode(res.body);
          final products = data['products'] as List?;
          if (products != null) {
            for (final p in products) {
              final name = (p['product_name'] ?? p['product_name_en'] ?? '').toString().trim();
              if (name.isNotEmpty) results.add(name);
            }
          }
        }
      } catch (_) {}
    }

    final list = results.take(10).toList();
    _suggestionCache[q] = CacheEntry(list, ttl: const Duration(minutes: 5));
    return list;
  }

  /// Automatically resolves company for a product (synchronized with Web backend)
  static Future<String> getCompanyForProduct(String productName) async {
    final p = productName.trim();
    if (p.isEmpty) return '';

    final cacheKey = p.toLowerCase();
    final cached = _companyCache[cacheKey];
    if (cached != null && !cached.isExpired) {
      return cached.data;
    }

    if (!isBootstrapped) {
      await fetchBootstrapData();
    }

    // 1. Check local MySQL bootstrap cache
    for (final entry in cachedCompanies.entries) {
      if (entry.value.any((item) => item.toLowerCase() == cacheKey || item.toLowerCase().contains(cacheKey))) {
        _companyCache[cacheKey] = CacheEntry(entry.key);
        return entry.key;
      }
    }

    // 2. Query MySQL API directly
    try {
      final res = await _get(
        Uri.parse('$baseUrl/api.php?action=search&q=${Uri.encodeComponent(p)}'),
        timeout: const Duration(seconds: 4),
      );
      if (res.statusCode == 200) {
        final data = jsonDecode(res.body);
        if (data is Map && (data['companyName'] ?? '').toString().trim().isNotEmpty) {
          final company = data['companyName'].toString().trim();
          _companyCache[cacheKey] = CacheEntry(company);
          return company;
        }
      }
    } catch (_) {}

    // 3. Fallback to online multi-APIs
    try {
      final online = await _searchOnlineAPIs(p);
      if (online != null && (online['companyName'] ?? '').toString().trim().isNotEmpty) {
        final company = online['companyName'].toString().trim();
        _companyCache[cacheKey] = CacheEntry(company);
        return company;
      }
    } catch (_) {}

    return '';
  }

  /// Company Suggestions
  static Future<List<String>> fetchCompanySuggestions(String query) async {
    final q = query.trim().toLowerCase();
    final Set<String> results = {};

    for (final c in cachedCompanies.keys) {
      if (c.toLowerCase().contains(q)) {
        results.add(c);
      }
    }

    try {
      final response = await _get(
        Uri.parse('$baseUrl/api.php?action=companies&q=${Uri.encodeComponent(q)}'),
        timeout: const Duration(seconds: 4),
      );
      if (response.statusCode == 200) {
        final List<dynamic> data = jsonDecode(response.body);
        for (final item in data) {
          if (item is Map && item['name'] != null) {
            results.add(item['name'].toString());
          }
        }
      }
    } catch (_) {}

    return results.take(8).toList();
  }

  /// Healthier Alternatives from MySQL or OpenRouter fallback
  static Future<List<dynamic>> fetchAlternatives(String productName, num sugar, num salt) async {
    try {
      final response = await _get(
        Uri.parse('$baseUrl/api.php?action=alternatives&productName=${Uri.encodeComponent(productName)}&sugar=$sugar&salt=$salt'),
        timeout: const Duration(seconds: 8),
      );
      if (response.statusCode == 200) {
        final data = jsonDecode(response.body);
        if (data is List) {
          return data;
        }
      }
    } catch (e) {
      print('Error fetching alternatives: $e');
    }
    return [];
  }

  /// Report incorrect food data to admin
  static Future<bool> reportFood(String productName) async {
    try {
      final response = await _post(
        Uri.parse('$baseUrl/api.php?action=report_food'),
        headers: {'Content-Type': 'application/json'},
        body: jsonEncode({'name': productName}),
        timeout: const Duration(seconds: 6),
      );
      return response.statusCode == 200;
    } catch (_) {
      return false;
    }
  }

  // ─────────────────────────────────────────────────────────────
  // AI RECIPE MAKER (via generate-recipe.php)
  // ─────────────────────────────────────────────────────────────
  /// Generate AI Healthy Recipe (via generate-recipe.php)
  static Future<Map<String, dynamic>?> generateRecipe(String productName) async {
    try {
      final response = await _post(
        Uri.parse('$baseUrl/generate-recipe.php'),
        headers: {'Content-Type': 'application/json'},
        body: jsonEncode({'productName': productName.trim()}),
        timeout: const Duration(seconds: 25),
      );

      if (response.statusCode == 200) {
        final data = jsonDecode(response.body);
        if (data is Map<String, dynamic> && data['recipeName'] != null) {
          return data;
        }
      }
    } catch (e) {
      print('Error generating recipe: $e');
    }
    return null;
  }

  // ─────────────────────────────────────────────────────────────
  // FEEDBACK SYSTEM (SYNCHRONIZED WITH WEB, MYSQL & ADMIN PANEL)
  // ─────────────────────────────────────────────────────────────
  /// Submits feedback to send-feedback.php -> MySQL feedbacks table -> Brevo Email notification
  static Future<Map<String, dynamic>> sendFeedback(String name, String email, String message) async {
    try {
      final response = await _post(
        Uri.parse('$baseUrl/send-feedback.php'),
        headers: {'Content-Type': 'application/json'},
        body: jsonEncode({
          'name': name.trim(),
          'email': email.trim(),
          'message': message.trim(),
        }),
        timeout: const Duration(seconds: 15),
      );

      if (response.statusCode == 200) {
        final data = jsonDecode(response.body);
        if (data is Map && (data['success'] == true || data['ok'] == true)) {
          return {
            'success': true,
            'message': 'Feedback submitted successfully. Thank you for helping us improve!',
          };
        }
      } else if (response.statusCode == 429) {
        return {
          'success': false,
          'message': 'Please wait a minute before sending another feedback.',
        };
      } else {
        try {
          final data = jsonDecode(response.body);
          if (data is Map && data['error'] != null) {
            return {'success': false, 'message': data['error'].toString()};
          }
        } catch (_) {}
      }
    } catch (e) {
      print('Error sending feedback: $e');
    }
    return {
      'success': false,
      'message': 'Unable to submit feedback. Please try again.',
    };
  }
}
