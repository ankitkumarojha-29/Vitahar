# 🥗 Vitahar – Verified Food for Every Age

<p align="center">
  <img src="vitahar-logo.png" alt="Vitahar Logo" width="120" />
</p>

<p align="center">
  <b>Next-generation AI-Powered Food Safety, Nutrition Intelligence & Recipe Platform</b>
</p>

<p align="center">
  <a href="https://vitahar.kesug.com/" target="_blank">
    <img src="https://img.shields.io/badge/Live_Website-vitahar.kesug.com-2E7D32?style=for-the-badge&logo=google-chrome&logoColor=white" alt="Live Website" />
  </a>
  &nbsp;
  <a href="https://github.com/ankitkumarojha-29/Vitahar/releases/latest/download/vitahar-app.apk" target="_blank">
    <img src="https://img.shields.io/badge/Android_App-Download_APK-3DDC84?style=for-the-badge&logo=android&logoColor=white" alt="Download APK" />
  </a>
  &nbsp;
  <img src="https://img.shields.io/badge/Standards-FSSAI_%26_WHO-blue?style=for-the-badge" alt="FSSAI & WHO" />
</p>

---

## 📖 Overview

**Vitahar** is an end-to-end nutrition intelligence and food safety platform built to help Indian consumers make transparent, healthy food choices. Designed for today's health-conscious generation, Vitahar breaks down packaged food labels and delivers clear, age-calibrated health verdicts: **Safe**, **Consume with Caution**, or **Not Recommended**.

Vitahar is available as both a **Responsive Progressive Web App (PWA)** and a **Native Flutter Android Application**.

---

## ✨ Key Capabilities

- 🎯 **6-Tier Age-Specific Analysis**: Calibrates sugar, salt, trans-fat, and additive intake against official **FSSAI** & **WHO** nutritional guidelines for 6 distinct age groups (infants to seniors).
- 📷 **Multi-Database Barcode Scanner**: Instant barcode scanning backed by a 6-tier fallback cascade querying local MySQL, Open Food Facts, and USDA FoodData Central databases.
- 🤖 **AI Healthy Recipe Maker**: Powered by **Google Gemini AI** to turn packaged products into healthy homemade recipes or discover cleaner dietary alternatives.
- ⚖️ **Side-by-Side Food Comparison**: Compare two competing products head-to-head to pick the healthier, less processed option.
- 🔍 **Predictive Autocomplete & Brand Filtering**: Instant real-time search with company/brand recommendation filters.

---

## 🏗️ Architecture & Technology Stack

| Layer | Technologies |
|---|---|
| **Mobile App** | Flutter 3.x, Dart, `mobile_scanner`, Material 3 |
| **Web Frontend** | Vanilla JavaScript (ES6+), PWA, Chart.js, HTML5-QRCode, Responsive CSS |
| **Backend & APIs** | PHP REST APIs, Apache, MySQL Database |
| **AI Microservices** | Google Gemini API (nutritional analysis & healthy recipe generation) |
| **Food Data Sources** | Local Curated MySQL DB, Open Food Facts API, USDA FoodData Central |

---

## 📱 Mobile App Setup (Flutter)

```bash
cd mobile_app
flutter pub get
flutter run
```

To compile release APK:
```bash
flutter build apk --release
```

---

## 👨‍💻 Author

**Ankit Kumar Ojha**  
*2nd-Year B.Tech CSE (Core) Student at VIT Bhopal University*  
- [GitHub](https://github.com/ankitkumarojha-29)  
- [LinkedIn](https://linkedin.com/in/ankit-kumar-ojha29)  
- Email: [ankitojha6205949869@gmail.com](mailto:ankitojha6205949869@gmail.com)
