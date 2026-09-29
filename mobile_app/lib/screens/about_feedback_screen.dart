import 'package:flutter/material.dart';
import '../services/api_service.dart';

class AboutFeedbackScreen extends StatefulWidget {
  const AboutFeedbackScreen({super.key});

  @override
  State<AboutFeedbackScreen> createState() => _AboutFeedbackScreenState();
}

class _AboutFeedbackScreenState extends State<AboutFeedbackScreen> {
  final _nameController = TextEditingController();
  final _emailController = TextEditingController();
  final _messageController = TextEditingController();

  bool _isSubmitting = false;

  @override
  void dispose() {
    _nameController.dispose();
    _emailController.dispose();
    _messageController.dispose();
    super.dispose();
  }

  void _submitFeedback() async {
    final name = _nameController.text.trim();
    final email = _emailController.text.trim();
    final message = _messageController.text.trim();

    if (name.isEmpty || email.isEmpty || message.isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Please fill in your name, email, and message.'),
          backgroundColor: Color(0xFFE65100),
        ),
      );
      return;
    }

    if (!email.contains('@') || !email.contains('.')) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Please enter a valid email address.'),
          backgroundColor: Color(0xFFE65100),
        ),
      );
      return;
    }

    setState(() => _isSubmitting = true);

    final res = await ApiService.sendFeedback(name, email, message);
    final bool success = res['success'] == true;
    final String responseMessage = res['message'] ?? 'Unable to submit feedback. Please try again.';

    if (mounted) {
      setState(() => _isSubmitting = false);
      if (success) {
        _nameController.clear();
        _emailController.clear();
        _messageController.clear();

        showDialog(
          context: context,
          builder: (ctx) => AlertDialog(
            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
            title: const Row(
              children: [
                Icon(Icons.check_circle, color: Color(0xFF2E7D32)),
                SizedBox(width: 8),
                Text('Feedback Submitted'),
              ],
            ),
            content: const Text(
              'Feedback submitted successfully. Thank you for helping us improve!',
              style: TextStyle(fontSize: 15, height: 1.4),
            ),
            actions: [
              ElevatedButton(
                onPressed: () => Navigator.pop(ctx),
                style: ElevatedButton.styleFrom(
                  backgroundColor: const Color(0xFF2E7D32),
                  foregroundColor: Colors.white,
                ),
                child: const Text('OK'),
              ),
            ],
          ),
        );
      } else {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(responseMessage),
            backgroundColor: const Color(0xFFB71C1C),
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
        title: const Text('About & Feedback', style: TextStyle(fontWeight: FontWeight.bold, color: Colors.black87)),
        backgroundColor: Colors.white,
        elevation: 0.5,
      ),
      body: SingleChildScrollView(
        padding: const EdgeInsets.all(16.0),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            // About Vitahar Card
            Container(
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
              padding: const EdgeInsets.all(20.0),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  const Row(
                    children: [
                      Text('🌿', style: TextStyle(fontSize: 24)),
                      SizedBox(width: 8),
                      Text(
                        'About Vitahar',
                        style: TextStyle(
                          fontSize: 20,
                          fontWeight: FontWeight.bold,
                          color: Color(0xFF2E7D32),
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 10),
                  const Text(
                    'Eat Smart. Live Better. Know Your Food with Vitahar.',
                    style: TextStyle(fontWeight: FontWeight.bold, fontSize: 14, color: Colors.black87),
                  ),
                  const SizedBox(height: 8),
                  const Text(
                    'Vitahar is a next-generation food safety and nutrition intelligence platform designed for today\'s health-conscious generation. In a world full of packaged foods and hidden ingredients, Vitahar empowers users to instantly understand what they are consuming.\n\nBased on age-specific health standards, Vitahar provides a clear verdict — Safe, Consume with Caution, or Not Recommended — making complex nutrition simple and easy to understand.',
                    style: TextStyle(fontSize: 13, color: Colors.black87, height: 1.4),
                  ),
                  const SizedBox(height: 16),
                  const Divider(height: 1),
                  const SizedBox(height: 14),

                  // Key Features Checklist
                  const Text(
                    'Key Features',
                    style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold, color: Color(0xFF2E7D32)),
                  ),
                  const SizedBox(height: 8),
                  _buildFeatureCheck('Smart age-based food safety analysis (6 tiers)'),
                  _buildFeatureCheck('Instant nutrition breakdown (calories, sugar, salt, fat)'),
                  _buildFeatureCheck('Advanced 6-database Barcode scanner (MySQL, OFF, USDA, Datakick)'),
                  _buildFeatureCheck('Side-by-side Food Comparison Tool to pick the healthier option'),
                  _buildFeatureCheck('Smart "Healthier Alternatives" suggestion engine'),
                  _buildFeatureCheck('AI Healthy Recipe Maker for packaged foods'),
                  _buildFeatureCheck('Based on official FSSAI & WHO nutrition standards'),
                ],
              ),
            ),
            const SizedBox(height: 16),

            // Developer and Powered By Card
            Container(
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
              padding: const EdgeInsets.all(20.0),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  const Row(
                    children: [
                      Icon(Icons.person_outline, color: Color(0xFF2E7D32), size: 24),
                      SizedBox(width: 8),
                      Text(
                        'About the Developer',
                        style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold, color: Color(0xFF2E7D32)),
                      ),
                    ],
                  ),
                  const SizedBox(height: 10),
                  const Text(
                    'Developed by Ankit Kumar Ojha, Computer Science & Engineering student at VIT Bhopal University. Created to help everyday consumers make informed, transparent food choices through nutrition and safety insights.',
                    style: TextStyle(fontSize: 13, color: Colors.black87, height: 1.4),
                  ),
                  const SizedBox(height: 16),
                  const Divider(height: 1),
                  const SizedBox(height: 14),
                  const Text(
                    'Powered By',
                    style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold, color: Color(0xFF2E7D32)),
                  ),
                  const SizedBox(height: 8),
                  _buildPoweredByItem('FSSAI Guidelines', 'Indian Food Safety Standards'),
                  _buildPoweredByItem('WHO Standards', 'Global dietary reference guidelines'),
                  _buildPoweredByItem('Open Food Facts', 'Crowdsourced food database'),
                  _buildPoweredByItem('USDA FoodData Central', 'Comprehensive nutrition reference'),
                  _buildPoweredByItem('Google Gemini / AI', 'Nutritional intelligence & healthy recipes'),
                ],
              ),
            ),
            const SizedBox(height: 16),

            // Feedback Form Card
            Container(
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
              padding: const EdgeInsets.all(20.0),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  const Row(
                    children: [
                      Icon(Icons.chat_bubble_outline, color: Color(0xFF2E7D32), size: 24),
                      SizedBox(width: 8),
                      Text(
                        'Send Feedback',
                        style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold, color: Color(0xFF2E7D32)),
                      ),
                    ],
                  ),
                  const SizedBox(height: 6),
                  const Text(
                    'Have an idea, bug report, or suggestion? Your feedback directly helps improve Vitahar.',
                    style: TextStyle(fontSize: 13, color: Colors.grey),
                  ),
                  const SizedBox(height: 16),
                  TextField(
                    controller: _nameController,
                    decoration: InputDecoration(
                      labelText: 'Your Name',
                      prefixIcon: const Icon(Icons.badge_outlined, color: Color(0xFF2E7D32)),
                      border: OutlineInputBorder(borderRadius: BorderRadius.circular(12)),
                      filled: true,
                      fillColor: const Color(0xFFFBFBFB),
                    ),
                  ),
                  const SizedBox(height: 12),
                  TextField(
                    controller: _emailController,
                    keyboardType: TextInputType.emailAddress,
                    decoration: InputDecoration(
                      labelText: 'Your Email',
                      prefixIcon: const Icon(Icons.email_outlined, color: Color(0xFF2E7D32)),
                      border: OutlineInputBorder(borderRadius: BorderRadius.circular(12)),
                      filled: true,
                      fillColor: const Color(0xFFFBFBFB),
                    ),
                  ),
                  const SizedBox(height: 12),
                  TextField(
                    controller: _messageController,
                    maxLines: 4,
                    decoration: InputDecoration(
                      labelText: 'Message',
                      alignLabelWithHint: true,
                      prefixIcon: const Padding(
                        padding: EdgeInsets.only(bottom: 50.0),
                        child: Icon(Icons.message_outlined, color: Color(0xFF2E7D32)),
                      ),
                      border: OutlineInputBorder(borderRadius: BorderRadius.circular(12)),
                      filled: true,
                      fillColor: const Color(0xFFFBFBFB),
                    ),
                  ),
                  const SizedBox(height: 18),
                  ElevatedButton.icon(
                    onPressed: _isSubmitting ? null : _submitFeedback,
                    icon: _isSubmitting
                        ? const SizedBox(
                            width: 18,
                            height: 18,
                            child: CircularProgressIndicator(color: Colors.white, strokeWidth: 2),
                          )
                        : const Icon(Icons.send_rounded),
                    label: Text(_isSubmitting ? 'Sending...' : 'Send Feedback'),
                    style: ElevatedButton.styleFrom(
                      backgroundColor: const Color(0xFF2E7D32),
                      foregroundColor: Colors.white,
                      padding: const EdgeInsets.symmetric(vertical: 14),
                      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                    ),
                  ),
                ],
              ),
            ),
            const SizedBox(height: 24),
          ],
        ),
      ),
    );
  }

  Widget _buildFeatureCheck(String text) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 6.0),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Icon(Icons.check_circle, size: 16, color: Color(0xFF2E7D32)),
          const SizedBox(width: 8),
          Expanded(child: Text(text, style: const TextStyle(fontSize: 13, color: Colors.black87))),
        ],
      ),
    );
  }

  Widget _buildPoweredByItem(String title, String subtitle) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 6.0),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Text('• ', style: TextStyle(color: Color(0xFF2E7D32), fontWeight: FontWeight.bold, fontSize: 16)),
          Expanded(
            child: RichText(
              text: TextSpan(
                style: const TextStyle(color: Colors.black87, fontSize: 13),
                children: [
                  TextSpan(text: '$title: ', style: const TextStyle(fontWeight: FontWeight.bold)),
                  TextSpan(text: subtitle, style: const TextStyle(color: Colors.black54)),
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }
}
