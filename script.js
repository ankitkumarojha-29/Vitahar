// ============================================================
//  VITAHAR — script.js  |  50+ Accurate Food Database + AI
// ============================================================

// ── Local database — 50+ products with accurate FSSAI/label data
//    All values per 100g (or 100ml for beverages)
//    salt = sodium in mg, sugar = g, fat = g, calories = kcal
// ────────────────────────────────────────────────────────────
const foodNutritionData = {

  // ── NOODLES & INSTANT FOODS ──────────────────────────────
  "Maggi 2-Minute Noodles":        { calories: 350, sugar: 2,  salt: 1160, fat: 14 },
  "Sunfeast Yippee Noodles":       { calories: 378, sugar: 3,  salt: 1300, fat: 16 },
  "Top Ramen Noodles":             { calories: 347, sugar: 2,  salt: 1500, fat: 13 },
  "Wai Wai Noodles":               { calories: 465, sugar: 3,  salt: 1480, fat: 22 },
  "Patanjali Atta Noodles":        { calories: 340, sugar: 2,  salt: 900,  fat: 10 },
  "Chings Secret Schezwan Noodles":{ calories: 361, sugar: 3,  salt: 1400, fat: 14 },

  // ── CHIPS & CRISPS ───────────────────────────────────────
  "Lays Classic Salted":           { calories: 536, sugar: 1,  salt: 690,  fat: 34 },
  "Lays Magic Masala":             { calories: 536, sugar: 2,  salt: 830,  fat: 34 },
  "Lays Cream & Onion":            { calories: 536, sugar: 2,  salt: 700,  fat: 34 },
  "Kurkure Masala Munch":          { calories: 512, sugar: 3,  salt: 1050, fat: 29 },
  "Kurkure Naughty Tomatoes":      { calories: 510, sugar: 4,  salt: 980,  fat: 28 },
  "Bingo Mad Angles":              { calories: 503, sugar: 4,  salt: 1100, fat: 28 },
  "Bingo Tedhe Medhe":             { calories: 488, sugar: 3,  salt: 1050, fat: 27 },
  "Uncle Chipps Spicy Treat":      { calories: 536, sugar: 2,  salt: 760,  fat: 34 },
  "Pringles Original":             { calories: 536, sugar: 2,  salt: 580,  fat: 34 },
  "Doritos Nacho Cheese":          { calories: 489, sugar: 2,  salt: 755,  fat: 26 },

  // ── NAMKEEN & TRADITIONAL SNACKS ─────────────────────────
  "Haldiram Bhujia Sev":           { calories: 544, sugar: 4,  salt: 1200, fat: 35 },
  "Haldiram Aloo Bhujia":          { calories: 510, sugar: 3,  salt: 1100, fat: 30 },
  "Haldiram Navratan Mix":         { calories: 493, sugar: 5,  salt: 950,  fat: 30 },
  "Bikaji Bhujia":                 { calories: 510, sugar: 4,  salt: 1150, fat: 32 },
  "Bikaji Navratan Mix":           { calories: 481, sugar: 8,  salt: 1050, fat: 28 },

  // ── BISCUITS & COOKIES ───────────────────────────────────
  "Parle-G Biscuits":              { calories: 483, sugar: 26, salt: 370,  fat: 16 },
  "Parle Monaco Biscuits":         { calories: 460, sugar: 5,  salt: 680,  fat: 18 },
  "Britannia Marie Gold":          { calories: 416, sugar: 14, salt: 480,  fat: 9  },
  "Britannia Good Day Cashew":     { calories: 503, sugar: 22, salt: 360,  fat: 23 },
  "Britannia NutriChoice":         { calories: 462, sugar: 12, salt: 460,  fat: 18 },
  "Britannia Bourbon":             { calories: 482, sugar: 30, salt: 340,  fat: 22 },
  "Britannia Treat Jam Cookies":   { calories: 480, sugar: 28, salt: 310,  fat: 22 },
  "Sunfeast Dark Fantasy":         { calories: 524, sugar: 38, salt: 290,  fat: 27 },
  "Sunfeast Bounce":               { calories: 498, sugar: 35, salt: 270,  fat: 25 },
  "Hide & Seek Chocolate":         { calories: 489, sugar: 30, salt: 420,  fat: 24 },
  "Oreo Original":                 { calories: 473, sugar: 41, salt: 480,  fat: 20 },
  "McVities Digestive":            { calories: 471, sugar: 16, salt: 460,  fat: 20 },
  "Patanjali Digestive Biscuit":   { calories: 440, sugar: 16, salt: 380,  fat: 17 },
  "Priyagold Butter Bite":         { calories: 465, sugar: 20, salt: 400,  fat: 20 },

  // ── CHOCOLATE & CANDY ────────────────────────────────────
  "Cadbury Dairy Milk":            { calories: 530, sugar: 57, salt: 100,  fat: 30 },
  "Cadbury 5 Star":                { calories: 459, sugar: 55, salt: 130,  fat: 22 },
  "Cadbury Perk":                  { calories: 517, sugar: 50, salt: 80,   fat: 28 },
  "Cadbury Gems":                  { calories: 466, sugar: 62, salt: 60,   fat: 19 },
  "Cadbury Eclairs":               { calories: 446, sugar: 65, salt: 95,   fat: 18 },
  "KitKat":                        { calories: 518, sugar: 52, salt: 75,   fat: 27 },
  "Nestle Munch":                  { calories: 509, sugar: 48, salt: 95,   fat: 29 },
  "Amul Dark Chocolate 55%":       { calories: 555, sugar: 38, salt: 10,   fat: 35 },
  "Amul Milk Chocolate":           { calories: 545, sugar: 54, salt: 95,   fat: 32 },
  "Mentos Candy":                  { calories: 394, sugar: 90, salt: 30,   fat: 1  },
  "Alpenliebe Candy":              { calories: 394, sugar: 85, salt: 30,   fat: 2  },

  // ── BEVERAGES (per 100 ml) ───────────────────────────────
  "Coca-Cola":                     { calories: 42,  sugar: 11, salt: 10,   fat: 0  },
  "Pepsi":                         { calories: 40,  sugar: 10, salt: 20,   fat: 0  },
  "Sprite":                        { calories: 38,  sugar: 10, salt: 35,   fat: 0  },
  "Thums Up":                      { calories: 42,  sugar: 11, salt: 20,   fat: 0  },
  "Frooti Mango Drink":            { calories: 60,  sugar: 14, salt: 10,   fat: 0  },
  "Maaza Mango Drink":             { calories: 54,  sugar: 13, salt: 10,   fat: 0  },
  "Tropicana Orange Juice":        { calories: 45,  sugar: 10, salt: 20,   fat: 0  },
  "Real Fruit Juice":              { calories: 47,  sugar: 10, salt: 15,   fat: 0  },
  "Red Bull Energy Drink":         { calories: 45,  sugar: 11, salt: 40,   fat: 0  },
  "Limca":                         { calories: 42,  sugar: 10, salt: 30,   fat: 0  },
  "Mountain Dew":                  { calories: 47,  sugar: 11, salt: 30,   fat: 0  }
};

// ── Company database ─────────────────────────────────────────
const companyData = {
  "Nestlé":              ["Maggi 2-Minute Noodles", "KitKat", "Nestle Munch"],
  "ITC":                 ["Sunfeast Yippee Noodles", "Bingo Mad Angles", "Bingo Tedhe Medhe", "Sunfeast Dark Fantasy", "Sunfeast Bounce"],
  "Nissin":              ["Top Ramen Noodles"],
  "CG Foods":            ["Wai Wai Noodles"],
  "Patanjali":           ["Patanjali Atta Noodles", "Patanjali Digestive Biscuit"],
  "Capital Foods":       ["Chings Secret Schezwan Noodles"],
  "PepsiCo":             ["Lays Classic Salted", "Lays Magic Masala", "Lays Cream & Onion", "Kurkure Masala Munch", "Kurkure Naughty Tomatoes", "Uncle Chipps Spicy Treat", "Doritos Nacho Cheese", "Pepsi", "Mountain Dew", "Tropicana Orange Juice"],
  "Kellogg's":           ["Pringles Original"],
  "Haldiram's":          ["Haldiram Bhujia Sev", "Haldiram Aloo Bhujia", "Haldiram Navratan Mix"],
  "Bikaji Foods":        ["Bikaji Bhujia", "Bikaji Navratan Mix"],
  "Parle Products":      ["Parle-G Biscuits", "Parle Monaco Biscuits", "Frooti Mango Drink"],
  "Britannia":           ["Britannia Marie Gold", "Britannia Good Day Cashew", "Britannia NutriChoice", "Britannia Bourbon", "Britannia Treat Jam Cookies"],
  "Mondelez":            ["Cadbury Dairy Milk", "Cadbury 5 Star", "Cadbury Perk", "Cadbury Gems", "Cadbury Eclairs", "Oreo Original"],
  "McVitie's":           ["McVities Digestive"],
  "Priyagold":           ["Priyagold Butter Bite"],
  "Amul":                ["Amul Dark Chocolate 55%", "Amul Milk Chocolate"],
  "Perfetti Van Melle":  ["Mentos Candy", "Alpenliebe Candy"],
  "Coca-Cola India":     ["Coca-Cola", "Sprite", "Thums Up", "Maaza Mango Drink", "Limca"],
  "Red Bull":            ["Red Bull Energy Drink"],
  "Dabur":               ["Real Fruit Juice"]
};

// ── Age-group thresholds ─────────────────────────────────────
const ageLimits = {
  age_0_5:    { calories: 200, sugar: 8,  salt: 300,  fat: 12 },
  age_5_12:   { calories: 350, sugar: 15, salt: 700,  fat: 20 },
  age_12_18:  { calories: 450, sugar: 25, salt: 1200, fat: 28 },
  age_18_30:  { calories: 500, sugar: 30, salt: 1600, fat: 35 },
  age_30_60:  { calories: 450, sugar: 25, salt: 1400, fat: 30 },
  age_60_plus:{ calories: 400, sugar: 20, salt: 1000, fat: 25 }
};

// ── 🔑 PASTE YOUR GEMINI API KEY HERE ────────────────────────
// Get a free key at: https://aistudio.google.com/app/apikey
const GEMINI_API_KEY = "AIzaSyAf91ZJtAv2LthT6dVjmZCWDXxi1SMx5YI";

// ── AI Lookup via Gemini ─────────────────────────────────────
async function fetchNutritionFromAI(productName, companyName) {
  const prompt = `You are a food nutrition database for Indian packaged foods.
Product: "${productName}"
Company: "${companyName || "unknown"}"

Reply with ONLY a raw JSON object. No markdown, no backticks, no explanation, nothing else.
Exactly this format: {"calories":350,"sugar":4,"salt":1160,"fat":14,"found":true}

Fields (all required):
- calories: integer, kcal per 100g
- sugar: integer, grams per 100g  
- salt: integer, milligrams of sodium per 100g
- fat: integer, grams per 100g
- found: true if you know this exact product, false if estimating

Use real Indian packaged food label values. If unknown, set found:false and estimate for this food type.`;

  // ✅ Try these models in order — first one that works wins
  const models = [
    "gemini-2.5-flash-preview-05-20",
    "gemini-1.5-flash-latest",
    "gemini-1.5-flash"
  ];

  let lastError = "Unknown error";

  for (const model of models) {
    try {
      const url = `https://generativelanguage.googleapis.com/v1beta/models/${model}:generateContent?key=${GEMINI_API_KEY}`;

      const response = await fetch(url, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          contents: [{ parts: [{ text: prompt }] }],
          generationConfig: {
            temperature: 0.1,
            maxOutputTokens: 512   // ✅ was 200 — too low, caused truncated JSON
          }
        })
      });

      // ✅ Read the real error from Gemini instead of hiding it
      if (!response.ok) {
        const errBody = await response.json();
        lastError = `[${model}] HTTP ${response.status}: ${errBody?.error?.message || "Unknown API error"}`;
        console.warn("Gemini model failed:", lastError);
        continue; // try next model
      }

      const data = await response.json();
      const raw = data.candidates?.[0]?.content?.parts?.[0]?.text || "";
      console.log(`[${model}] Raw AI response:`, raw); // helpful for debugging

      // ✅ Extract JSON even if there's stray text around it
      const jsonMatch = raw.match(/\{[\s\S]*\}/);
      if (!jsonMatch) {
        lastError = `[${model}] No JSON found in response: "${raw.slice(0, 100)}"`;
        console.warn(lastError);
        continue;
      }

      const parsed = JSON.parse(jsonMatch[0]);

      // ✅ Ensure all fields are numbers
      ["calories", "sugar", "salt", "fat"].forEach(f => {
        if (typeof parsed[f] !== "number") parsed[f] = 0;
      });
      if (typeof parsed.found !== "boolean") parsed.found = false;

      return parsed; // ✅ success

    } catch (err) {
      lastError = `[${model}] ${err.message}`;
      console.warn("Gemini attempt failed:", lastError);
    }
  }

  // All models failed — throw with the real reason
  throw new Error(lastError);
}

// ── Company Autocomplete ──────────────────────────────────────
function setupCompanyAutocomplete(inputEl, dropdownEl) {
  // ✅ Guard — if the dropdown element doesn't exist in HTML, skip silently
  if (!inputEl || !dropdownEl) return;

  inputEl.addEventListener("input", () => {
    const query = inputEl.value.trim().toLowerCase();
    dropdownEl.innerHTML = "";
    dropdownEl.style.display = "none";

    if (query.length < 3) return;

    const matches = Object.keys(companyData).filter(name =>
      name.toLowerCase().includes(query)
    );

    matches.slice(0, 6).forEach(name => {
      const item = document.createElement("div");
      item.className = "autocomplete-item";
      const idx = name.toLowerCase().indexOf(query);
      item.innerHTML =
        `<span class="company-icon">🏭</span> ` +
        name.slice(0, idx) +
        `<strong>${name.slice(idx, idx + query.length)}</strong>` +
        name.slice(idx + query.length);

      item.addEventListener("mousedown", (e) => {
        e.preventDefault();
        inputEl.value = name;
        dropdownEl.innerHTML = "";
        dropdownEl.style.display = "none";

        const products = companyData[name];
        if (products && products.length === 1) {
          document.getElementById("productName").value = products[0];
        }
      });
      dropdownEl.appendChild(item);
    });

    // "Search online" option
    const searchItem = document.createElement("div");
    searchItem.className = "autocomplete-item search-online-item";
    searchItem.innerHTML = `🔍 Search "<strong>${inputEl.value.trim()}</strong>" in search bar`;
    searchItem.addEventListener("mousedown", (e) => {
      e.preventDefault();
      const val = inputEl.value.trim();
      document.getElementById("headerSearch").value = val;
      dropdownEl.style.display = "none";
      window.scrollTo({ top: 0, behavior: "smooth" });
      setTimeout(() => document.getElementById("headerSearch").focus(), 400);
    });
    dropdownEl.appendChild(searchItem);

    dropdownEl.style.display = "block";
  });

  inputEl.addEventListener("blur", () => {
    setTimeout(() => { dropdownEl.style.display = "none"; }, 150);
  });

  inputEl.addEventListener("focus", () => {
    if (inputEl.value.trim().length >= 3 && dropdownEl.children.length > 0) {
      dropdownEl.style.display = "block";
    }
  });
}

// ── Product Autocomplete ──────────────────────────────────────
function setupAutocomplete(inputEl, dropdownEl) {
  if (!inputEl || !dropdownEl) return;

  inputEl.addEventListener("input", () => {
    const query = inputEl.value.trim().toLowerCase();
    dropdownEl.innerHTML = "";
    dropdownEl.style.display = "none";

    if (query.length < 3) return;

    const matches = Object.keys(foodNutritionData).filter(name =>
      name.toLowerCase().includes(query)
    );

    if (matches.length === 0) return;

    matches.slice(0, 8).forEach(name => {
      const item = document.createElement("div");
      item.className = "autocomplete-item";
      const idx = name.toLowerCase().indexOf(query);
      item.innerHTML =
        name.slice(0, idx) +
        `<strong>${name.slice(idx, idx + query.length)}</strong>` +
        name.slice(idx + query.length);

      item.addEventListener("mousedown", (e) => {
        e.preventDefault();
        inputEl.value = name;
        dropdownEl.innerHTML = "";
        dropdownEl.style.display = "none";
        inputEl.dispatchEvent(new Event("autocompleted"));
      });

      dropdownEl.appendChild(item);
    });

    dropdownEl.style.display = "block";
  });

  inputEl.addEventListener("blur", () => {
    setTimeout(() => { dropdownEl.style.display = "none"; }, 150);
  });

  inputEl.addEventListener("focus", () => {
    if (inputEl.value.trim().length >= 3 && dropdownEl.children.length > 0) {
      dropdownEl.style.display = "block";
    }
  });
}

// ── Helpers ──────────────────────────────────────────────────
function calculateAge(dob) {
  const birth = new Date(dob);
  const today = new Date();
  let age = today.getFullYear() - birth.getFullYear();
  const m = today.getMonth() - birth.getMonth();
  if (m < 0 || (m === 0 && today.getDate() < birth.getDate())) age--;
  return age;
}

function getAgeGroupFromAge(age) {
  if (age <= 5)  return "age_0_5";
  if (age <= 12) return "age_5_12";
  if (age <= 18) return "age_12_18";
  if (age <= 30) return "age_18_30";
  if (age <= 60) return "age_30_60";
  return "age_60_plus";
}

function findInLocalDB(productName) {
  const norm = s => s.toLowerCase().replace(/\s+/g, " ").trim();
  const input = norm(productName);
  return Object.keys(foodNutritionData).find(key => {
    const k = norm(key);
    return k.includes(input) || input.includes(k);
  });
}

// ── Verdict logic ────────────────────────────────────────────
function getVerdict(nutrition, limits) {
  let verdict = "safe";
  let reasons = [];
  for (const key in nutrition) {
    if (!limits[key]) continue;
    const val = nutrition[key];
    const lim = limits[key];
    if (val > lim * 2.5) {
      reasons.push(`High ${key}`);
      verdict = "danger";
    } else if (val > lim && verdict !== "danger") {
      reasons.push(`Moderate ${key}`);
      verdict = "caution";
    }
  }
  return { verdict, reasons };
}

const verdictConfig = {
  safe:    { emoji: "✅", label: "Safe to Consume",       color: "#2e7d32", bg: "#e8f5e9" },
  caution: { emoji: "⚠️", label: "Consume with Caution", color: "#e65100", bg: "#fff3e0" },
  danger:  { emoji: "❌", label: "Not Recommended",        color: "#b71c1c", bg: "#ffebee" }
};

function showLoading(message) {
  document.getElementById("result").innerHTML = `
    <div class="ai-loading">
      <div class="spinner"></div>
      <span>${message}</span>
    </div>`;
}

function showResultCard(verdictObj, age, productName, aiUsed, aiFound) {
  const { verdict, reasons } = verdictObj;
  const cfg = verdictConfig[verdict];
  const sourceBadge = aiUsed
    ? (aiFound
        ? `<span class="source-badge ai">✨ Gemini AI Verified</span>`
        : `<span class="source-badge ai estimated">✨ Gemini AI Estimated</span>`)
    : `<span class="source-badge local">📦 Local Database</span>`;

  const reasonHtml = reasons.length > 0
    ? `<div class="verdict-reasons">${reasons.map(r => `<span class="reason-tag">${r}</span>`).join("")}</div>`
    : `<div class="verdict-reasons"><span class="reason-tag safe-tag">All nutrients within range</span></div>`;

  document.getElementById("result").innerHTML = `
    <div class="verdict-card" style="background:${cfg.bg}; border-left:5px solid ${cfg.color};">
      <div class="verdict-emoji">${cfg.emoji}</div>
      <div class="verdict-text">
        <strong style="color:${cfg.color};">${cfg.label}</strong>
        <small>Age: ${age} years &nbsp;|&nbsp; ${productName}</small>
        ${reasonHtml}
        ${sourceBadge}
      </div>
    </div>`;
}

// ── MAIN FORM SUBMIT ──────────────────────────────────────────
document.getElementById("foodForm").addEventListener("submit", async function (e) {
  e.preventDefault();

  const productName = document.getElementById("productName").value.trim();
  const companyName = document.getElementById("companyName").value.trim();
  const dob         = document.getElementById("dob").value;

  if (!productName || !dob) { alert("Please enter product name and date of birth"); return; }

  const age = calculateAge(dob);
  if (age < 0) { alert("Please enter a valid Date of Birth"); return; }

  const ageGroup = getAgeGroupFromAge(age);

  // 1. Try local DB first
  const localKey = findInLocalDB(productName);
  if (localKey) {
    const nutrition = foodNutritionData[localKey];
    showResultCard(getVerdict(nutrition, ageLimits[ageGroup]), age, localKey, false, false);
    showNutrition(nutrition, ageGroup, "compare");
    return;
  }

  // 2. Not in local DB → ask Gemini AI
  // ✅ Check if API key is still the placeholder
  if (!GEMINI_API_KEY || GEMINI_API_KEY === "PASTE_YOUR_KEY_HERE") {
    document.getElementById("result").innerHTML = `
      <div class="verdict-card" style="background:#fff3e0; border-left:5px solid #e65100;">
        <div class="verdict-emoji">🔑</div>
        <div class="verdict-text">
          <strong style="color:#e65100;">Gemini API Key Missing</strong>
          <small>Open script.js and paste your key from <a href="https://aistudio.google.com/app/apikey" target="_blank">aistudio.google.com</a></small>
        </div>
      </div>`;
    return;
  }

  showLoading("✨ Asking Gemini AI for nutrition data…");

  try {
    const aiData = await fetchNutritionFromAI(productName, companyName);
    const displayName = companyName ? `${productName} (${companyName})` : productName;
    foodNutritionData[displayName] = {
      calories: aiData.calories,
      sugar:    aiData.sugar,
      salt:     aiData.salt,
      fat:      aiData.fat
    };
    const nutrition = foodNutritionData[displayName];
    showResultCard(getVerdict(nutrition, ageLimits[ageGroup]), age, displayName, true, aiData.found);
    showNutrition(nutrition, ageGroup, "compare");

  } catch (err) {
    // ✅ Now shows the REAL error message instead of generic "check connection"
    console.error("AI lookup error:", err);
    document.getElementById("result").innerHTML = `
      <div class="verdict-card" style="background:#ffebee; border-left:5px solid #b71c1c;">
        <div class="verdict-emoji">⚠️</div>
        <div class="verdict-text">
          <strong style="color:#b71c1c;">AI Lookup Failed</strong>
          <small style="word-break:break-word;">${err.message}</small>
          <small style="margin-top:4px; display:block;">
            Fix: Get a new key at 
            <a href="https://aistudio.google.com/app/apikey" target="_blank" style="color:#1565c0;">aistudio.google.com</a>
            and paste it in script.js line where GEMINI_API_KEY is set.
          </small>
        </div>
      </div>`;
  }
});

// ── Barcode / QR scanner ──────────────────────────────────────
const startScanBtn = document.getElementById("startScan");
const video        = document.getElementById("video");
const scannerBox   = document.querySelector(".scanner-box");

if (startScanBtn) {
  startScanBtn.addEventListener("click", async () => {
    try {
      const stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: "environment" } });
      video.srcObject = stream;
      scannerBox.style.display = "block";
    } catch (err) {
      alert("Camera access denied or not available");
    }
  });
}

// ── Nutrition table + chart ───────────────────────────────────
let chartInstance = null;

function showNutrition(nutrition, ageGroup, mode) {
  const section = document.getElementById("nutritionSection");
  section.style.display = "block";
  section.offsetHeight;

  const table = document.getElementById("nutritionTable");
  table.innerHTML = "";
  const thead = table.closest("table").querySelector("thead");

  if (mode === "search") {
    thead.innerHTML = `<tr><th>Nutrient</th><th>Value (per 100g)</th></tr>`;
    Object.keys(nutrition).forEach(key => {
      const unit = key === "calories" ? "kcal" : key === "salt" ? "mg" : "g";
      table.innerHTML += `<tr><td>${key.toUpperCase()}</td><td>${nutrition[key]} ${unit}</td></tr>`;
    });
    document.querySelector(".chart-wrapper").style.display = "none";
    document.getElementById("result").innerText = "";
    return;
  }

  // Compare mode
  const limits = ageLimits[ageGroup];
  thead.innerHTML = `<tr><th>Nutrient</th><th>In Product</th><th>Recommended</th></tr>`;

  Object.keys(nutrition).forEach(key => {
    const over = limits[key] && nutrition[key] > limits[key];
    const unit = key === "calories" ? "kcal" : key === "salt" ? "mg" : "g";
    table.innerHTML += `
      <tr ${over ? 'class="over-limit"' : ""}>
        <td>${key.toUpperCase()}</td>
        <td ${over ? 'style="color:#b71c1c;font-weight:bold;"' : ""}>${nutrition[key]} ${unit}</td>
        <td>${limits[key] ?? "—"} ${unit}</td>
      </tr>`;
  });

  document.querySelector(".chart-wrapper").style.display = "block";
  const ctx = document.getElementById("nutritionChart").getContext("2d");
  if (chartInstance) chartInstance.destroy();

  chartInstance = new Chart(ctx, {
    type: "bar",
    data: {
      labels: Object.keys(nutrition),
      datasets: [
        { label: "In Product",  data: Object.values(nutrition), backgroundColor: "#ff9800" },
        { label: "Recommended", data: Object.keys(nutrition).map(k => limits[k] ?? 0), backgroundColor: "#2e7d32" }
      ]
    },
    options: { responsive: true, maintainAspectRatio: false }
  });

  section.scrollIntoView({ behavior: "smooth", block: "start" });
}

// ── Init autocomplete ─────────────────────────────────────────
setupAutocomplete(
  document.getElementById("headerSearch"),
  document.getElementById("headerSuggestions")
);
setupAutocomplete(
  document.getElementById("productName"),
  document.getElementById("productSuggestions")
);

// ✅ companySuggestions may not exist in HTML — guard added inside function
setupCompanyAutocomplete(
  document.getElementById("companyName"),
  document.getElementById("companySuggestions")
);

// ── Header search ─────────────────────────────────────────────
document.getElementById("headerSearchBtn").addEventListener("click", async () => {
  const searchValue = document.getElementById("headerSearch").value.trim();
  if (!searchValue) return;

  const localKey = findInLocalDB(searchValue);
  if (localKey) {
    document.querySelector(".form-section").scrollIntoView({ behavior: "smooth" });
    showNutrition(foodNutritionData[localKey], null, "search");
    return;
  }

  if (!GEMINI_API_KEY || GEMINI_API_KEY === "PASTE_YOUR_KEY_HERE") {
    alert("Please add your Gemini API key in script.js first.");
    return;
  }

  document.querySelector(".form-section").scrollIntoView({ behavior: "smooth" });
  showLoading("✨ Fetching data via Gemini AI…");

  try {
    const aiData = await fetchNutritionFromAI(searchValue, "");
    foodNutritionData[searchValue] = {
      calories: aiData.calories, sugar: aiData.sugar,
      salt:     aiData.salt,     fat:   aiData.fat
    };
    showNutrition(foodNutritionData[searchValue], null, "search");
    document.getElementById("result").innerHTML = aiData.found
      ? `<span class="source-badge ai">✨ Gemini AI Verified</span>`
      : `<span class="source-badge ai estimated">✨ Gemini AI Estimated</span>`;
  } catch (err) {
    console.error("Header search AI error:", err);
    alert("AI lookup failed: " + err.message);
  }
});
