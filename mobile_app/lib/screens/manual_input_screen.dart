import 'package:flutter/material.dart';

class ManualInputScreen extends StatelessWidget {
  const ManualInputScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Manual Food Input'), backgroundColor: Colors.orange),
      body: Padding(
        padding: const EdgeInsets.all(16.0),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            const Text('Enter Food Details Manually:', style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold)),
            const SizedBox(height: 10),
            const TextField(decoration: InputDecoration(labelText: 'Food Name', border: OutlineInputBorder())),
            const SizedBox(height: 10),
            const TextField(decoration: InputDecoration(labelText: 'Calories (kcal)', border: OutlineInputBorder()), keyboardType: TextInputType.number),
            const SizedBox(height: 10),
            const TextField(decoration: InputDecoration(labelText: 'Sugar (g)', border: OutlineInputBorder()), keyboardType: TextInputType.number),
            const SizedBox(height: 10),
            const TextField(decoration: InputDecoration(labelText: 'Salt (mg)', border: OutlineInputBorder()), keyboardType: TextInputType.number),
            const SizedBox(height: 20),
            ElevatedButton(
              onPressed: () {
                ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('Food added to local database!')));
              },
              style: ElevatedButton.styleFrom(backgroundColor: Colors.orange, foregroundColor: Colors.white),
              child: const Text('Save Food'),
            )
          ],
        ),
      ),
    );
  }
}
