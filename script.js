const foodNutritionData = {
  "Maggi Noodles": {
    calories: 350,
    sugar: 4,
    salt: 1200,
    fat: 14
  },
  "Lays Chips": {
    calories: 550,
    sugar: 2,
    salt: 1700,
    fat: 35
  }
};

const ageLimits = {
  age_0_5: { calories: 120, sugar: 10, salt: 600, fat: 10 },
  age_5_12: { calories: 180, sugar: 15, salt: 900, fat: 15 },
  age_12_18: { calories: 250, sugar: 20, salt: 1200, fat: 22 },
  age_18_30: { calories: 300, sugar: 25, salt: 1500, fat: 30 },
  age_30_60: { calories: 280, sugar: 22, salt: 1400, fat: 28 },
  age_60_plus: { calories: 240, sugar: 20, salt: 1200, fat: 25 }
};

document.getElementById("foodForm").addEventListener("submit", function (e) {
  e.preventDefault();

  // 1️⃣ Product name
  const productName = document
    .getElementById("productName")
    .value.trim();

  // 2️⃣ Date of Birth
  const dob = document.getElementById("dob").value;

  if (!productName || !dob) {
    alert("Please enter product name and date of birth");
    return;
  }

  // 3️⃣ Calculate age & age group automatically
  const age = calculateAge(dob);
  if (age < 0) {
  alert("Please enter a valid Date of Birth");
  return;
}

  const ageGroup = getAgeGroupFromAge(age);

  // 4️⃣ Normalize product name
  const normalizedInput = productName
    .toLowerCase()
    .replace(/\s+/g, " ")
    .trim();

  // 5️⃣ Find matching food
  const matchedKey = Object.keys(foodNutritionData).find(key => {
    const normalizedKey = key.toLowerCase().replace(/\s+/g, " ").trim();
    return (
      normalizedKey.includes(normalizedInput) ||
      normalizedInput.includes(normalizedKey)
    );
  });

  if (!matchedKey) {
    alert("Food data not found. Try: Maggi Noodles or Lays Chips");
    return;
  }

  // 6️⃣ Show nutrition comparison
  showNutrition(matchedKey, ageGroup, "compare");

  // 7️⃣ Verdict logic
  const nutrition = foodNutritionData[matchedKey];
  const limits = ageLimits[ageGroup];

  let verdict = "✅ Safe";
  let color = "green";

  for (let key in nutrition) {
    if (nutrition[key] > limits[key]) {
      verdict = "⚠ Consume with Caution";
      color = "orange";
    }
    if (nutrition[key] > limits[key] * 1.2) {
      verdict = "❌ Not Recommended";
      color = "red";
      break;
    }
  }

  // 8️⃣ Show result with age
  const result = document.getElementById("result");
  result.style.color = color;
  result.innerHTML = `
    ${verdict}<br>
    <small>Age: ${age} years</small>
  `;
});



const startScanBtn = document.getElementById("startScan");
const video = document.getElementById("video");
const scannerBox = document.querySelector(".scanner-box");

startScanBtn.addEventListener("click", async () => {
  try {
    const stream = await navigator.mediaDevices.getUserMedia({
      video: { facingMode: "environment" }
    });

    video.srcObject = stream;
    scannerBox.style.display = "block";
  } catch (err) {
    alert("Camera access denied or not available");
  }
});
let chartInstance = null;

function showNutrition(productKey, ageGroup = null, mode = "compare") {

  const nutrition = foodNutritionData[productKey];
  if (!nutrition) return;

  const section = document.getElementById("nutritionSection");
  section.style.display = "block";
  section.offsetHeight;

  const table = document.getElementById("nutritionTable");
  table.innerHTML = "";

  // ---------- SEARCH MODE (DATABASE ONLY) ----------
  if (mode === "search") {

    // Change table header
    table.parentElement.querySelector("thead").innerHTML = `
      <tr>
        <th>Nutrient</th>
        <th>Value</th>
      </tr>
    `;

    Object.keys(nutrition).forEach(key => {
      table.innerHTML += `
        <tr>
          <td>${key.toUpperCase()}</td>
          <td>${nutrition[key]}</td>
        </tr>
      `;
    });

    // Hide chart
    document.querySelector(".chart-wrapper").style.display = "none";
    document.getElementById("result").innerText = "";

    return;
  }

  // ---------- COMPARE MODE (FORM) ----------
  const limits = ageLimits[ageGroup];

  table.parentElement.querySelector("thead").innerHTML = `
    <tr>
      <th>Nutrient</th>
      <th>In Product</th>
      <th>Recommended</th>
    </tr>
  `;

  Object.keys(nutrition).forEach(key => {
    table.innerHTML += `
      <tr>
        <td>${key.toUpperCase()}</td>
        <td>${nutrition[key]}</td>
        <td>${limits[key]}</td>
      </tr>
    `;
  });

  // Show chart
  document.querySelector(".chart-wrapper").style.display = "block";

  const ctx = document.getElementById("nutritionChart").getContext("2d");
  if (chartInstance) chartInstance.destroy();

  chartInstance = new Chart(ctx, {
    type: "bar",
    data: {
      labels: Object.keys(nutrition),
      datasets: [
        {
          label: "In Product",
          data: Object.values(nutrition),
          backgroundColor: "#ff9800"
        },
        {
          label: "Recommended",
          data: Object.values(limits),
          backgroundColor: "#2e7d32"
        }
      ]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false
    }
  });
}
function calculateAge(dob) {
  const birth = new Date(dob);
  const today = new Date();

  let age = today.getFullYear() - birth.getFullYear();
  const m = today.getMonth() - birth.getMonth();

  if (m < 0 || (m === 0 && today.getDate() < birth.getDate())) {
    age--;
  }
  return age;
}

function getAgeGroupFromAge(age) {
  if (age <= 5) return "age_0_5";
  if (age <= 12) return "age_5_12";
  if (age <= 18) return "age_12_18";
  if (age <= 30) return "age_18_30";
  if (age <= 60) return "age_30_60";
  return "age_60_plus";
}

const headerSearchBtn = document.getElementById("headerSearchBtn");
const headerSearchInput = document.getElementById("headerSearch");

headerSearchBtn.addEventListener("click", () => {
  const searchValue = headerSearchInput.value.trim();
  if (!searchValue) return;

  const normalizedInput = searchValue.toLowerCase().replace(/\s+/g, " ").trim();

  const matchedKey = Object.keys(foodNutritionData).find(key => {
    const normalizedKey = key.toLowerCase().replace(/\s+/g, " ").trim();
    return (
      normalizedKey.includes(normalizedInput) ||
      normalizedInput.includes(normalizedKey)
    );
  });

  if (!matchedKey) {
    alert("Food data not found");
    return;
  }

  // Scroll to nutrition section
  document
    .querySelector(".form-section")
    .scrollIntoView({ behavior: "smooth" });

  // Show ONLY database nutrition
  showNutrition(matchedKey, null, "search");
});
