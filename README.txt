# 🍟 Joshi Enterprise Gondal — Sales Management System

## 📌 Website Overview

Joshi Enterprise Gondal is a full-featured Sales & Inventory Management System built for a snacks/namkeen business. It supports two versions:

| Version       | File          | Backend        | Storage       |
|---------------|---------------|----------------|---------------|
| **PHP + MySQL** | `index.php`   | PHP 7.4+       | MySQL Database |
| **HTML Only**   | `index8.html` | None (browser) | localStorage  |

> Use `index8.html` for quick demo/offline use. Use `index.php` for production with real database.

---

## 🚀 How to Run

### ▶️ Option A — HTML Version (No Server Needed)
1. Open `index8.html` directly in any modern browser (Chrome, Firefox, Edge)
2. Data is saved automatically in your browser's `localStorage`
3. No installation required — works offline!

### ▶️ Option B — PHP + MySQL Version (Recommended for Production)

#### Requirements
- PHP 7.4 or higher (with `mysqli` extension enabled)
- MySQL 5.7 or higher (or MariaDB 10.3+)
- A local server like XAMPP, WAMP, Laragon, or LAMP

#### Steps
1. **Install XAMPP** → https://www.apachefriends.org/ (or any PHP server)
2. **Copy project folder** to `C:\xampp\htdocs\joshi_enterprise\`
3. **Start Apache + MySQL** from the XAMPP Control Panel
4. **Create the database:**
   - Open browser → go to `http://localhost/phpmyadmin`
   - Click "Import" → upload `joshi_enterprise.sql`
   - OR run: `mysql -u root -p < joshi_enterprise.sql`
5. **Open the website:**
   - Go to `http://localhost/joshi_enterprise/index.php`

#### Configure Database (if needed)
Open `index.php` and update lines 3–6:
```php
$db_host = "localhost";
$db_user = "root";
$db_pass = "";          // ← your MySQL password here
$db_name = "joshi_enterprise";
```

---

## 👤 Admin & User Passwords

| Role      | Username   | Password   | Access Level              |
|-----------|------------|------------|---------------------------|
| 👨‍💼 Admin    | `admin`    | `admin123` | Full access (all features)|
| 🧑‍💼 Salesman | `salesman` | `sales123` | Create sales, My Sales    |
| 👨 Pinak   | `pinak`    | `123`      | Create sales, My Sales    |
| 👨 Parth   | `parth`    | `123`      | Create sales, My Sales    |

> **Passwords are stored as SHA-256 hashes** in the database (secure).

---

## ✨ Features

| Feature                   | Admin | Salesman |
|---------------------------|:-----:|:--------:|
| 📊 Dashboard Stats         | ✅    | ✅       |
| 📦 View Products           | ✅    | ✅       |
| ➕ Add Product             | ✅    | ❌       |
| 🗑️ Delete Product          | ✅    | ❌       |
| 🛒 Create Sale / Invoice   | ✅    | ✅       |
| 📜 View My Sales           | ✅    | ✅       |
| 📑 View ALL Sales Report   | ✅    | ❌       |
| 📥 Download PDF Invoice    | ✅    | ✅       |
| 🖨️ Print Invoice           | ✅    | ✅       |
| ⚠️ Low Stock Alert         | ✅    | ❌       |
| 🌙 Dark/Light Theme        | ✅    | ✅       |

---

## 🗂️ File Structure

```
joshi_enterprise/
│
├── index.php              ← Main PHP+MySQL version (production)
├── index8.html            ← Standalone HTML version (no server needed)
├── joshi_enterprise.sql   ← MySQL database setup + seed data
└── README.txt             ← This file
```

---

## 🗄️ Database Structure

```
Database: joshi_enterprise
│
├── users         → id, username, password_hash, role
├── products      → id, name, price, stock, image, created_at
├── sales         → id, customer, mobile, total, date_time, salesman
└── sale_items    → id, sale_id, product_id, product_name, qty, price, line_total
```

---

## 🐛 Bugs Fixed (from original code)

| # | Bug | File | Fix Applied |
|---|-----|------|-------------|
| 1 | `bind_param` had space in format string `"iisi dd"` | index.php | Fixed to `"iisidd"` |
| 2 | Duplicate `bind_param` call inside sale creation loop | index.php | Removed duplicate |
| 3 | Stock deduction had no overflow check (stock could go negative) | index.php | Added `WHERE stock >= qty` |
| 4 | `html2canvas` loaded from non-CDN URL (could fail) | both | Switched to cdnjs CDN |
| 5 | Broken image `onerror` URL (malformed JS string) | both | Fixed to proper template literal |
| 6 | Passwords shown in plain text on login screen | both | Moved to collapsible hint box |
| 7 | Cart allowed adding out-of-stock products | both | Added `stock > 0` filter in product selector |
| 8 | `addToCart` didn't merge duplicates — same item added multiple times | both | Added duplicate merge logic |
| 9 | `downloadPDF` could crash if no invoice was open | both | Added `currentInvoice` null check |
| 10 | Theme toggle emoji inconsistency | both | Fixed sun/moon logic |
| 11 | `logout()` didn't clear cart or form fields | both | Added full cleanup on logout |
| 12 | No empty state message in sales tables | both | Added "No sales yet" row |
| 13 | Dashboard product/low-stock cards not shown for salesman | index.php | Properly scoped admin-only cards |
| 14 | Session check sent no HTTP status code (401) | index.php | Added `http_response_code(401)` |
| 15 | `getProducts` not ordered (random each time) | index.php | Added `ORDER BY name ASC` |

---

## 💡 Tips

- **First time setup:** Always import the SQL file before opening `index.php`
- **Forgot password:** You can reset it in phpMyAdmin using:
  ```sql
  UPDATE users SET password_hash = SHA2('newpassword', 256) WHERE username = 'admin';
  ```
- **Add more products:** Login as admin → Products → Add Product form
- **PDF invoices:** Require internet connection (uses cdnjs CDN for jsPDF + html2canvas)

---

## 🛠️ Tech Stack

- **Frontend:** HTML5, CSS3, Vanilla JavaScript
- **Backend:** PHP 7.4+ (index.php only)
- **Database:** MySQL / MariaDB (index.php only)
- **PDF:** jsPDF + html2canvas (via CDN)
- **No frameworks required** — runs on any basic PHP host

---

*Built for Joshi Enterprise, Gondal — Snacks & Namkeen 🍟*
