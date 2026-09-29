import 'package:flutter/material.dart';
import 'feedback_screen.dart';
import 'recipe_screen.dart';

class HomeScreen extends StatelessWidget {
  const HomeScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Vitahar - AI Recipes'),
        backgroundColor: Colors.orange,
      ),
      body: Center(
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            // Logo Image
            Image.asset('assets/vitahar-logo.png', height: 150),
            const SizedBox(height: 20),
            const Text(
              'Welcome to Vitahar App!',
              style: TextStyle(fontSize: 24, fontWeight: FontWeight.bold),
            ),
            const SizedBox(height: 40),
            ElevatedButton(
              onPressed: () {
                Navigator.push(context, MaterialPageRoute(builder: (context) => const RecipeScreen()));
              },
              child: const Text('Generate AI Recipe'),
            ),
            ElevatedButton(
              onPressed: () {
                Navigator.push(context, MaterialPageRoute(builder: (context) => const FeedbackScreen()));
              },
              child: const Text('Send Feedback'),
            ),
          ],
        ),
      ),
    );
  }
}
