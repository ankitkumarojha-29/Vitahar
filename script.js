// \u2500\u2500 Dataset size limit \u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500
const MAX_DATASET_SIZE = 100000;

// \u2500\u2500 Local food database (50+ products, per 100g / 100ml) \u2500\u2500\u2500\u2500\u2500
//    salt = sodium mg | sugar = g | fat = g | calories = kcal
const foodNutritionData = {};

// Load saved scans from localStorage on startup \u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500
function loadPersistedScans() {
  try {
    const saved = localStorage.getItem("vitahar_scanned_products");
    if (saved) {
      const parsed = JSON.parse(saved);
      Object.assign(foodNutritionData, parsed);
      console.log(`\u2705 Restored ${Object.keys(parsed).length} scanned product(s) from storage.`);
    }
  } catch (e) {
    console.warn("Could not load persisted scans:", e);
  }
}
loadPersistedScans();

// \u2500\u2500 Save a product to localStorage (only if it has real data) \u2500
function persistScannedProduct(productName, nutrition, source = "external", barcode = "", companyName = "") {
  if (!productName || !nutrition) return;
  const hasRealData = Object.values(nutrition).some(v => v > 0);
  if (!hasRealData) return;

  const totalItems = Object.keys(foodNutritionData).length;
  if (totalItems >= MAX_DATASET_SIZE && !foodNutritionData[productName]) {
    console.warn(`\u26A0\uFE0F Dataset limit reached (${MAX_DATASET_SIZE}). Skipping "${productName}".`);
    return;
  }

  try {
    const saved = localStorage.getItem("vitahar_scanned_products");
    const existing = saved ? JSON.parse(saved) : {};
    const keys = Object.keys(existing);
    if (keys.length >= 200) delete existing[keys[0]]; // evict oldest if over 200
    existing[productName] = nutrition;
    localStorage.setItem("vitahar_scanned_products", JSON.stringify(existing));
    rememberCompanyProduct(companyName, productName);
    saveProductToSql(productName, nutrition, source, barcode, companyName);
    console.log(`\uD83D\uDCBE Saved "${productName}" to local dataset.`);
  } catch (e) {
    console.warn("Could not persist product:", e);
  }
}

// Company database is loaded from MySQL via api.php.
const companyData = {};
const CORE_NUTRIENT_KEYS = ["calories", "sugar", "salt", "fat"];
let nutritionColumnMeta = {};
let nutritionColumnOrder = [...CORE_NUTRIENT_KEYS];

// \u2500\u2500 Age-group thresholds (per 100g serving) \u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500
// Load the same shapes from MySQL via api.php, so the rest of the app can
// keep using the existing animations, autocomplete, scanner and verdict code.
let sqlDatabaseReady = false;
const sqlDatabaseReadyPromise = loadSqlDatabase();

async function loadSqlDatabase() {
  try {
    const res = await fetch("api.php?action=bootstrap", { cache: "no-store" });
    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    const data = await res.json();
    if (!data || typeof data !== "object") throw new Error("Invalid database response");

    if (data.foods && typeof data.foods === "object") {
      const dbKeys = Object.keys(data.foods);
      if (dbKeys.length > 0) {
        // DB is the single source of truth — wipe everything first
        Object.keys(foodNutritionData).forEach(key => delete foodNutritionData[key]);
        Object.assign(foodNutritionData, data.foods);
      } else {
        // Bootstrap returned empty foods — DB may be unreachable or empty.
        // Keep whatever is already in foodNutritionData (from localStorage).
        console.warn("Bootstrap returned 0 foods — keeping cached data.");
      }
    }

    if (data.companies && typeof data.companies === "object") {
      Object.keys(companyData).forEach(key => delete companyData[key]);
      Object.assign(companyData, data.companies);
    }

    if (Array.isArray(data.nutritionColumns)) {
      setNutritionColumns(data.nutritionColumns);
    }

    if (data.ageLimits && typeof data.ageLimits === 'object') {
      Object.assign(ageLimits, data.ageLimits);
    }

    // Sync localStorage: remove any product that no longer exists in DB
    // and update stale entries so admin edits/deletes are reflected instantly.
    // Only sync if the DB actually returned data (guard against empty bootstrap).
    if (data.foods && Object.keys(data.foods).length > 0) {
      syncLocalStorageWithDB(data.foods);
    }

    sqlDatabaseReady = true;
    console.log(`Loaded ${Object.keys(foodNutritionData).length} products from SQL database.`);
  } catch (err) {
    // DB unreachable — fall back to whatever is in localStorage
    loadPersistedScans();
    console.warn("Could not load SQL database. Using cached data:", err);
  }
}

// Keep localStorage in sync with the authoritative DB snapshot.
// Removes deleted items, updates changed items.
function syncLocalStorageWithDB(dbFoods) {
  try {
    const raw = localStorage.getItem("vitahar_scanned_products");
    if (!raw) return;
    const cached = JSON.parse(raw);
    let changed = false;
    Object.keys(cached).forEach(name => {
      if (!dbFoods[name]) {
        // Product was deleted or renamed in admin — remove from cache
        delete cached[name];
        changed = true;
      }
    });
    if (changed) localStorage.setItem("vitahar_scanned_products", JSON.stringify(cached));
  } catch (e) {
    // If localStorage is corrupted just clear it
    try { localStorage.removeItem("vitahar_scanned_products"); } catch (_) {}
  }
}

async function ensureSqlDatabaseReady() {
  if (!sqlDatabaseReady) await sqlDatabaseReadyPromise;
}

function saveProductToSql(productName, nutrition, source = "external", barcode = "", companyName = "") {
  if (!productName || !nutrition) return;
  fetch("api.php?action=save", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({
      name: productName,
      nutrition,
      source,
      barcode,
      company: companyName,
      companyName: companyName   // send both keys so api.php always finds it
    })
  }).catch(err => console.warn("Could not save product to SQL:", err));
}

// ── Age-group thresholds (per 100g serving) ──────────────────────────────────
// These are loaded from the MySQL database via api.php bootstrap.
// The defaults here are used only as a fallback if the DB is unreachable.
let ageLimits = {
  age_0_5:     { calories: 200, sugar: 8,  salt: 300,  fat: 12, protein: 13, fiber: 14 },
  age_5_12:    { calories: 350, sugar: 15, salt: 700,  fat: 20, protein: 20, fiber: 18 },
  age_12_18:   { calories: 450, sugar: 25, salt: 1200, fat: 28, protein: 34, fiber: 22 },
  age_18_30:   { calories: 500, sugar: 30, salt: 1600, fat: 35, protein: 50, fiber: 25 },
  age_30_60:   { calories: 450, sugar: 25, salt: 1400, fat: 30, protein: 46, fiber: 25 },
  age_60_plus: { calories: 400, sugar: 20, salt: 1000, fat: 25, protein: 40, fiber: 21 }
};

const FEEDBACK_EMAIL = "ankitojha6205949869@gmail.com";

// \u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550
//  UTILITIES
// \u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550

function debounce(fn, delay) {
  let timer;
  return (...args) => { clearTimeout(timer); timer = setTimeout(() => fn(...args), delay); };
}

function escapeHtml(str) {
  return String(str)
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;");
}

function hasNutritionData(nutrition) {
  return !!nutrition && Object.values(nutrition).some(v => Number(v) > 0);
}

function getFirstBrand(value) {
  return String(value || "")
    .split(",")
    .map(part => part.trim())
    .find(Boolean) || "";
}

function rememberCompanyProduct(companyName, productName) {
  const company = String(companyName || "").trim();
  const product = String(productName || "").trim();
  if (!company || !product) return;
  if (!companyData[company]) companyData[company] = [];
  if (!companyData[company].some(name => name.toLowerCase() === product.toLowerCase())) {
    companyData[company].push(product);
  }
}

function setNutritionColumns(columns) {
  nutritionColumnMeta = {};
  nutritionColumnOrder = [];
  columns.forEach(col => {
    if (!col || !col.key) return;
    nutritionColumnMeta[col.key] = col;
    if (col.visible !== false) nutritionColumnOrder.push(col.key);
  });
  CORE_NUTRIENT_KEYS.forEach(key => {
    if (!nutritionColumnMeta[key]) nutritionColumnMeta[key] = defaultNutritionMeta(key);
    if (!nutritionColumnOrder.includes(key)) nutritionColumnOrder.push(key);
  });
}

function defaultNutritionMeta(key) {
  return {
    key,
    label: key.replace(/_/g, " ").replace(/\b\w/g, ch => ch.toUpperCase()),
    unit: key === "calories" ? "kcal" : key === "salt" ? "mg" : "g",
    core: CORE_NUTRIENT_KEYS.includes(key),
    visible: true
  };
}

function getNutritionMeta(key) {
  return nutritionColumnMeta[key] || defaultNutritionMeta(key);
}

function hasPublicNutritionValue(key, value) {
  if (value === null || value === undefined || value === "") return false;
  const meta = getNutritionMeta(key);
  if (meta.visible === false) return false;
  // Core nutrients (calories, sugar, salt, fat) always show even when 0
  if (meta.core) return true;
  // Non-core: only show if there is a real nonzero value
  if (Number(value) === 0) return false;
  return true;
}

function getPublicNutritionEntries(nutrition) {
  // Always start with core keys so they appear even when nutritionColumnOrder is stale
  const coreFirst = [...CORE_NUTRIENT_KEYS];
  const extras = nutritionColumnOrder.filter(k => !coreFirst.includes(k));
  const objectKeys = Object.keys(nutrition || {}).filter(k => !coreFirst.includes(k) && !extras.includes(k));
  const orderedKeys = [...coreFirst, ...extras, ...objectKeys];
  return orderedKeys
    .filter((key, index, arr) => arr.indexOf(key) === index)
    .filter(key => hasPublicNutritionValue(key, nutrition[key]))
    .map(key => ({ key, value: nutrition[key], meta: getNutritionMeta(key) }));
}

function formatNutritionValue(entry) {
  const unit = entry.meta.unit || "";
  return `${entry.value}${unit ? " " + unit : ""}`;
}

function getBarcodeFromScan(value) {
  const text = String(value || "").trim();
  const direct = text.match(/^\d{6,14}$/);
  if (direct) return text;
  const embedded = text.match(/\d{6,14}/);
  return embedded ? embedded[0] : text;
}

function getBarcodeVariants(value) {
  const barcode = getBarcodeFromScan(value);
  if (!/^\d{6,14}$/.test(barcode)) return [];

  const variants = new Set([barcode]);
  if (barcode.length === 12) variants.add(`0${barcode}`);
  if ((barcode.length === 13 || barcode.length === 14) && barcode.startsWith("0")) {
    variants.add(barcode.slice(1));
  }
  return Array.from(variants);
}

function findCompanyForProduct(productName) {
  const target = productName.toLowerCase();
  return Object.keys(companyData).find(company =>
    companyData[company].some(product => {
      const name = product.toLowerCase();
      return name === target || name.includes(target) || target.includes(name);
    })
  ) || "";
}

function fetchWithTimeout(url, ms = 8000) {
  return Promise.race([
    fetch(url),
    new Promise((_, reject) => setTimeout(() => reject(new Error("Timeout")), ms))
  ]);
}

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

// \u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550
//  3-API BARCODE CHAIN: OFF \u2192 UPC Item DB \u2192 USDA
// \u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550

// ══════════════════════════════════════════════════════════════════
//  SHARED NUTRITION EXTRACTOR  (for OFF responses)
// ══════════════════════════════════════════════════════════════════
function extractOFFNutrition(p) {
  const n = p.nutriments || {};
  const sodiumG = n["sodium_100g"] ?? n["sodium"] ?? 0;
  const proteinVal = n["proteins_100g"] ?? n["proteins"] ?? 0;
  const fiberVal   = n["fiber_100g"]    ?? n["fiber"]    ?? n["fibers_100g"] ?? n["fibers"] ?? 0;
  const result = {
    calories: Math.round(n["energy-kcal_100g"] ?? n["energy-kcal"] ?? 0),
    sugar:    Math.round(n["sugars_100g"]       ?? n["sugars"]       ?? 0),
    fat:      Math.round(n["fat_100g"]          ?? n["fat"]          ?? 0),
    salt:     Math.round(sodiumG * 1000),   // sodium g → mg
  };
  if (proteinVal > 0) result.protein = Math.round(proteinVal * 10) / 10;
  if (fiberVal   > 0) result.fiber   = Math.round(fiberVal   * 10) / 10;
  return result;
}

// ══════════════════════════════════════════════════════════════════
//  BARCODE APIS  (called in order; first non-null wins)
// ══════════════════════════════════════════════════════════════════

// 1a. Our own MySQL — barcode column (instant, grows with every scan)
async function fetchFromMySQLBarcode(barcode) {
  try {
    const res = await fetchWithTimeout(`api.php?action=barcode&bc=${encodeURIComponent(barcode)}`);
    if (!res.ok) return null;
    const data = await res.json();
    if (!data.found) return null;
    if (Array.isArray(data.nutritionColumns)) setNutritionColumns(data.nutritionColumns);
    return { productName: data.productName, companyName: data.companyName || "", nutrition: data.nutrition, source: "MySQL" };
  } catch (e) { console.warn("MySQL barcode:", e.message); return null; }
}

// 1b. Open Food Facts — world (includes India, has Parle-G, Marie Gold etc.)
async function fetchFromOpenFoodFacts(barcode) {
  try {
    const res = await fetchWithTimeout(
      `https://world.openfoodfacts.org/api/v2/product/${barcode}.json?fields=product_name,product_name_en,product_name_hi,brands,nutriments`
    );
    if (!res.ok) return null;
    const data = await res.json();
    if (data.status !== 1 || !data.product) return null;
    const p = data.product;
    const nutrition = extractOFFNutrition(p);
    const productName = (p.product_name || p.product_name_en || p.product_name_hi || "").trim();
    if (!productName) return null;
    // Always return the product — with or without nutrition (caller will fill gaps)
    return { productName, companyName: getFirstBrand(p.brands), nutrition };
  } catch (e) { console.warn("OFF barcode:", e.message); return null; }
}

// 1c. Open Food Facts — India subdomain (higher priority for Indian products)
async function fetchFromOFFIndia(barcode) {
  try {
    const res = await fetchWithTimeout(
      `https://in.openfoodfacts.org/api/v2/product/${barcode}.json?fields=product_name,product_name_en,brands,nutriments`
    );
    if (!res.ok) return null;
    const data = await res.json();
    if (data.status !== 1 || !data.product) return null;
    const p = data.product;
    const nutrition = extractOFFNutrition(p);
    const productName = (p.product_name || p.product_name_en || "").trim();
    if (!productName) return null;
    // Always return the product — caller fills nutrition gaps
    return { productName, companyName: getFirstBrand(p.brands), nutrition };
  } catch (e) { console.warn("OFF India barcode:", e.message); return null; }
}

// 1d. Open Beauty / Products Facts — catches products not in food DB
async function fetchFromOpenProductsFacts(barcode) {
  try {
    const res = await fetchWithTimeout(
      `https://world.openproductsfacts.org/api/v2/product/${barcode}.json?fields=product_name,brands,nutriments`
    );
    if (!res.ok) return null;
    const data = await res.json();
    if (data.status !== 1 || !data.product) return null;
    const p = data.product;
    const nutrition = extractOFFNutrition(p);
    const productName = (p.product_name || "").trim();
    if (!productName) return null;
    // Always return — caller fills nutrition gaps
    return { productName, companyName: getFirstBrand(p.brands), nutrition };
  } catch (e) { console.warn("OpenProductsFacts:", e.message); return null; }
}

// 1e. UPC Item DB — free tier, good global coverage
async function fetchFromUPCItemDB(barcode) {
  try {
    const res = await fetchWithTimeout(`https://api.upcitemdb.com/prod/trial/lookup?upc=${barcode}`);
    if (!res.ok) return null;
    const data = await res.json();
    if (!data.items || data.items.length === 0) return null;
    const item = data.items[0];
    const productName = (item.title || item.description || "").trim();
    if (!productName) return null;
    const companyName = item.brand || item.manufacturer || "";
    // UPC gives name + brand; try to fetch nutrition from USDA by name
    const nutrition = await fetchNutritionFromUSDA(productName);
    // Always return the product name — nutrition may be null/partial, that's fine
    return {
      productName,
      companyName,
      nutrition: nutrition || { calories: 0, sugar: 0, salt: 0, fat: 0 }
    };
  } catch (e) { console.warn("UPC ItemDB:", e.message); return null; }
}

// 1f. Datakick — community product database, decent Indian coverage
async function fetchFromDatakick(barcode) {
  try {
    const res = await fetchWithTimeout(`https://www.datakick.org/api/items/${barcode}`);
    if (!res.ok) return null;
    const data = await res.json();
    const productName = (data.name || "").trim();
    if (!productName) return null;
    const companyName = data.brand_name || data.brand || "";
    const nutrition = {
      calories: Math.round(data.serving_calories ?? 0),
      sugar:    Math.round(data.sugars ?? 0),
      fat:      Math.round(data.fat ?? 0),
      salt:     Math.round(data.sodium ?? 0),   // already in mg
      ...(((data.protein ?? 0) > 0)        ? { protein: Math.round((data.protein ?? 0) * 10) / 10 } : {}),
      ...(((data.dietary_fiber ?? 0) > 0)  ? { fiber:   Math.round((data.dietary_fiber ?? 0) * 10) / 10 } : {}),
    };
    // If Datakick has no nutrition at all, try USDA by product name
    if (!hasNutritionData(nutrition)) {
      const usdaNutrition = await fetchNutritionFromUSDA(productName);
      if (usdaNutrition && hasNutritionData(usdaNutrition)) {
        return { productName, companyName, nutrition: usdaNutrition };
      }
    }
    // Always return — even with zero nutrition (barcode resolver merges from other sources)
    return { productName, companyName, nutrition };
  } catch (e) { console.warn("Datakick:", e.message); return null; }
}

// Shared USDA nutrition helper (used by UPC path + manual search)
async function fetchNutritionFromUSDA(query) {
  try {
    const res = await fetchWithTimeout(
      `https://api.nal.usda.gov/fdc/v1/foods/search?query=${encodeURIComponent(query)}&pageSize=3&api_key=DEMO_KEY`
    );
    if (!res.ok) return null;
    const data = await res.json();
    if (!data.foods || data.foods.length === 0) return null;

    let bestNutrition = null;
    let bestScore = -1;

    for (const food of data.foods) {
      const nutrients = food.foodNutrients || [];
      const get = (...names) => {
        for (const name of names) {
          const found = nutrients.find(n =>
            n.nutrientName && n.nutrientName.toLowerCase().includes(name.toLowerCase())
          );
          if (found) return Math.round(found.value ?? 0);
        }
        return 0;
      };
      const getDec = (...names) => {
        for (const name of names) {
          const found = nutrients.find(n =>
            n.nutrientName && n.nutrientName.toLowerCase().includes(name.toLowerCase())
          );
          if (found && (found.value ?? 0) > 0) return Math.round((found.value ?? 0) * 10) / 10;
        }
        return 0;
      };
      const proteinVal = getDec("protein");
      const fiberVal   = getDec("fiber, total dietary");
      const nutrition = {
        calories: get("energy", "calories"),
        sugar:    get("sugars, total", "sugars"),
        salt:     get("sodium"),
        fat:      get("total lipid", "fat"),
        ...(proteinVal > 0 ? { protein: proteinVal } : {}),
        ...(fiberVal   > 0 ? { fiber:   fiberVal   } : {}),
      };
      const score = Object.values(nutrition).filter(v => Number(v) > 0).length;
      if (score > bestScore) {
        bestScore = score;
        bestNutrition = nutrition;
      }
    }
    return bestNutrition;
  } catch (e) { console.warn("USDA nutrition:", e.message); return null; }
}

// ══════════════════════════════════════════════════════════════════
//  MASTER BARCODE RESOLVER — 6 sources, all free, no API key
//  Order: MySQL cache → OFF India → OFF World → OpenProductsFacts
//          → Datakick → UPC+USDA
// ══════════════════════════════════════════════════════════════════
async function resolveBarcode(barcode) {
  const variants = getBarcodeVariants(barcode);
  const scannedText = String(barcode || "").trim();
  if (!scannedText) return null;

  if (variants.length === 0) {
    const textResult = await searchThreeAPIs(scannedText);
    if (!textResult) return null;
    return {
      productName: textResult.name,
      companyName: textResult.company || "",
      nutrition: textResult.nutrition,
      source: `${textResult.source} (scan text)`
    };
  }

  await ensureSqlDatabaseReady();

  // ① MySQL — instant for previously scanned products
  for (const variant of variants) {
    const mysql = await fetchFromMySQLBarcode(variant);
    if (mysql) return { ...mysql, barcode: variant };
  }

  // ② Run the remaining APIs in parallel for speed
  const lookups = [];
  variants.forEach(variant => {
    lookups.push(fetchFromOFFIndia(variant).then(result => result && ({ ...result, barcode: variant, apiSource: "offIndia" })));
    lookups.push(fetchFromOpenFoodFacts(variant).then(result => result && ({ ...result, barcode: variant, apiSource: "offWorld" })));
    lookups.push(fetchFromOpenProductsFacts(variant).then(result => result && ({ ...result, barcode: variant, apiSource: "openProducts" })));
    lookups.push(fetchFromDatakick(variant).then(result => result && ({ ...result, barcode: variant, apiSource: "datakick" })));
    lookups.push(fetchFromUPCItemDB(variant).then(result => result && ({ ...result, barcode: variant, apiSource: "upc" })));
  });

  const settled = await Promise.allSettled(lookups);

  // Collect all non-null results
  const candidates = settled
    .filter(r => r.status === "fulfilled" && r.value)
    .map(r => r.value);

  const labelFor = (apiSource) => ({
    offIndia:     "Open Food Facts (India)",
    offWorld:     "Open Food Facts",
    openProducts: "Open Products Facts",
    datakick:     "Datakick",
    upc:          "UPC Item DB"
  }[apiSource] || "Barcode API");

  // Priority order for selecting name/company (India first, UPC last)
  const PRIORITY = ["offIndia", "offWorld", "openProducts", "datakick", "upc"];

  // Sort candidates by priority, then by richness of nutrition data
  const sorted = [...candidates].sort((a, b) => {
    const pa = PRIORITY.indexOf(a.apiSource);
    const pb = PRIORITY.indexOf(b.apiSource);
    if (pa !== pb) return pa - pb;
    const scoreA = a.nutrition ? Object.values(a.nutrition).filter(v => Number(v) > 0).length : 0;
    const scoreB = b.nutrition ? Object.values(b.nutrition).filter(v => Number(v) > 0).length : 0;
    return scoreB - scoreA;
  });

  // Pick the best result (by priority) that has a product name
  let result = sorted.find(c => c.productName) || null;

  if (!result) {
    // ALL barcode APIs failed — fall back to text search using the raw barcode string
    // (some QR codes embed the product name directly)
    const textResult = await searchThreeAPIs(scannedText);
    if (textResult) {
      return {
        productName: textResult.name,
        companyName: textResult.company || "",
        nutrition:   textResult.nutrition || { calories: 0, sugar: 0, salt: 0, fat: 0 },
        source:      `${textResult.source} (text fallback)`
      };
    }
    return null;
  }

  let label = labelFor(result.apiSource);

  // Merge nutrition from ALL candidates into one object — fill any zero/missing field
  const mergedNutrition = Object.assign(
    { calories: 0, sugar: 0, salt: 0, fat: 0 },
    result.nutrition || {}
  );
  for (const c of sorted) {
    if (c === result || !c.nutrition) continue;
    for (const key of Object.keys(c.nutrition)) {
      const val = Number(c.nutrition[key]);
      if (val > 0 && !(Number(mergedNutrition[key]) > 0)) {
        mergedNutrition[key] = c.nutrition[key];
      }
    }
  }
  result = { ...result, nutrition: mergedNutrition };

  // Also merge company name — use first candidate that has one
  if (!result.companyName) {
    const withCompany = sorted.find(c => c.companyName);
    if (withCompany) result = { ...result, companyName: withCompany.companyName };
  }

  // If still no nutrition after merging all barcode APIs, try text-search by product name
  if (!hasNutritionData(result.nutrition)) {
    const manualResult = await searchThreeAPIs(result.productName);
    if (manualResult) {
      result = {
        ...result,
        companyName: result.companyName || manualResult.company || "",
        nutrition:   hasNutritionData(manualResult.nutrition)
          ? manualResult.nutrition
          : result.nutrition
      };
      if (hasNutritionData(manualResult.nutrition)) {
        label = `${label} + ${manualResult.source}`;
      }
    }
  }
  
  // Ensure impossible values aren't passed to the DB
  if (result.nutrition) {
    result.nutrition = sanitizeNutrition(result.nutrition);
  }

  // Cache to MySQL with barcode so next scan is instant.
  // Save even if nutrition is missing — at minimum the company-product link is preserved.
  if (result.productName) {
    const srcKey = result.apiSource === "offIndia" ? "OFF-IN" : (result.barcode ? "barcode" : "api");
    saveProductToSql(
      result.productName,
      hasNutritionData(result.nutrition) ? result.nutrition : { calories: 0, sugar: 0, salt: 0, fat: 0 },
      srcKey,
      result.barcode || "",
      result.companyName || ""
    );
    // Update in-memory companyData so admin dashboard reflects it without a full reload
    if (result.companyName) rememberCompanyProduct(result.companyName, result.productName);
  }
  return { ...result, source: hasNutritionData(result.nutrition) ? label : label + " (name only)" };
}

// ══════════════════════════════════════════════════════════════════
//  TEXT SEARCH APIs  (manual typing — also uses all sources)
// ══════════════════════════════════════════════════════════════════

async function searchOpenFoodFactsByName(query) {
  try {
    const res = await fetchWithTimeout(
      `https://world.openfoodfacts.org/cgi/search.pl?search_terms=${encodeURIComponent(query)}&search_simple=1&json=1&page_size=3&cc=in`
    );
    if (!res.ok) return null;
    const data = await res.json();
    if (!data.products || data.products.length === 0) return null;

    // Try each product in the result list — pick the first one that has nutrition
    let bestProduct = null;
    let bestNutrition = null;
    for (const p of data.products) {
      const nutrition = extractOFFNutrition(p);
      if (hasNutritionData(nutrition)) {
        bestProduct = p;
        bestNutrition = nutrition;
        break;
      }
      // Keep as fallback (name only) if we haven't found a better one
      if (!bestProduct && (p.product_name || p.product_name_en || "").trim()) {
        bestProduct = p;
        bestNutrition = nutrition; // may be all-zeros, that's OK
      }
    }
    if (!bestProduct) return null;

    const productName = (bestProduct.product_name || bestProduct.product_name_en || query).trim();
    return {
      name: productName,
      company: getFirstBrand(bestProduct.brands),
      nutrition: bestNutrition,
      source: "Open Food Facts"
    };
  } catch (e) { console.warn("OFF text search:", e.message); return null; }
}

async function searchUSDAByName(query) {
  try {
    const res = await fetchWithTimeout(
      `https://api.nal.usda.gov/fdc/v1/foods/search?query=${encodeURIComponent(query)}&api_key=DEMO_KEY&pageSize=3`
    );
    if (!res.ok) return null;
    const data = await res.json();
    if (!data.foods || data.foods.length === 0) return null;

    // Try multiple results — pick the one with the most nutrition data
    let bestFood = null;
    let bestNutrition = null;
    let bestScore = -1;

    for (const food of data.foods) {
      const nutrients = food.foodNutrients || [];
      const get = (...names) => {
        for (const name of names) {
          const found = nutrients.find(n =>
            n.nutrientName && n.nutrientName.toLowerCase().includes(name.toLowerCase())
          );
          if (found) return Math.round(found.value ?? 0);
        }
        return 0;
      };
      const getDec = (...names) => {
        for (const name of names) {
          const found = nutrients.find(n =>
            n.nutrientName && n.nutrientName.toLowerCase().includes(name.toLowerCase())
          );
          if (found && (found.value ?? 0) > 0) return Math.round((found.value ?? 0) * 10) / 10;
        }
        return 0;
      };
      const proteinVal = getDec("protein");
      const fiberVal   = getDec("fiber, total dietary");
      const nutrition = {
        calories: get("energy", "calories"),
        sugar:    get("sugars, total", "sugars"),
        salt:     get("sodium"),
        fat:      get("total lipid", "fat"),
        ...(proteinVal > 0 ? { protein: proteinVal } : {}),
        ...(fiberVal   > 0 ? { fiber:   fiberVal   } : {}),
      };
      const score = Object.values(nutrition).filter(v => v > 0).length;
      if (score > bestScore) {
        bestScore = score;
        bestFood = food;
        bestNutrition = nutrition;
      }
    }

    if (!bestFood) return null;
    // Return even if all values are 0 — at least we found the product name
    return {
      name: (bestFood.description || query).trim(),
      company: bestFood.brandOwner || bestFood.brandName || "",
      nutrition: bestNutrition,
      source: "USDA"
    };
  } catch (e) { console.warn("USDA text search:", e.message); return null; }
}

// Datakick text search by name (barcode=0 triggers name search)
async function searchDatakickByName(query) {
  try {
    const res = await fetchWithTimeout(
      `https://www.datakick.org/api/items?query=${encodeURIComponent(query)}`
    );
    if (!res.ok) return null;
    const data = await res.json();
    if (!Array.isArray(data) || data.length === 0) return null;

    // Find the item with the most nutrition data
    let bestItem = null;
    let bestScore = -1;
    for (const item of data) {
      if (!(item.name || "").trim()) continue;
      const score = [item.serving_calories, item.sugars, item.fat, item.sodium, item.protein, item.dietary_fiber]
        .filter(v => v != null && v > 0).length;
      if (score > bestScore) { bestScore = score; bestItem = item; }
    }
    if (!bestItem) bestItem = data[0]; // fallback to first

    const productName = (bestItem.name || "").trim();
    if (!productName) return null;
    const nutrition = {
      calories: Math.round(bestItem.serving_calories ?? 0),
      sugar:    Math.round(bestItem.sugars ?? 0),
      fat:      Math.round(bestItem.fat ?? 0),
      salt:     Math.round(bestItem.sodium ?? 0),
      ...(((bestItem.protein ?? 0) > 0)        ? { protein: Math.round((bestItem.protein ?? 0) * 10) / 10 } : {}),
      ...(((bestItem.dietary_fiber ?? 0) > 0)  ? { fiber:   Math.round((bestItem.dietary_fiber ?? 0) * 10) / 10 } : {}),
    };
    return {
      name: productName,
      company: bestItem.brand_name || bestItem.brand || "",
      nutrition, // may have all-zero cores — caller merges from other sources
      source: "Datakick"
    };
  } catch (e) { console.warn("Datakick text:", e.message); return null; }
}

// Master text search — all APIs run in parallel, merge best nutrition from all sources
async function searchThreeAPIs(query) {
  const [offResult, usdaResult, datakickResult] = await Promise.allSettled([
    searchOpenFoodFactsByName(query),
    searchUSDAByName(query),
    searchDatakickByName(query),
  ]);
  // Prefer: OFF (food-specific, Indian products) → Datakick → USDA
  const off  = offResult.status      === "fulfilled" ? offResult.value      : null;
  const usda = usdaResult.status     === "fulfilled" ? usdaResult.value     : null;
  const dk   = datakickResult.status === "fulfilled" ? datakickResult.value : null;

  // Collect all results that have at least a name
  const allResults = [off, dk, usda].filter(Boolean);
  if (allResults.length === 0) return null;

  // Pick the primary result: prefer whichever has the most nutrition data
  const scoreResult = (r) => r && r.nutrition
    ? Object.values(r.nutrition).filter(v => Number(v) > 0).length : 0;

  // Sort by nutrition data richness: OFF > DK > USDA for tie-breaking
  const ranked = allResults.sort((a, b) => scoreResult(b) - scoreResult(a));
  const primary = ranked[0];

  // Build merged nutrition: start with primary, fill gaps from the others
  const mergedNutrition = Object.assign(
    { calories: 0, sugar: 0, salt: 0, fat: 0 },
    primary.nutrition || {}
  );

  const others = [off, dk, usda].filter(r => r && r !== primary && r.nutrition);
  for (const other of others) {
    // Fill any zero/missing value from another source
    for (const key of Object.keys(other.nutrition)) {
      const val = Number(other.nutrition[key]);
      if (val > 0 && !(Number(mergedNutrition[key]) > 0)) {
        mergedNutrition[key] = other.nutrition[key];
      }
    }
  }

  // Build combined source label
  const sourceNames = [];
  if (off)  sourceNames.push("Open Food Facts");
  if (dk)   sourceNames.push("Datakick");
  if (usda) sourceNames.push("USDA");
  const sourceLabel = sourceNames.slice(0, 2).join(" + ") || primary.source || "Online API";

  return {
    name:      primary.name || query,
    company:   primary.company || (others.find(r => r.company) || {}).company || "",
    nutrition: sanitizeNutrition(mergedNutrition),
    source:    sourceLabel
  };
}

// Ensure impossible nutrition values from external APIs are corrected
function sanitizeNutrition(n) {
  let sugar = Number(n.sugar) || 0;
  let salt = Number(n.salt) || 0;
  let fat = Number(n.fat) || 0;
  let calories = Number(n.calories) || 0;

  // Cap at realistic max values per 100g/serving
  if (sugar > 100) sugar = 100;
  if (fat > 100) fat = 100;
  if (salt > 50000) salt = 50000; // Cap salt at 50,000 mg
  if (calories > 900) calories = 900; // Pure fat is 900kcal/100g

  // No negatives
  sugar = Math.max(0, sugar);
  salt = Math.max(0, salt);
  fat = Math.max(0, fat);
  calories = Math.max(0, calories);

  return { 
    ...n, 
    sugar: parseFloat(sugar.toFixed(1)), 
    salt: parseFloat(salt.toFixed(1)), 
    fat: parseFloat(fat.toFixed(1)), 
    calories: parseFloat(calories.toFixed(1)) 
  };
}


// Fetch product name suggestions from OFF + USDA simultaneously
async function fetchSuggestionsFromAPIs(query) {
  const seen = new Set(Object.keys(foodNutritionData).map(k => k.toLowerCase()));

  // Fire all 3 APIs simultaneously for speed
  const [offRes, usdaRes, dkRes] = await Promise.allSettled([
    fetchWithTimeout(
      `https://world.openfoodfacts.org/cgi/search.pl?search_terms=${encodeURIComponent(query)}&search_simple=1&json=1&page_size=6&cc=in`,
      6000
    ).then(r => r.ok ? r.json() : null).catch(() => null),

    fetchWithTimeout(
      `https://api.nal.usda.gov/fdc/v1/foods/search?query=${encodeURIComponent(query)}&api_key=DEMO_KEY&pageSize=6`,
      6000
    ).then(r => r.ok ? r.json() : null).catch(() => null),

    fetchWithTimeout(
      `https://www.datakick.org/api/items?query=${encodeURIComponent(query)}`,
      6000
    ).then(r => r.ok ? r.json() : null).catch(() => null),
  ]);

  const results = [];

  // OFF first (India-biased, best for Parle-G, Marie Gold, etc.)
  const offData = offRes.status === "fulfilled" ? offRes.value : null;
  if (offData && offData.products) {
    offData.products.forEach(p => {
      const name = (p.product_name || p.product_name_en || "").trim();
      if (name.length > 1 && !seen.has(name.toLowerCase())) {
        seen.add(name.toLowerCase());
        results.push({ name, source: "OFF", company: getFirstBrand(p.brands) });
      }
    });
  }

  // Datakick (local & Indian brands)
  const dkData = dkRes.status === "fulfilled" ? dkRes.value : null;
  if (Array.isArray(dkData)) {
    dkData.forEach(item => {
      const name = (item.name || "").trim();
      if (name.length > 1 && !seen.has(name.toLowerCase())) {
        seen.add(name.toLowerCase());
        results.push({ name, source: "DK", company: item.brand_name || item.brand || "" });
      }
    });
  }

  // USDA (generic nutrition reference)
  const usdaData = usdaRes.status === "fulfilled" ? usdaRes.value : null;
  if (usdaData && usdaData.foods) {
    usdaData.foods.forEach(f => {
      const name = (f.description || "").trim();
      if (name.length > 1 && !seen.has(name.toLowerCase())) {
        seen.add(name.toLowerCase());
        results.push({ name, source: "USDA", company: f.brandOwner || f.brandName || "" });
      }
    });
  }

  return results;
}

async function fetchCompanySuggestionsFromAPIs(query) {
  if (!query || query.length < 2) return [];
  const seen = new Set();
  const results = [];
  const add = (name, product, source) => {
    const company = getFirstBrand(name);
    if (!company || seen.has(company.toLowerCase())) return;
    seen.add(company.toLowerCase());
    results.push({ name: company, product: product || "", source });
  };

  const [offRes, usdaRes, dkRes] = await Promise.allSettled([
    fetchWithTimeout(
      `https://world.openfoodfacts.org/cgi/search.pl?search_terms=${encodeURIComponent(query)}&search_simple=1&json=1&page_size=12&cc=in`,
      6000
    ).then(r => r.ok ? r.json() : null).catch(() => null),

    fetchWithTimeout(
      `https://api.nal.usda.gov/fdc/v1/foods/search?query=${encodeURIComponent(query)}&api_key=DEMO_KEY&pageSize=12`,
      6000
    ).then(r => r.ok ? r.json() : null).catch(() => null),

    fetchWithTimeout(
      `https://www.datakick.org/api/items?query=${encodeURIComponent(query)}`,
      6000
    ).then(r => r.ok ? r.json() : null).catch(() => null),
  ]);

  const offData = offRes.status === "fulfilled" ? offRes.value : null;
  (offData?.products || []).forEach(p => {
    add(p.brands, p.product_name || p.product_name_en || query, "OFF");
  });

  const dkData = dkRes.status === "fulfilled" ? dkRes.value : null;
  if (Array.isArray(dkData)) {
    dkData.forEach(item => add(item.brand_name || item.brand, item.name || query, "DK"));
  }

  const usdaData = usdaRes.status === "fulfilled" ? usdaRes.value : null;
  (usdaData?.foods || []).forEach(food => {
    add(food.brandOwner || food.brandName, food.description || query, "USDA");
  });

  return results;
}
// Build one suggestion row item
function buildSuggestionItem(name, source, isLocal, query, onSelect) {
  const item = document.createElement("div");
  item.className = "autocomplete-item";
  const suggestion = typeof name === "object" ? name : { name, source, company: "" };
  const productName = suggestion.name || "";
  const companyName = suggestion.company || "";
  const itemSource = suggestion.source || source;

  // Highlight matched part
  const q = query.toLowerCase();
  const idx = productName.toLowerCase().indexOf(q);
  let nameHtml = escapeHtml(productName);
  if (idx >= 0) {
    nameHtml =
      escapeHtml(productName.slice(0, idx)) +
      `<strong>${escapeHtml(productName.slice(idx, idx + q.length))}</strong>` +
      escapeHtml(productName.slice(idx + q.length));
  }

  // Source badge
  const badgeStyle = isLocal
    ? "background:#e8f5e9;color:#2e7d32;"
    : itemSource === "USDA"
    ? "background:#e3f2fd;color:#1565c0;"
    : "background:#fff3e0;color:#e65100;";
  const badgeLabel = isLocal ? "\uD83D\uDCE6 Local" : itemSource;

  item.innerHTML = `
    <span class="ac-name">
      <span class="ac-title">${nameHtml}</span>
      ${companyName ? `<span class="ac-company">${escapeHtml(companyName)}</span>` : ""}
    </span>
    <span class="ac-badge" style="${badgeStyle}">${badgeLabel}</span>
  `;

  addTapSelectHandler(item, () => onSelect(suggestion));

  return item;
}

function addTapSelectHandler(item, onTap) {
  let startX = 0;
  let startY = 0;
  let moved = false;
  let pointerId = null;

  item.addEventListener("pointerdown", (e) => {
    if (e.pointerType === "mouse") {
      e.preventDefault();
      onTap();
      return;
    }

    startX = e.clientX;
    startY = e.clientY;
    moved = false;
    pointerId = e.pointerId;
  });

  item.addEventListener("pointermove", (e) => {
    if (pointerId !== e.pointerId) return;
    if (Math.abs(e.clientX - startX) > 8 || Math.abs(e.clientY - startY) > 8) {
      moved = true;
    }
  });

  item.addEventListener("pointerup", (e) => {
    if (pointerId !== e.pointerId) return;
    pointerId = null;
    if (moved) return;
    e.preventDefault();
    onTap();
  });

  item.addEventListener("pointercancel", () => {
    pointerId = null;
    moved = false;
  });
}

// \u2500\u2500 Global dropdown touch tracker \u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500
// Tracks whether ANY dropdown is being scrolled/touched on mobile.
// Using document-level touchmove lets us detect scrolling inside
// a dropdown even when the input loses focus.
let _activeDropdown = null;
let _dropdownScrolling = false;
let _touchStartY = 0;

document.addEventListener("touchstart", (e) => {
  _touchStartY = e.touches[0].clientY;
  _dropdownScrolling = false;
  // Check if touch is inside an open dropdown
  const target = e.target.closest(".autocomplete-dropdown");
  _activeDropdown = target || null;
}, { passive: true });

document.addEventListener("touchmove", (e) => {
  if (_activeDropdown) {
    // User is scrolling inside a dropdown \u2014 mark as scrolling
    const dy = Math.abs(e.touches[0].clientY - _touchStartY);
    if (dy > 5) _dropdownScrolling = true;
  }
}, { passive: true });

document.addEventListener("touchend", (e) => {
  // If it was a tap (not scroll), let pointerdown handle the selection
  // Reset after a short delay
  setTimeout(() => {
    _activeDropdown = null;
    _dropdownScrolling = false;
  }, 350);
}, { passive: true });

function _shouldHideDropdown(dropdownEl) {
  // Don't hide if user is scrolling this specific dropdown
  return !(_activeDropdown === dropdownEl && _dropdownScrolling);
}

// Smart autocomplete: local results instantly + API suggestions after debounce
function setupAutocomplete(inputEl, dropdownEl, onAutocompleted) {
  if (!inputEl || !dropdownEl) return;

  let currentQuery = "";
  let loaderEl = null;

  // Desktop: prevent blur when clicking inside dropdown
  dropdownEl.addEventListener("mousedown", e => e.preventDefault());

  function onSelect(suggestion) {
    const selected = typeof suggestion === "object" ? suggestion : { name: suggestion };
    inputEl.value = selected.name || "";
    dropdownEl.innerHTML = "";
    dropdownEl.style.display = "none";
    currentQuery = "";
    inputEl.dispatchEvent(new Event("autocompleted"));
    if (typeof onAutocompleted === "function") onAutocompleted(selected);
  }

  function addLoader() {
    loaderEl = document.createElement("div");
    loaderEl.className = "ac-loader";
    loaderEl.innerHTML = `<span>🌐</span> Searching online databases…`;
    dropdownEl.appendChild(loaderEl);
    dropdownEl.style.display = "block";
  }

  function removeLoader() {
    if (loaderEl && loaderEl.parentNode) loaderEl.parentNode.removeChild(loaderEl);
    loaderEl = null;
  }

  function renderLocalMatches(query) {
    const matches = Object.keys(foodNutritionData)
      .filter(k => k.toLowerCase().includes(query.toLowerCase()))
      .slice(0, 6);
    matches.forEach(name =>
      dropdownEl.appendChild(buildSuggestionItem(
        { name, source: "local", company: findCompanyForProduct(name) },
        "local",
        true,
        query,
        onSelect
      ))
    );
    return matches.length;
  }

  const debouncedAPI = debounce(async (query) => {
    if (query !== currentQuery || query.length < 3) return;
    addLoader();
    const apiItems = await fetchSuggestionsFromAPIs(query);
    if (query !== currentQuery) { removeLoader(); return; }
    removeLoader();
    apiItems.slice(0, 5).forEach(item =>
      dropdownEl.appendChild(buildSuggestionItem(item, item.source, false, query, onSelect))
    );
    if (dropdownEl.children.length > 0) dropdownEl.style.display = "block";
    else dropdownEl.style.display = "none";
  }, 380);

  inputEl.addEventListener("input", async () => {
    currentQuery = inputEl.value.trim();
    dropdownEl.innerHTML = "";
    dropdownEl.style.display = "none";
    if (currentQuery.length < 3) return;

    await ensureSqlDatabaseReady();
    if (currentQuery !== inputEl.value.trim()) return;

    const localCount = renderLocalMatches(currentQuery);
    if (localCount > 0) dropdownEl.style.display = "block";
    // Always fetch from APIs (even if local results exist) — they may have more/better results
    debouncedAPI(currentQuery);
  });

  // \u2500\u2500 Blur: only hide if user isn't scrolling the dropdown \u2500\u2500
  inputEl.addEventListener("blur", () => {
    setTimeout(() => {
      if (_shouldHideDropdown(dropdownEl)) {
        dropdownEl.style.display = "none";
      }
    }, 200);
  });

  inputEl.addEventListener("focus", () => {
    if (currentQuery.length >= 3 && dropdownEl.children.length > 0) {
      dropdownEl.style.display = "block";
    }
  });
}

// Company autocomplete \u2014 only opens when company field is focused/clicked
function setupCompanyAutocomplete(inputEl, dropdownEl) {
  if (!inputEl || !dropdownEl) return;

  let isOpen = false;
  let allRenderedItems = []; // holds { el, nameLower } for fast filtering

  // Prevent blur firing before pointerdown on desktop mouse click
  dropdownEl.addEventListener("mousedown", e => e.preventDefault());

  function selectCompany(name) {
    inputEl.value = name;
    closeDropdown();
  }

  function closeDropdown() {
    isOpen = false;
    dropdownEl.innerHTML = "";
    dropdownEl.style.display = "none";
    allRenderedItems = [];
  }

  function buildCompanyItem(name, badgeHtml) {
    const item = document.createElement("div");
    item.className = "autocomplete-item";
    item.innerHTML =
      `<span class="ac-name"><span class="company-icon">\uD83C\uDFED</span> ${escapeHtml(name)}</span>` +
      badgeHtml;
    addTapSelectHandler(item, () => selectCompany(name));
    return item;
  }

  function applyFilter() {
    const q = inputEl.value.trim().toLowerCase();
    allRenderedItems.forEach(({ el, nameLower }) => {
      el.style.display = (!q || nameLower.includes(q)) ? "" : "none";
    });
  }

  async function openDropdown() {
    await ensureSqlDatabaseReady();
    isOpen = true;
    dropdownEl.innerHTML = "";
    allRenderedItems = [];

    const productQuery = (document.getElementById("productName")?.value || "").trim().toLowerCase();

    if (!productQuery) {
      // No product typed \u2014 show all local companies
      Object.keys(companyData).forEach(name => {
        const count = companyData[name].length;
        const badge = `<span class="ac-badge" style="background:#f3e5f5;color:#6a1b9a;">${count} product${count > 1 ? 's' : ''}</span>`;
        const el = buildCompanyItem(name, badge);
        allRenderedItems.push({ el, nameLower: name.toLowerCase() });
        dropdownEl.appendChild(el);
      });
      dropdownEl.style.display = "block";
      applyFilter();
      return;
    }

    // Product typed \u2014 show matching local companies instantly
    const localMatches = Object.keys(companyData).filter(company =>
      companyData[company].some(p => p.toLowerCase().includes(productQuery))
    );
    const seenNames = new Set(localMatches.map(n => n.toLowerCase()));

    localMatches.forEach(name => {
      const matchProd = companyData[name].find(p => p.toLowerCase().includes(productQuery));
      const label = matchProd
        ? (matchProd.length > 16 ? matchProd.slice(0, 16) + '\u2026' : matchProd)
        : `${companyData[name].length} products`;
      const badgeStyle = matchProd
        ? `background:#e8f5e9;color:#2e7d32;`
        : `background:#f3e5f5;color:#6a1b9a;`;
      const badge = `<span class="ac-badge" style="${badgeStyle}font-size:10px;max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${escapeHtml(label)}</span>`;
      const el = buildCompanyItem(name, badge);
      allRenderedItems.push({ el, nameLower: name.toLowerCase() });
      dropdownEl.appendChild(el);
    });

    // Loader for API fetch
    const loader = document.createElement("div");
    loader.className = "ac-loader";
    loader.innerHTML = `<span>\uD83C\uDF10</span> Finding more companies\u2026`;
    dropdownEl.appendChild(loader);
    dropdownEl.style.display = "block";
    applyFilter();

    const apiCompanies = await fetchCompanySuggestionsFromAPIs(productQuery);

    // Discard if field was closed while fetching
    if (!isOpen) return;
    if (loader.parentNode) loader.parentNode.removeChild(loader);

    apiCompanies.forEach(({ name, product, source }) => {
      if (!name || seenNames.has(name.toLowerCase())) return;
      seenNames.add(name.toLowerCase());
      const label = product
        ? (product.length > 16 ? product.slice(0, 16) + '\u2026' : product)
        : source;
      const badge = `<span class="ac-badge" style="background:#fff3e0;color:#e65100;font-size:10px;max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">\uD83C\uDF10 ${escapeHtml(label)}</span>`;
      const el = buildCompanyItem(name, badge);
      allRenderedItems.push({ el, nameLower: name.toLowerCase() });
      dropdownEl.appendChild(el);
    });

    if (dropdownEl.children.length === 0) {
      dropdownEl.style.display = "none";
    } else {
      applyFilter();
    }
  }

  // Open ONLY when this field is focused/clicked
  inputEl.addEventListener("focus", () => {
    openDropdown();
  });

  // Filter already-rendered list as user types \u2014 no re-fetch
  inputEl.addEventListener("input", () => {
    applyFilter();
  });

  // Close on blur (both desktop and mobile)
  inputEl.addEventListener("blur", () => {
    setTimeout(() => {
      if (_shouldHideDropdown(dropdownEl)) closeDropdown();
    }, 200);
  });
}

// \u2500\u2500 Init autocomplete on all three inputs \u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500
setupAutocomplete(
  document.getElementById("headerSearch"),
  document.getElementById("headerSuggestions"),
  /* onAutocompleted */ () => runHeaderSearch()
);
setupAutocomplete(
  document.getElementById("productName"),
  document.getElementById("productSuggestions"),
  async (selected) => {
    const companyInput = document.getElementById("companyName");
    if (!companyInput || companyInput.value.trim()) return;

    const productName = selected.name || selected;

    // Step 1: company came directly from the suggestion (API suggestion)
    if (selected.company) {
      companyInput.value = selected.company;
      rememberCompanyProduct(selected.company, productName);
      return;
    }

    // Step 2: check local companyData (loaded from MySQL bootstrap)
    const localCompany = findCompanyForProduct(productName);
    if (localCompany) {
      companyInput.value = localCompany;
      return;
    }

    // Step 3: ask MySQL directly
    try {
      const res = await fetch(`api.php?action=search&q=${encodeURIComponent(productName)}`);
      const data = await res.json();
      if (data.companyName && !companyInput.value.trim()) {
        companyInput.value = data.companyName;
        rememberCompanyProduct(data.companyName, productName);
        return;
      }
    } catch (e) { /* MySQL unreachable, fall through */ }

    // Step 4: search all external APIs (OFF, USDA, Datakick)
    try {
      const apiResult = await searchThreeAPIs(productName);
      if (apiResult && apiResult.company && !companyInput.value.trim()) {
        companyInput.value = apiResult.company;
        rememberCompanyProduct(apiResult.company, productName);
        // Save company to MySQL so next time it's found in Step 3
        if (foodNutritionData[productName]) {
          saveProductToSql(productName, foodNutritionData[productName], "local", "", apiResult.company);
        }
      }
    } catch (e) { /* all APIs failed */ }
  }
);
setupCompanyAutocomplete(
  document.getElementById("companyName"),
  document.getElementById("companySuggestions")
);
setupAutocomplete(
  document.getElementById("compareFood1"),
  document.getElementById("compareSuggestions1"),
  () => {}
);
setupAutocomplete(
  document.getElementById("compareFood2"),
  document.getElementById("compareSuggestions2"),
  () => {}
);

const verdictConfig = {
  safe:    { emoji: "\u2705", label: "Safe to Consume",        color: "#2e7d32", bg: "#e8f5e9" },
  caution: { emoji: "\u26A0\uFE0F", label: "Consume with Caution",  color: "#e65100", bg: "#fff3e0" },
  danger:  { emoji: "\u274C", label: "Not Recommended",         color: "#b71c1c", bg: "#ffebee" }
};

function getVerdict(nutrition, limits) {
  let verdict = "safe";
  const reasons = [];
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

function showLoading(message) {
  document.getElementById("result").innerHTML = `
    <div class="ai-loading">
      <div class="spinner"></div>
      <span>${message}</span>
    </div>`;
}

function showResultCard(verdictObj, age, productName, aiUsed, aiFound, nutritionObj = null) {
  const { verdict, reasons } = verdictObj;
  const cfg = verdictConfig[verdict];
  const sourceBadge = aiUsed
    ? (aiFound
        ? `<span class="source-badge local">📦 MySQL Database</span>`
        : `<span class="source-badge local">📦 MySQL Database</span>`)
    : `<span class="source-badge local">\uD83D\uDCE6 SQL Database</span>`;

  const reasonHtml = reasons.length > 0
    ? `<div class="verdict-reasons">${reasons.map(r => `<span class="reason-tag">${r}</span>`).join("")}</div>`
    : `<div class="verdict-reasons"><span class="reason-tag safe-tag">All nutrients within range</span></div>`;

  document.getElementById("result").innerHTML = `
    <div class="verdict-card" style="background:${cfg.bg}; border-left:5px solid ${cfg.color};">
      <div class="verdict-emoji">${cfg.emoji}</div>
      <div class="verdict-text">
        <strong style="color:${cfg.color};">${cfg.label}</strong>
        <small>Age: ${age} years &nbsp;|&nbsp; ${escapeHtml(productName)}</small>
        ${reasonHtml}
        ${sourceBadge}
        <button onclick="reportFoodData('${escapeHtml(productName)}')" style="margin-top:8px; background:none; border:none; color:#d32f2f; cursor:pointer; font-size:12px; text-decoration:underline;">
          🚩 Report Incorrect Data
        </button>
      </div>
    </div>`;
    
  if (nutritionObj) {
    checkAlternatives(verdict, productName, nutritionObj);
  }
}

async function reportFoodData(productName) {
  try {
    const res = await fetch('api.php?action=report_food', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ name: productName })
    });
    if (res.ok) {
      alert("Thanks for reporting! Our admin team has been notified and will verify this product's data.");
    } else {
      alert("Error reporting data. Please try again.");
    }
  } catch (e) {
    alert("Network error. Please try again.");
  }
}

async function checkAlternatives(verdict, productName, nutrition) {
  if (verdict === "safe") return; 
  
  try {
    const res = await fetch(`api.php?action=alternatives&productName=${encodeURIComponent(productName)}&sugar=${nutrition.sugar || 0}&salt=${nutrition.salt || 0}`);
    if (!res.ok) return;
    const alts = await res.json();
    
    if (alts && alts.length > 0) {
      let html = `<div class="alternatives-section" style="margin-top: 15px; background: #f0fdf4; padding: 15px; border-radius: 8px; border: 1px solid #bbf7d0; animation: fadeIn 0.5s ease;">
        <h4 style="margin: 0 0 10px 0; color: #166534; font-size: 16px;">🌿 Healthier Alternatives</h4>
        <div style="display: flex; gap: 10px; flex-wrap: wrap;">`;
        
      for (const alt of alts) {
        // Fetch real values from external APIs as requested by user
        const apiRes = await searchThreeAPIs(alt.productName);
        const displayNutrition = (apiRes && apiRes.nutrition) ? apiRes.nutrition : alt.nutrition;
        const displayCompany = (apiRes && apiRes.company) ? apiRes.company : (alt.companyName || 'Unknown Brand');

        html += `<div style="background: white; padding: 10px; border-radius: 6px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); flex: 1; min-width: 150px;">
          <strong style="display:block; font-size:14px; color:#374151;">${escapeHtml(alt.productName)}</strong>
          <small style="color:#6b7280; display:block; margin-bottom:5px;">${escapeHtml(displayCompany)}</small>
          <div style="font-size:12px; color:#047857;">Sugar: ${displayNutrition.sugar}g | Salt: ${displayNutrition.salt}mg</div>
          <button type="button" onclick="document.getElementById('productName').value='${escapeHtml(alt.productName)}'; document.getElementById('searchForm').dispatchEvent(new Event('submit', {cancelable: true, bubbles: true}));" style="margin-top: 8px; background: #22c55e; color: white; border: none; padding: 5px 10px; border-radius: 4px; cursor: pointer; font-size: 12px; width: 100%; transition: 0.2s;">Check This Item</button>
        </div>`;
      }
      html += `</div></div>`;
      
      const resCard = document.querySelector(".verdict-card");
      if (resCard) {
        resCard.insertAdjacentHTML('afterend', html);
      }
    }
  } catch(e) {
    console.error("Failed to fetch alternatives", e);
  }
}

let chartInstance = null;

function showNutrition(nutrition, ageGroup, mode) {
  const section = document.getElementById("nutritionSection");
  section.style.display = "block";
  section.offsetHeight; // force reflow

  const table = document.getElementById("nutritionTable");
  table.innerHTML = "";
  const thead = table.closest("table").querySelector("thead");
  const entries = getPublicNutritionEntries(nutrition);

  if (entries.length === 0) {
    thead.innerHTML = `<tr><th>Nutrient</th><th>Value</th></tr>`;
    table.innerHTML = `<tr><td colspan="2">No public nutrition values available.</td></tr>`;
    document.querySelector(".chart-wrapper").style.display = "none";
    return;
  }

  if (mode === "search") {
    thead.innerHTML = `<tr><th>Nutrient</th><th>Value (per 100g)</th></tr>`;
    entries.forEach(entry => {
      table.innerHTML += `<tr><td>${escapeHtml(entry.meta.label)}</td><td>${escapeHtml(formatNutritionValue(entry))}</td></tr>`;
    });
    document.querySelector(".chart-wrapper").style.display = "none";
    return;
  }

  // Compare mode (form submit)
  const limits = ageGroup ? ageLimits[ageGroup] : {};
  thead.innerHTML = `<tr><th>Nutrient</th><th>In Product</th><th>Recommended</th></tr>`;
  entries.forEach(entry => {
    const key = entry.key;
    const over = limits[key] && nutrition[key] > limits[key];
    const unit = entry.meta.unit || "";
    const recommended = limits[key] !== undefined ? `${limits[key]} ${unit}` : "—";
    table.innerHTML += `
      <tr ${over ? 'class="over-limit"' : ""}>
        <td>${escapeHtml(entry.meta.label)}</td>
        <td ${over ? 'style="color:#b71c1c;font-weight:bold;"' : ""}>${escapeHtml(formatNutritionValue(entry))}</td>
        <td>${escapeHtml(recommended.trim())}</td>
      </tr>`;
  });

  const chartEntries = entries.filter(entry => Number.isFinite(Number(entry.value)));
  document.querySelector(".chart-wrapper").style.display = chartEntries.length ? "block" : "none";
  if (!chartEntries.length) {
    section.scrollIntoView({ behavior: "smooth", block: "start" });
    return;
  }
  const ctx = document.getElementById("nutritionChart").getContext("2d");
  if (chartInstance) chartInstance.destroy();
  
  // Create beautiful gradients
  const gradientProduct = ctx.createLinearGradient(0, 0, 0, 400);
  gradientProduct.addColorStop(0, 'rgba(255, 152, 0, 0.9)'); // Deep Orange
  gradientProduct.addColorStop(1, 'rgba(255, 87, 34, 0.6)'); // Vibrant Red-Orange

  const gradientRecommended = ctx.createLinearGradient(0, 0, 0, 400);
  gradientRecommended.addColorStop(0, 'rgba(76, 175, 80, 0.9)'); // Fresh Green
  gradientRecommended.addColorStop(1, 'rgba(46, 125, 50, 0.6)'); // Dark Green

  chartInstance = new Chart(ctx, {
    type: "bar",
    data: {
      labels: chartEntries.map(entry => entry.meta.label),
      datasets: [
        { 
          label: "In Product",  
          data: chartEntries.map(entry => Number(entry.value)), 
          backgroundColor: gradientProduct,
          borderRadius: 8,
          borderWidth: 0,
          hoverBackgroundColor: 'rgba(255, 87, 34, 1)'
        },
        { 
          label: "Recommended Limit", 
          data: chartEntries.map(entry => limits[entry.key] ?? 0), 
          backgroundColor: gradientRecommended,
          borderRadius: 8,
          borderWidth: 0,
          hoverBackgroundColor: 'rgba(46, 125, 50, 1)'
        }
      ]
    },
    options: { 
      responsive: true, 
      maintainAspectRatio: false,
      plugins: {
        legend: {
          position: 'bottom',
          labels: {
            font: { family: "'Inter', sans-serif", size: 14, weight: '500' },
            padding: 20
          }
        },
        tooltip: {
          backgroundColor: 'rgba(0,0,0,0.8)',
          titleFont: { size: 16, family: "'Inter', sans-serif" },
          bodyFont: { size: 14, family: "'Inter', sans-serif" },
          padding: 15,
          cornerRadius: 10,
          displayColors: true,
          boxPadding: 5
        }
      },
      scales: {
        y: {
          beginAtZero: true,
          grid: { color: 'rgba(0,0,0,0.05)', borderDash: [5, 5] },
          ticks: { font: { family: "'Inter', sans-serif" } }
        },
        x: {
          grid: { display: false },
          ticks: { font: { family: "'Inter', sans-serif", weight: 'bold' } }
        }
      },
      animation: {
        duration: 1500,
        easing: 'easeOutQuart'
      }
    }
  });

  section.scrollIntoView({ behavior: "smooth", block: "start" });
}

// \u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550
//  MAIN FORM SUBMIT
// \u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550

document.getElementById("foodForm").addEventListener("submit", async function (e) {
  e.preventDefault();

  const productName = document.getElementById("productName").value.trim();
  const companyName = document.getElementById("companyName").value.trim();
  const dob         = document.getElementById("dob").value;

  if (!productName || !dob) { alert("Please enter product name and date of birth."); return; }

  const age = calculateAge(dob);
  if (age < 0) { alert("Please enter a valid Date of Birth."); return; }

  // ── Show loading immediately so there is always visual feedback ──────────────
  showLoading("\u23F3 Verifying food safety\u2026");

  await ensureSqlDatabaseReady();

  const ageGroup = getAgeGroupFromAge(age);

  // 1. Local DB (instant — product already in MySQL / local cache)
  const localKey = findInLocalDB(productName);
  if (localKey) {
    const nutrition = foodNutritionData[localKey];
    const localCompany = findCompanyForProduct(localKey);

    if (companyName) {
      // BUG FIX: user entered a company name — always save it to MySQL so
      // it persists for future searches and the admin company count increments.
      rememberCompanyProduct(companyName, localKey);
      saveProductToSql(localKey, nutrition, "local", "", companyName);
    } else if (localCompany) {
      document.getElementById("companyName").value = localCompany;
    } else {
      // No company anywhere yet — try MySQL first, then external APIs (fire-and-forget)
      fetch(`api.php?action=search&q=${encodeURIComponent(localKey)}`)
        .then(r => r.json())
        .then(async data => {
          const companyEl = document.getElementById("companyName");
          if (data.companyName && !companyEl.value.trim()) {
            companyEl.value = data.companyName;
            rememberCompanyProduct(data.companyName, localKey);
            saveProductToSql(localKey, foodNutritionData[localKey], "local", "", data.companyName);
          } else if (!companyEl.value.trim()) {
            const apiResult = await searchThreeAPIs(localKey);
            if (apiResult && apiResult.company && !companyEl.value.trim()) {
              companyEl.value = apiResult.company;
              rememberCompanyProduct(apiResult.company, localKey);
              saveProductToSql(localKey, foodNutritionData[localKey], "local", "", apiResult.company);
            }
          }
        })
        .catch(async () => {
          const companyEl = document.getElementById("companyName");
          if (!companyEl.value.trim()) {
            const apiResult = await searchThreeAPIs(localKey);
            if (apiResult && apiResult.company) {
              companyEl.value = apiResult.company;
              rememberCompanyProduct(apiResult.company, localKey);
              saveProductToSql(localKey, foodNutritionData[localKey], "local", "", apiResult.company);
            }
          }
        });
    }
    showResultCard(getVerdict(nutrition, ageLimits[ageGroup]), age, localKey, false, false, nutrition);
    showNutrition(nutrition, ageGroup, "compare");
    return;
  }

  // 2. Product not in local DB — fetch from external APIs
  showLoading("🔍 Searching online databases… (this may take a few seconds)");
  try {
    const apiResult = await searchThreeAPIs(productName);
    if (apiResult) {
      // BUG FIX: if the API didn't return a company but the user typed one, use it
      const finalCompany = apiResult.company || companyName;
      const finalNutrition = apiResult.nutrition || { calories: 0, sugar: 0, salt: 0, fat: 0 };
      foodNutritionData[apiResult.name] = finalNutrition;
      persistScannedProduct(apiResult.name, finalNutrition, apiResult.source, "", finalCompany);
      // Update in-memory company map so autocomplete reflects the new company immediately
      if (finalCompany) rememberCompanyProduct(finalCompany, apiResult.name);
      // Fill the company input if it is still empty
      if (finalCompany && !document.getElementById("companyName").value.trim()) {
        document.getElementById("companyName").value = finalCompany;
      }

      showResultCard(getVerdict(finalNutrition, ageLimits[ageGroup]), age, apiResult.name, false, false, finalNutrition);
      document.getElementById("result").innerHTML +=
        `<span class="source-badge local" style="background:#e8f5e9;color:#2e7d32;margin-top:8px;display:inline-block;">
          🌐 ${escapeHtml(apiResult.source)}
        </span>`;
      showNutrition(finalNutrition, ageGroup, "compare");
      return;
    }

    // Product not found in ANY database — truly last resort
    document.getElementById("nutritionSection").style.display = "none";
    document.getElementById("result").innerHTML = `
      <div class="verdict-card" style="background:#fff8e1; border-left:5px solid #f9a825;">
        <div class="verdict-emoji">❓</div>
        <div class="verdict-text">
          <strong style="color:#f57f17;">Product Not Found or APIs Offline</strong>
          <small>No data found for <em>${escapeHtml(productName)}</em>. This may happen if the product doesn't exist, or if external databases (OpenFoodFacts, USDA) are temporarily rate-limited/offline. Try scanning the barcode or try again later.</small>
        </div>
      </div>`;

  } catch (err) {
    console.error("Form submit error:", err);
    document.getElementById("result").innerHTML = `
      <div class="verdict-card" style="background:#ffebee; border-left:5px solid #b71c1c;">
        <div class="verdict-emoji">⚠️</div>
        <div class="verdict-text">
          <strong style="color:#b71c1c;">Lookup Failed</strong>
          <small>${escapeHtml(err.message)}</small>
        </div>
      </div>`;
  }
});

// \u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550
//  BARCODE SCANNER
// \u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550

let html5QrCode = null;

const startScanBtn = document.getElementById("startScan");
const closeScanBtn = document.getElementById("closeScan");
const scannerBox   = document.getElementById("scannerBox");
const scanStatus   = document.getElementById("scanStatus");

async function stopScanner() {
  if (html5QrCode) {
    const instance = html5QrCode;
    html5QrCode = null;
    try { await instance.stop(); } catch (e) { /* already stopped */ }
    try { instance.clear(); }   catch (e) { /* ignore */ }
  }
}

async function onBarcodeDetected(decodedText) {
  await stopScanner();
  const scannedBarcode = getBarcodeFromScan(decodedText);

  scanStatus.style.color = "#555";
  scanStatus.textContent = `\uD83D\uDD0D Barcode: ${scannedBarcode} \u2014 Searching databases\u2026`;
  scannerBox.style.display = "block";

  try {
    const result = await resolveBarcode(scannedBarcode);

    if (result && result.productName) {
      document.getElementById("productName").value = result.productName;
      document.getElementById("companyName").value = result.companyName || "";

      if (result.nutrition && result.source !== "Local") {
        const hasData = hasNutritionData(result.nutrition);
        if (hasData) {
          foodNutritionData[result.productName] = result.nutrition;
          persistScannedProduct(result.productName, result.nutrition, result.source || "barcode", result.barcode || scannedBarcode, result.companyName || "");
        }
      }

      scanStatus.style.color = "#2e7d32";
      const src = result.source ? ` (via ${result.source})` : "";
      scanStatus.textContent =
        `\u2705 Found: ${result.productName}${result.companyName ? " by " + result.companyName : ""}${src} \u2014 Fill DOB and tap Verify!`;

    } else {
      document.getElementById("productName").value = "";
      scanStatus.style.color = "#e65100";
      scanStatus.textContent = `\u26A0\uFE0F Product not found for barcode ${escapeHtml(scannedBarcode)}. Enter the product name manually.`;
    }
  } catch (err) {
    scanStatus.style.color = "#b71c1c";
    scanStatus.textContent = `\u274C Error: ${err.message}`;
  }

  setTimeout(() => { scannerBox.style.display = "none"; }, 3500);
}

async function startScanner() {
  await stopScanner();

  if (location.protocol !== "https:" && location.hostname !== "localhost") {
    scanStatus.style.color = "#b71c1c";
    scanStatus.textContent = "\u274C Camera requires HTTPS. Please open the site via https://";
    scannerBox.style.display = "block";
    return;
  }

  if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
    scanStatus.style.color = "#b71c1c";
    scanStatus.textContent = "\u274C Camera not supported. Use Chrome or Safari on your phone.";
    scannerBox.style.display = "block";
    return;
  }

  scannerBox.style.display = "block";
  scanStatus.textContent   = "\uD83D\uDCF7 Requesting camera permission\u2026";
  scanStatus.style.color   = "#555";

  const config = { fps: 10, qrbox: { width: 250, height: 150 }, aspectRatio: 1.5 };

  html5QrCode = new Html5Qrcode("qr-reader");
  html5QrCode.start(
    { facingMode: "environment" },
    config,
    onBarcodeDetected,
    () => {}
  ).then(() => {
    scanStatus.textContent = "\uD83D\uDCF7 Point camera at the barcode on the food packet\u2026";
    scanStatus.style.color = "#555";
  }).catch(async (err) => {
    await stopScanner();
    scanStatus.style.color = "#b71c1c";
    const msg = String(err);
    if (msg.includes("Permission") || msg.includes("denied") || msg.includes("NotAllowed")) {
      scanStatus.textContent = "\u274C Camera denied. Go to browser Settings \u2192 Camera \u2192 Allow, then try again.";
    } else if (msg.includes("NotFound") || msg.includes("DevicesNotFound")) {
      scanStatus.textContent = "\u274C No camera found on this device.";
    } else if (msg.includes("NotReadable") || msg.includes("TrackStart")) {
      scanStatus.textContent = "\u274C Camera is in use by another app. Close it and try again.";
    } else {
      scanStatus.textContent = `\u274C Camera error: ${msg}`;
    }
  });
}

if (startScanBtn) startScanBtn.addEventListener("click", startScanner);

if (closeScanBtn) {
  closeScanBtn.addEventListener("click", async () => {
    await stopScanner();
    scannerBox.style.display = "none";
    scanStatus.textContent = "";
  });
}

// Stop camera when user switches tabs or minimises
document.addEventListener("visibilitychange", async () => {
  if (document.hidden && html5QrCode) {
    await stopScanner();
    if (scannerBox) {
      scanStatus.style.color   = "#e65100";
      scanStatus.textContent   = "\uD83D\uDCF7 Camera paused. Tap 'Scan' again to resume.";
    }
  }
});

// \u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550
//  HEADER SEARCH
// \u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550

async function runHeaderSearch() {
  await ensureSqlDatabaseReady();
  const searchValue = document.getElementById("headerSearch").value.trim();
  if (!searchValue) return;

  // Local first
  const localKey = findInLocalDB(searchValue);
  if (localKey) {
    document.querySelector(".form-section").scrollIntoView({ behavior: "smooth" });
    document.getElementById("nutritionSection").style.display = "block";
    showNutrition(foodNutritionData[localKey], null, "search");
    document.getElementById("result").innerHTML = `<span class="source-badge local">\uD83D\uDCE6 SQL Database</span>`;
    return;
  }

  document.querySelector(".form-section").scrollIntoView({ behavior: "smooth" });
  showLoading("🔍 Searching online databases… (this may take a few seconds)");

  try {
    const apiResult = await searchThreeAPIs(searchValue);
    if (apiResult) {
      const finalNutrition = apiResult.nutrition || { calories: 0, sugar: 0, salt: 0, fat: 0 };
      foodNutritionData[apiResult.name] = finalNutrition;
      persistScannedProduct(apiResult.name, finalNutrition, apiResult.source, "", apiResult.company || "");
      // Update in-memory company map so it shows in dropdowns/admin immediately
      if (apiResult.company) rememberCompanyProduct(apiResult.company, apiResult.name);
      document.getElementById("nutritionSection").style.display = "block";
      showNutrition(finalNutrition, null, "search");
      document.getElementById("result").innerHTML =
        `<span class="source-badge local" style="background:#e8f5e9;color:#2e7d32;">🌐 ${escapeHtml(apiResult.source)}</span>`;
      return;
    }

    // Product not found in any database
    document.getElementById("nutritionSection").style.display = "none";
    document.getElementById("result").innerHTML = `
      <div class="verdict-card" style="background:#fff8e1; border-left:5px solid #f9a825;">
        <div class="verdict-emoji">❓</div>
        <div class="verdict-text">
          <strong style="color:#f57f17;">Product Not Found</strong>
          <small>No nutrition data found for <em>${escapeHtml(searchValue)}</em>. Try scanning the barcode instead.</small>
        </div>
      </div>`;

  } catch (err) {
    console.error("Header search error:", err);
    document.getElementById("result").innerHTML = `
      <div class="verdict-card" style="background:#ffebee; border-left:5px solid #b71c1c;">
        <div class="verdict-emoji">⚠️</div>
        <div class="verdict-text">
          <strong style="color:#b71c1c;">Search Failed</strong>
          <small>${escapeHtml(err.message)}</small>
        </div>
      </div>`;
  }}

document.getElementById("headerSearchBtn").addEventListener("click", runHeaderSearch);
document.getElementById("headerSearch").addEventListener("keydown", (e) => {
  if (e.key === "Enter") runHeaderSearch();
});

// \u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550
//  SECTION NAVIGATION
// \u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550

function setActiveSection(targetId) {
  document.querySelectorAll(".content-section").forEach(section => {
    section.classList.toggle("hide-section", section.id !== targetId);
  });
  window.scrollTo({ top: 0, behavior: "smooth" });
  document.querySelectorAll(".footer-links a[data-target]").forEach(a => {
    a.classList.toggle("active-link", a.getAttribute("data-target") === targetId);
  });
}

document.querySelectorAll(".footer-links a[data-target]").forEach(link => {
  link.addEventListener("click", event => {
    event.preventDefault();
    const targetId = link.getAttribute("data-target");
    if (targetId) setActiveSection(targetId);
  });
});

// Header logo \u2192 go home
const headerHome = document.getElementById("headerHome");
if (headerHome) {
  headerHome.addEventListener("click", async () => {
    document.getElementById("productName").value = "";
    document.getElementById("companyName").value = "";
    document.getElementById("dob").value = "";
    document.getElementById("result").innerHTML = "";
    document.getElementById("nutritionSection").style.display = "none";
    await stopScanner();
    if (scannerBox) scannerBox.style.display = "none";
    setActiveSection("food-safety");
  });
}

// Clear / reload button
const reloadBtn = document.getElementById("reloadBtn");
if (reloadBtn) {
  reloadBtn.addEventListener("click", async () => {
    document.getElementById("productName").value = "";
    document.getElementById("companyName").value = "";
    document.getElementById("dob").value = "";
    document.getElementById("result").innerHTML = "";
    document.getElementById("nutritionSection").style.display = "none";
    await stopScanner();
    if (scannerBox) scannerBox.style.display = "none";
  });
}

// Script is deferred \u2014 DOM is guaranteed ready at this point.
// DOMContentLoaded already fired before deferred scripts run, so we call directly:
setActiveSection("food-safety");
initSearchAnimation();

// \u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550
//  ANIMATED SEARCH PLACEHOLDER  (Flipkart-style)
// \u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550

const SEARCH_WORDS = [
  "Maggi 2-Minute Noodles...",
  "Cadbury Dairy Milk...",
  "Lays Magic Masala...",
  "Kurkure Masala Munch...",
  "Parle-G Biscuits...",
  "Bingo Mad Angles...",
  "KitKat...",
  "Haldiram Bhujia Sev...",
  "Britannia Good Day...",
  "Coca-Cola...",
  "Sunfeast Dark Fantasy...",
  "Red Bull Energy Drink...",
  "Frooti Mango Drink...",
  "Oreo Original...",
  "Amul Dark Chocolate..."
];

function initSearchAnimation() {
  const animEl  = document.getElementById("searchPlaceholderAnim");
  const wordEl  = document.getElementById("searchAnimWord");
  const inputEl = document.getElementById("headerSearch");
  if (!animEl || !wordEl || !inputEl) return;

  // Initially hide the overlay so CSS doesn't show stale state
  animEl.style.display = "none";

  let wordIdx    = 0;
  let intervalId = null;
  let running    = false;

  function showWord(idx) {
    wordEl.classList.remove("slide-in", "slide-out");
    wordEl.textContent = SEARCH_WORDS[idx];
    void wordEl.offsetWidth; // force reflow so animation restarts
    wordEl.classList.add("slide-in");
  }

  function cycleWord() {
    wordEl.classList.remove("slide-in");
    wordEl.classList.add("slide-out");
    setTimeout(() => {
      if (!running) return;
      wordIdx = (wordIdx + 1) % SEARCH_WORDS.length;
      wordEl.classList.remove("slide-out");
      showWord(wordIdx);
    }, 300);
  }

  function startAnim() {
    if (running) return;
    // Don't start if input has a value
    if (inputEl.value.trim()) return;
    running = true;
    animEl.style.display = "flex";
    showWord(wordIdx);
    intervalId = setInterval(cycleWord, 2600);
  }

  function stopAnim() {
    running = false;
    clearInterval(intervalId);
    intervalId = null;
    animEl.style.display = "none";
  }

  // Start on load with small delay so page is settled
  setTimeout(startAnim, 200);

  // Stop when user focuses (about to type)
  inputEl.addEventListener("focus", stopAnim);

  // Restart when user blurs with empty input
  inputEl.addEventListener("blur", () => {
    // Slightly longer delay than autocomplete hide (180ms) so order is safe
    setTimeout(() => {
      if (!inputEl.value.trim()) startAnim();
    }, 220);
  });

  // Stop/start as user types
  inputEl.addEventListener("input", () => {
    if (inputEl.value.trim()) stopAnim();
    else if (!running) startAnim();
  });

  // When a suggestion is selected, clear input & restart animation
  inputEl.addEventListener("autocompleted", () => {
    // autocomplete sets the value \u2014 we stop anim (value is set)
    stopAnim();
  });
}

// \u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550
//  HERO TYPEWRITER \u2014 types heading + paragraph, erases, loops
// \u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550

(function heroTypewriter() {
  const titleEl  = document.getElementById("heroTitleText");
  const subEl    = document.getElementById("heroSubText");
  const cursorEl = document.getElementById("typeCursor");
  if (!titleEl || !subEl || !cursorEl) return;

  // \u2500\u2500 Texts \u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500
  let HEADING = "Know What You Eat. Trust Your Health.";
  let SUB     = "Vitahar helps Indian citizens verify whether packaged food is safe and healthy for their age.";

  // \u2500\u2500 Timing (ms) \u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500
  const SPEED_TYPE_H   = 48;   // ms per character \u2014 heading (slightly slower, more impact)
  const SPEED_TYPE_P   = 26;   // ms per character \u2014 paragraph
  const SPEED_DELETE   = 16;   // ms per character \u2014 backspace (fast & snappy)
  const PAUSE_AFTER_H  = 320;  // pause between heading done & paragraph starts
  const PAUSE_FULL     = 1900; // pause when everything is typed \u2014 user reads
  const PAUSE_RESTART  = 480;  // pause when screen is blank before restarting

  // \u2500\u2500 Helpers \u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500
  const sleep = ms => new Promise(r => setTimeout(r, ms));

  async function type(el, text, speed) {
    for (let i = 1; i <= text.length; i++) {
      el.textContent = text.slice(0, i);
      await sleep(speed + (Math.random() * 12 - 6)); // tiny jitter \u2192 feels natural
    }
  }

  async function erase(el, speed) {
    const len = el.textContent.length;
    for (let i = len; i >= 0; i--) {
      el.textContent = el.textContent.slice(0, i);
      await sleep(speed);
    }
  }

  // \u2500\u2500 Main loop \u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500
  async function run() {
    while (true) {
      // 1. Type the heading
      await type(titleEl, HEADING, SPEED_TYPE_H);
      await sleep(PAUSE_AFTER_H);

      // 2. Type the paragraph
      await type(subEl, SUB, SPEED_TYPE_P);
      await sleep(PAUSE_FULL);

      // 3. Erase paragraph, then heading
      await erase(subEl, SPEED_DELETE);
      await erase(titleEl, SPEED_DELETE);
      await sleep(PAUSE_RESTART);
    }
  }

  // Give the page ~400ms to settle (hero fade-in etc.), pick up any
  // admin-edited hero text, then start
  (async function boot() {
    try {
      const content = await (window.VITAHAR_CONTENT_PROMISE || Promise.resolve({}));
      if (content.home_hero_title)    HEADING = content.home_hero_title;
      if (content.home_hero_subtitle) SUB     = content.home_hero_subtitle;
    } catch (e) { /* keep defaults */ }
    setTimeout(run, 400);
  })();
})();


const feedbackForm = document.getElementById("feedbackForm");
if (feedbackForm) {
  feedbackForm.addEventListener("submit", async event => {
    event.preventDefault();
    const name    = document.getElementById("feedbackName").value.trim();
    const email   = document.getElementById("feedbackEmail").value.trim();
    const message = document.getElementById("feedbackMessage").value.trim();
    const feedbackResult = document.getElementById("feedbackResult");
    const submitBtn = feedbackForm.querySelector('button[type="submit"]');
    const originalBtnText = submitBtn ? submitBtn.innerText : "Send Feedback";

    if (!name || !email || !message) {
      feedbackResult.style.color = "#b71c1c";
      feedbackResult.innerText = "Please complete all fields before sending feedback.";
      return;
    }

    // Email validation
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    if (!emailRegex.test(email)) {
      feedbackResult.style.color = "#b71c1c";
      feedbackResult.innerText = "Please enter a valid email address.";
      return;
    }

    if (submitBtn) {
      submitBtn.disabled = true;
      submitBtn.innerText = "Sending...";
    }
    
    feedbackResult.style.color = "#333";
    feedbackResult.innerText = "";

    try {
      const response = await fetch("send-feedback.php", {
        method: "POST",
        headers: {
          "Content-Type": "application/json"
        },
        body: JSON.stringify({ name, email, message })
      });

      const data = await response.json();

      if (response.ok && data.success) {
        feedbackResult.style.color = "#2e7d32";
        feedbackResult.innerText = "✓ Feedback sent successfully!";
        feedbackForm.reset();
      } else {
        feedbackResult.style.color = "#b71c1c";
        feedbackResult.innerText = data.error || "Unable to send feedback right now. Please try again later.";
      }
    } catch (error) {
      feedbackResult.style.color = "#b71c1c";
      feedbackResult.innerText = "Unable to send feedback right now. Please try again later.";
    } finally {
      if (submitBtn) {
        submitBtn.disabled = false;
        submitBtn.innerText = originalBtnText;
      }
    }
  });
}

// ══════════════════════════════════════════════════════════════
//  AI RECIPE GENERATOR
// ══════════════════════════════════════════════════════════════
const generateRecipeBtn = document.getElementById("generateRecipeBtn");
if (generateRecipeBtn) {
  generateRecipeBtn.addEventListener("click", async () => {
    const productName = document.getElementById("productName").value.trim();
    if (!productName) return;
    
    const recipeSection = document.getElementById("recipeSection");
    
    generateRecipeBtn.innerHTML = '<span style="display:inline-block; width:16px; height:16px; border:2px solid #fff; border-top:2px solid transparent; border-radius:50%; animation:spin 1s linear infinite; margin-right:8px;"></span> AI is cooking...';
    generateRecipeBtn.disabled = true;
    recipeSection.style.display = "none";
    
    try {
      const response = await fetch("generate-recipe.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ productName })
      });
      
      const data = await response.json();
      
      if (!response.ok || data.error) {
        throw new Error(data.error || "Failed to generate recipe.");
      }
      
      document.getElementById("recipeTitle").innerText = data.recipeName || "Healthy Custom Recipe";
      document.getElementById("recipeTime").innerText = data.prepTime || "15 mins";
      document.getElementById("recipeBenefits").innerText = data.healthBenefits || "Balanced nutrition.";
      
      const ingredientsList = document.getElementById("recipeIngredients");
      ingredientsList.innerHTML = "";
      if (data.ingredients && Array.isArray(data.ingredients)) {
        data.ingredients.forEach(ing => {
          const li = document.createElement("li");
          li.innerText = ing;
          ingredientsList.appendChild(li);
        });
      }
      
      const instructionsList = document.getElementById("recipeInstructions");
      instructionsList.innerHTML = "";
      if (data.instructions && Array.isArray(data.instructions)) {
        data.instructions.forEach(step => {
          const li = document.createElement("li");
          li.innerText = step;
          instructionsList.appendChild(li);
        });
      }
      
      recipeSection.style.display = "block";
      recipeSection.scrollIntoView({ behavior: "smooth", block: "start" });
      
    } catch (err) {
      alert("Recipe Generation Error: " + err.message);
    } finally {
      generateRecipeBtn.innerHTML = "✨ Generate Healthy AI Recipe";
      generateRecipeBtn.disabled = false;
    }
  });
}

// ── COMPARE MODAL LOGIC ──────────────────────────────────────────────────────────
function openCompareModal() {
  document.getElementById('compareModal').style.display = 'flex';
}
function closeCompareModal() {
  document.getElementById('compareModal').style.display = 'none';
}

async function runComparison() {
  const f1 = document.getElementById('compareFood1').value.trim();
  const f2 = document.getElementById('compareFood2').value.trim();
  
  if (!f1 || !f2) {
    alert("Please enter both food names to compare.");
    return;
  }
  
  document.getElementById('compareRes1').innerHTML = '<i>Searching...</i>';
  document.getElementById('compareRes2').innerHTML = '<i>Searching...</i>';
  document.getElementById('compareWinner').style.display = 'none';
  
  try {
    const fetchFood = async (foodName) => {
      // Fetch from real APIs first as requested by user
      const apiRes = await searchThreeAPIs(foodName);
      if (apiRes && apiRes.nutrition) {
        return { found: true, productName: apiRes.name, companyName: apiRes.company, nutrition: apiRes.nutrition };
      }
      // Fallback to local DB
      return await fetch(`api.php?action=search&q=${encodeURIComponent(foodName)}`).then(r => r.json());
    };

    const [res1, res2] = await Promise.all([
      fetchFood(f1),
      fetchFood(f2)
    ]);
    
    let score1 = 0, score2 = 0;
    
    if (res1 && res1.found) {
      document.getElementById('compareRes1').innerHTML = renderCompareNutrition(res1);
    } else {
      document.getElementById('compareRes1').innerHTML = '<span style="color:red">Not found anywhere.</span>';
    }
    
    if (res2.found) {
      document.getElementById('compareRes2').innerHTML = renderCompareNutrition(res2);
    } else {
      document.getElementById('compareRes2').innerHTML = '<span style="color:red">Not found in database.</span>';
    }
    
    if (res1.found && res2.found) {
      const n1 = res1.nutrition;
      const n2 = res2.nutrition;
      
      if (n1.sugar < n2.sugar) score1++; else if (n2.sugar < n1.sugar) score2++;
      if (n1.salt < n2.salt) score1++; else if (n2.salt < n1.salt) score2++;
      if (n1.fat < n2.fat) score1++; else if (n2.fat < n1.fat) score2++;
      
      const winnerEl = document.getElementById('compareWinner');
      winnerEl.style.display = 'block';
      if (score1 > score2) {
        winnerEl.style.background = '#e8f5e9';
        winnerEl.innerHTML = `<h3 style="margin:0; color:#2e7d32;">🏆 Winner: ${escapeHtml(res1.productName)}</h3><p style="margin:5px 0 0; color:#1b5e20;">It has overall better nutritional values (lower sugar/salt/fat).</p>`;
      } else if (score2 > score1) {
        winnerEl.style.background = '#e8f5e9';
        winnerEl.innerHTML = `<h3 style="margin:0; color:#2e7d32;">🏆 Winner: ${escapeHtml(res2.productName)}</h3><p style="margin:5px 0 0; color:#1b5e20;">It has overall better nutritional values (lower sugar/salt/fat).</p>`;
      } else {
        winnerEl.style.background = '#fff8e1';
        winnerEl.innerHTML = `<h3 style="margin:0; color:#f9a825;">🤝 It's a Tie!</h3><p style="margin:5px 0 0; color:#f57f17;">Both products have similar nutritional profiles.</p>`;
      }
    }
  } catch (e) {
    console.error(e);
    alert("Error comparing foods.");
  }
}

function renderCompareNutrition(data) {
  const n = data.nutrition;
  return `
    <strong>${escapeHtml(data.productName)}</strong><br>
    <small style="color:#666">${escapeHtml(data.companyName || 'Unknown Brand')}</small>
    <ul style="list-style:none; padding:0; margin-top:10px;">
      <li style="padding: 5px; border-bottom: 1px solid #eee;"><strong>Sugar:</strong> ${n.sugar}g</li>
      <li style="padding: 5px; border-bottom: 1px solid #eee;"><strong>Salt:</strong> ${n.salt}mg</li>
      <li style="padding: 5px; border-bottom: 1px solid #eee;"><strong>Fat:</strong> ${n.fat}g</li>
      <li style="padding: 5px;"><strong>Calories:</strong> ${n.calories} kcal</li>
    </ul>
  `;
}