# 🥗 Vitahar Mobile App (Flutter)

A modern, cross-platform Android mobile application for **Vitahar** — an AI-powered food safety, nutritional intelligence, and healthy recipe platform based on **FSSAI** & **WHO** nutritional guidelines.

---

## 🚀 Key Features

- **Age-Specific Safety Verdicts**: Evaluates packaged food products against 6 age cohorts (infant to senior) with clear verdicts (*Safe*, *Consume with Caution*, *Not Recommended*).
- **Multi-Database Barcode Scanner**: Powered by `mobile_scanner` with a cascade lookup across local MySQL, Open Food Facts, and USDA FoodData Central databases.
- **Side-by-Side Product Comparison**: Compare two foods to identify healthier nutritional alternatives with lower sugar, salt, and trans fat.
- **AI-Powered Healthy Recipes**: Direct integration with Google Gemini AI microservices to suggest clean dietary alternatives and recipes.
- **Interactive Nutritional Breakdown**: Clean visualization of macronutrients, additives, and harmful chemicals.

---

## 🛠️ Tech Stack & Dependencies

- **Framework**: [Flutter 3.x](https://flutter.dev) (Dart SDK `>=3.0.0 <4.0.0`)
- **UI Design**: Material 3 Design System
- **Barcode & QR Scanning**: [`mobile_scanner`](https://pub.dev/packages/mobile_scanner)
- **HTTP & Networking**: [`http`](https://pub.dev/packages/http)
- **Data Encryption**: [`encrypt`](https://pub.dev/packages/encrypt)

---

## 📱 Getting Started

### Prerequisites

- Flutter SDK (v3.0.0 or higher)
- Android Studio / VS Code with Flutter extension
- Android Device or Emulator (API level 21+)

### Installation & Run

1. **Clone the repository**:
   ```bash
   git clone https://github.com/ankitkumarojha-29/Vitahar.git
   cd Vitahar/mobile_app
   ```

2. **Install Flutter packages**:
   ```bash
   flutter pub get
   ```

3. **Run on connected device/emulator**:
   ```bash
   flutter run
   ```

4. **Build release APK**:
   ```bash
   flutter build apk --release
   ```
   The APK will be generated at `build/app/outputs/flutter-apk/app-release.apk`.
