import 'package:flutter/material.dart';
import 'dashboard_screen.dart';
import 'comparison_screen.dart';
import 'recipe_screen.dart';
import 'about_feedback_screen.dart';
import 'food_detail_screen.dart';
import 'scanner_screen.dart';

class MainScreen extends StatefulWidget {
  const MainScreen({super.key});

  @override
  State<MainScreen> createState() => _MainScreenState();
}

class _MainScreenState extends State<MainScreen> {
  int _currentIndex = 0;

  final List<Widget> _screens = const [
    DashboardScreen(),
    ComparisonScreen(),
    RecipeScreen(),
    AboutFeedbackScreen(),
  ];

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: IndexedStack(
        index: _currentIndex,
        children: _screens,
      ),
      floatingActionButton: FloatingActionButton(
        onPressed: () async {
          final result = await Navigator.push<Map<String, dynamic>>(
            context,
            MaterialPageRoute(builder: (context) => const ScannerScreen()),
          );
          if (result != null && mounted) {
            final name = result['productName']?.toString() ?? '';
            if (name.isNotEmpty) {
              Navigator.push(
                context,
                MaterialPageRoute(
                  builder: (context) => FoodDetailScreen(
                    productName: name,
                    companyName: result['companyName']?.toString(),
                    initialNutrition: result['nutrition'] as Map<String, dynamic>?,
                    initialSource: result['source']?.toString(),
                  ),
                ),
              );
            }
          }
        },
        backgroundColor: const Color(0xFFFF9800),
        elevation: 4,
        shape: const CircleBorder(),
        child: const Icon(Icons.qr_code_scanner, color: Colors.white, size: 28),
      ),
      floatingActionButtonLocation: FloatingActionButtonLocation.centerDocked,
      bottomNavigationBar: BottomAppBar(
        color: Colors.white,
        elevation: 8,
        shape: const CircularNotchedRectangle(),
        notchMargin: 8.0,
        padding: const EdgeInsets.symmetric(horizontal: 10),
        child: Row(
          mainAxisAlignment: MainAxisAlignment.spaceBetween,
          children: [
            // Left pair: Home & Compare
            Row(
              children: [
                _buildNavItem(
                  index: 0,
                  icon: Icons.shield_outlined,
                  activeIcon: Icons.shield,
                  label: 'Safety',
                ),
                const SizedBox(width: 8),
                _buildNavItem(
                  index: 1,
                  icon: Icons.compare_arrows_outlined,
                  activeIcon: Icons.compare_arrows,
                  label: 'Compare',
                ),
              ],
            ),
            const SizedBox(width: 48), // Space for centered FAB
            // Right pair: Recipe & About/Feedback
            Row(
              children: [
                _buildNavItem(
                  index: 2,
                  icon: Icons.restaurant_menu_outlined,
                  activeIcon: Icons.restaurant_menu,
                  label: 'AI Recipe',
                ),
                const SizedBox(width: 8),
                _buildNavItem(
                  index: 3,
                  icon: Icons.info_outline,
                  activeIcon: Icons.info,
                  label: 'About',
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildNavItem({
    required int index,
    required IconData icon,
    required IconData activeIcon,
    required String label,
  }) {
    final isSelected = _currentIndex == index;
    const activeColor = Color(0xFF2E7D32);
    const inactiveColor = Color(0xFF9E9E9E);

    return InkWell(
      onTap: () => setState(() => _currentIndex = index),
      borderRadius: BorderRadius.circular(12),
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 12.0, vertical: 4.0),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(
              isSelected ? activeIcon : icon,
              color: isSelected ? activeColor : inactiveColor,
              size: 23,
            ),
            const SizedBox(height: 2),
            Text(
              label,
              style: TextStyle(
                fontSize: 11,
                fontWeight: isSelected ? FontWeight.bold : FontWeight.w500,
                color: isSelected ? activeColor : inactiveColor,
              ),
            ),
          ],
        ),
      ),
    );
  }
}
