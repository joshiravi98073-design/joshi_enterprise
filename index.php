<?php
// ====== DB CONFIG ======
$db_host = "localhost";
$db_user = "root";
$db_pass = "";           // Set your MySQL password here
$db_name = "joshi_enterprise";

$mysqli = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($mysqli->connect_errno) {
    die(json_encode(['ok' => false, 'msg' => 'DB connection failed: ' . $mysqli->connect_error]));
}

session_start();

// ====== API HANDLER ======
if (isset($_GET['api'])) {
    header('Content-Type: application/json');
    $action = $_GET['api'];

    // ---- LOGIN ----
    if ($action === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $username = trim($_POST['username'] ?? '');
        $password = trim($_POST['password'] ?? '');
        $stmt = $mysqli->prepare("SELECT id, username, password_hash, role FROM users WHERE username=? LIMIT 1");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $stmt->bind_result($id, $uname, $hash, $role);
        if ($stmt->fetch() && hash_equals($hash, hash('sha256', $password))) {
            $_SESSION['user'] = ['id' => $id, 'username' => $uname, 'role' => $role];
            echo json_encode(['ok' => true, 'user' => $_SESSION['user']]);
        } else {
            echo json_encode(['ok' => false, 'msg' => 'Invalid username or password']);
        }
        $stmt->close();
        exit;
    }

    // ---- LOGOUT ----
    if ($action === 'logout') {
        session_destroy();
        echo json_encode(['ok' => true]);
        exit;
    }

    // ---- PROTECTED ROUTES ----
    if (!isset($_SESSION['user'])) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'msg' => 'Not logged in']);
        exit;
    }
    $user = $_SESSION['user'];

    // ---- DASHBOARD ----
    if ($action === 'getDashboard') {
        $res  = $mysqli->query("SELECT COALESCE(SUM(total),0) AS total_sales, COUNT(*) AS total_orders FROM sales");
        $dash = $res->fetch_assoc();
        $res->free();
        $res  = $mysqli->query("SELECT COUNT(*) AS total_products, SUM(CASE WHEN stock<10 THEN 1 ELSE 0 END) AS low_stock FROM products");
        $prod = $res->fetch_assoc();
        $res->free();
        echo json_encode([
            'ok'             => true,
            'total_sales'    => (float)$dash['total_sales'],
            'total_orders'   => (int)$dash['total_orders'],
            'total_products' => (int)$prod['total_products'],
            'low_stock'      => (int)$prod['low_stock']
        ]);
        exit;
    }

    // ---- GET PRODUCTS ----
    if ($action === 'getProducts') {
        $rows = [];
        $res  = $mysqli->query("SELECT id, name, price, stock, image FROM products ORDER BY name ASC");
        while ($r = $res->fetch_assoc()) $rows[] = $r;
        $res->free();
        echo json_encode(['ok' => true, 'products' => $rows]);
        exit;
    }

    // ---- ADD PRODUCT (admin only) ----
    if ($action === 'addProduct' && $user['role'] === 'admin' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $name  = trim($_POST['name']  ?? '');
        $price = (float)($_POST['price'] ?? 0);
        $stock = (int)($_POST['stock']  ?? 0);
        $image = trim($_POST['image']  ?? '');
        if ($name === '' || $price <= 0 || $stock < 0) {
            echo json_encode(['ok' => false, 'msg' => 'Invalid product data']);
            exit;
        }
        $stmt = $mysqli->prepare("INSERT INTO products (name, price, stock, image) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("sdis", $name, $price, $stock, $image);
        $stmt->execute();
        $newId = $stmt->insert_id;
        $stmt->close();
        echo json_encode(['ok' => true, 'id' => $newId]);
        exit;
    }

    // ---- DELETE PRODUCT (admin only) ----
    if ($action === 'deleteProduct' && $user['role'] === 'admin' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id   = (int)($_POST['id'] ?? 0);
        $stmt = $mysqli->prepare("DELETE FROM products WHERE id=?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        echo json_encode(['ok' => $affected > 0]);
        exit;
    }

    // ---- CREATE SALE ----
    if ($action === 'createSale' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $customer = trim($_POST['customer'] ?? 'Cash Customer');
        $mobile   = trim($_POST['mobile']   ?? '');
        $items    = json_decode($_POST['items'] ?? '[]', true);

        if (empty($items)) {
            echo json_encode(['ok' => false, 'msg' => 'No items in cart']);
            exit;
        }

        // Use BIGINT-safe unique ID based on microseconds
        $sale_id = (int)round(microtime(true) * 1000);
        $total   = 0;
        foreach ($items as $it) {
            $total += (float)$it['price'] * (int)$it['qty'];
        }
        $dt      = date('Y-m-d H:i:s');
        $salesman = $user['username'];

        $mysqli->begin_transaction();
        try {
            // Insert sale header
            $stmt = $mysqli->prepare(
                "INSERT INTO sales (id, customer, mobile, total, date_time, salesman) VALUES (?,?,?,?,?,?)"
            );
            $stmt->bind_param("issdss", $sale_id, $customer, $mobile, $total, $dt, $salesman);
            $stmt->execute();
            $stmt->close();

            // Insert sale items + reduce stock
            foreach ($items as $it) {
                $pid        = (int)$it['id'];
                $pname      = (string)$it['name'];
                $qty        = (int)$it['qty'];
                $price      = (float)$it['price'];
                $line_total = $price * $qty;

                // BUG FIX: removed extra space in bind_param "iisi dd" → "iisidd"
                $stmt = $mysqli->prepare(
                    "INSERT INTO sale_items (sale_id, product_id, product_name, qty, price, line_total)
                     VALUES (?,?,?,?,?,?)"
                );
                $stmt->bind_param("iisidd", $sale_id, $pid, $pname, $qty, $price, $line_total);
                $stmt->execute();
                $stmt->close();

                // Reduce stock
                $stmt = $mysqli->prepare("UPDATE products SET stock = stock - ? WHERE id = ? AND stock >= ?");
                $stmt->bind_param("iii", $qty, $pid, $qty);
                $stmt->execute();
                $stmt->close();
            }

            $mysqli->commit();
            echo json_encode([
                'ok'       => true,
                'sale_id'  => $sale_id,
                'total'    => $total,
                'date'     => $dt,
                'salesman' => $salesman
            ]);
        } catch (Exception $e) {
            $mysqli->rollback();
            echo json_encode(['ok' => false, 'msg' => 'Error saving sale: ' . $e->getMessage()]);
        }
        exit;
    }

    // ---- GET SALES ----
    if ($action === 'getSales') {
        $my = isset($_GET['my']) && $_GET['my'] == '1';
        if ($my) {
            $stmt = $mysqli->prepare(
                "SELECT id, customer, total, date_time FROM sales WHERE salesman=? ORDER BY date_time DESC LIMIT 50"
            );
            $stmt->bind_param("s", $user['username']);
        } else {
            // Admin: all sales
            $stmt = $mysqli->prepare(
                "SELECT id, customer, total, date_time, salesman FROM sales ORDER BY date_time DESC LIMIT 100"
            );
        }
        $stmt->execute();
        $res  = $stmt->get_result();
        $rows = [];
        while ($r = $res->fetch_assoc()) $rows[] = $r;
        $stmt->close();
        echo json_encode(['ok' => true, 'sales' => $rows]);
        exit;
    }

    // ---- GET SINGLE SALE ----
    if ($action === 'getSale' && isset($_GET['id'])) {
        $id   = (int)$_GET['id'];
        $stmt = $mysqli->prepare(
            "SELECT id, customer, mobile, total, date_time, salesman FROM sales WHERE id=?"
        );
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $sale = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$sale) {
            echo json_encode(['ok' => false, 'msg' => 'Sale not found']);
            exit;
        }

        $stmt = $mysqli->prepare(
            "SELECT product_name, qty, price, line_total FROM sale_items WHERE sale_id=?"
        );
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $res   = $stmt->get_result();
        $items = [];
        while ($r = $res->fetch_assoc()) $items[] = $r;
        $stmt->close();

        echo json_encode(['ok' => true, 'sale' => $sale, 'items' => $items]);
        exit;
    }

    echo json_encode(['ok' => false, 'msg' => 'Unknown action: ' . htmlspecialchars($action)]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>🍟 Joshi Enterprise Gondal</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
<style>
*{margin:0;padding:0;box-sizing:border-box;font-family:Arial,sans-serif}
body{background:linear-gradient(135deg,#ff6b35,#f7931e);min-height:100vh}
.login-form{background:white;padding:40px;border-radius:20px;box-shadow:0 15px 40px rgba(0,0,0,.3);max-width:450px;margin:100px auto;text-align:center}
.login-form h2{color:#ff6b35;margin-bottom:20px;font-size:28px}
.hint{background:#fff3cd;border:1px solid #ffc107;border-radius:8px;padding:10px;margin-bottom:15px;font-size:13px;color:#856404}
input,select{width:100%;padding:15px;margin:10px 0;border:2px solid #eee;border-radius:10px;font-size:15px}
input:focus,select:focus{outline:none;border-color:#ff6b35}
button{width:100%;padding:15px;margin:8px 0;background:#ff6b35;color:white;border:none;border-radius:10px;font-size:17px;font-weight:bold;cursor:pointer;transition:all .3s}
button:hover{transform:translateY(-2px);box-shadow:0 5px 15px rgba(255,107,53,.4)}
.sidebar{position:fixed;left:0;top:0;width:280px;height:100vh;background:#f7931e;color:white;padding:25px;overflow-y:auto;z-index:100;box-shadow:2px 0 20px rgba(0,0,0,.2)}
.sidebar h3{font-size:22px;margin-bottom:15px;text-align:center}
.sidebar ul{list-style:none}
.sidebar a{color:white;text-decoration:none;display:block;padding:14px;border-radius:10px;margin:7px 0;transition:all .3s;cursor:pointer}
.sidebar a:hover{background:rgba(255,255,255,.2);transform:translateX(5px)}
.main-content{margin-left:280px;padding:25px}
.dashboard{background:white;border-radius:20px;padding:30px;box-shadow:0 10px 40px rgba(0,0,0,.15);margin-bottom:25px}
.stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:20px;margin-top:20px}
.stat-card{background:#f8f9fa;padding:25px;border-radius:15px;text-align:center;cursor:pointer;transition:transform .2s}
.stat-card:hover{transform:translateY(-5px)}
.stat-number{font-size:36px;font-weight:bold;color:#ff6b35;margin-top:8px}
.low-stock{background:#fff3cd!important;color:#856404;animation:pulse 2s infinite}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.7}}
table{width:100%;border-collapse:collapse;margin-top:20px;border-radius:15px;overflow:hidden;box-shadow:0 5px 20px rgba(0,0,0,.1)}
th{background:#ff6b35;color:white;padding:15px;text-align:left}
td{padding:14px;border-bottom:1px solid #eee}
tr:hover{background:#f8f9fa}
.product-img{width:70px;height:70px;object-fit:cover;border-radius:12px;border:3px solid #ff6b35}
.cart-img{width:50px;height:50px;object-fit:cover;border-radius:8px}
.form-group{display:flex;flex-wrap:wrap;gap:15px;margin:20px 0;align-items:center}
.form-group input,.form-group select{flex:1;min-width:180px;margin:0}
.form-group button{flex:none;width:auto;padding:15px 25px;margin:0}
.cart-item{display:flex;align-items:center;justify-content:space-between;padding:15px;border-bottom:2px solid #eee;margin:10px 0;border-radius:10px;background:#f8f9fa}
.theme-toggle{position:fixed;top:20px;right:20px;width:60px;height:60px;border-radius:50%;background:#ff6b35;color:white;cursor:pointer;font-size:24px;border:none;box-shadow:0 5px 15px rgba(0,0,0,.3);z-index:1000;transition:all .3s}
.theme-toggle:hover{transform:scale(1.1) rotate(180deg)}
body.dark{background:linear-gradient(135deg,#1a1a2e,#16213e);color:white}
body.dark .dashboard,body.dark table{background:#16213e;color:white}
body.dark .login-form{background:#2d2d44;color:white}
body.dark input,body.dark select{background:#3d3d52;color:white;border-color:#555}
body.dark .stat-card,.dark .cart-item{background:#2d2d44;color:white}
body.dark td{border-color:#333}
body.dark tr:hover{background:#1e2a3a}
@media(max-width:768px){.sidebar{width:100%;height:auto;position:relative}.main-content{margin-left:0}.form-group{flex-direction:column}}
.section{display:none}.section.active{display:block}
.admin-only{display:none}
</style>
</head>
<body>
<button class="theme-toggle" onclick="toggleTheme()" id="themeBtn">🌙</button>

<div id="loginScreen" class="login-form">
  <h2>🍟 Joshi Enterprise Gondal</h2>
  <div class="hint">
    <strong>Login Credentials:</strong><br>
    Admin: <b>admin / admin123</b><br>
    Salesman: <b>salesman / sales123</b><br>
    Pinak: <b>pinak / 123</b> &nbsp;|&nbsp; Parth: <b>parth / 123</b>
  </div>
  <input type="text" id="username" placeholder="Username" autocomplete="username">
  <input type="password" id="password" placeholder="Password" autocomplete="current-password">
  <button onclick="login()">🔐 Login</button>
</div>

<div id="sidebar" class="sidebar" style="display:none">
  <h3>🍟 Joshi Enterprise</h3>
  <p id="userRole" style="text-align:center;font-weight:bold;margin-bottom:20px;padding:10px;background:rgba(255,255,255,0.2);border-radius:10px"></p>
  <ul>
    <li><a onclick="showSection('dashboard')">📊 Dashboard</a></li>
    <li class="admin-only"><a onclick="showSection('products')">📦 Products</a></li>
    <li class="admin-only"><a onclick="showSection('salesReport')">📑 All Sales</a></li>
    <li><a onclick="showSection('createSale')">🛒 Create Sale</a></li>
    <li><a onclick="showSection('mySales')">📜 My Sales</a></li>
    <li><a onclick="logout()" style="background:rgba(220,53,69,0.3)">🚪 Logout</a></li>
  </ul>
</div>

<div id="mainContent" class="main-content" style="display:none">

  <div id="dashboardSection" class="dashboard section active">
    <h1>📊 Dashboard</h1>
    <div class="stats-grid">
      <div class="stat-card" onclick="showSection('salesReport')">
        <h3>💰 Total Sales</h3><div class="stat-number" id="totalSales">₹0</div>
      </div>
      <div class="stat-card" onclick="showSection('salesReport')">
        <h3>🧾 Orders</h3><div class="stat-number" id="totalOrders">0</div>
      </div>
      <div class="stat-card admin-only" onclick="showSection('products')">
        <h3>📦 Products</h3><div class="stat-number" id="totalProducts">0</div>
      </div>
      <div class="stat-card low-stock admin-only" onclick="showSection('products')">
        <h3>⚠️ Low Stock</h3><div class="stat-number" id="lowStock">0</div>
      </div>
    </div>
  </div>

  <div id="productsSection" class="dashboard section">
    <h1>📦 Products</h1>
    <div class="form-group">
      <input id="newProductName" placeholder="Product Name">
      <input id="newProductPrice" type="number" min="0.01" step="0.01" placeholder="Price ₹">
      <input id="newProductStock" type="number" min="0" placeholder="Stock Qty">
      <button onclick="addProduct()" style="background:#28a745">➕ Add Product</button>
    </div>
    <table id="productsTable">
      <thead><tr><th>Image</th><th>Name</th><th>Price</th><th>Stock</th><th>Action</th></tr></thead>
      <tbody></tbody>
    </table>
  </div>

  <div id="createSaleSection" class="dashboard section">
    <h1>🛒 Create Sale</h1>
    <div class="form-group">
      <input id="customerName" placeholder="Customer Name (optional)">
      <input id="customerMobile" type="tel" placeholder="Mobile (optional)">
    </div>
    <div class="form-group">
      <select id="productSelect"><option value="">📦 Select Product...</option></select>
      <input id="saleQuantity" type="number" value="1" min="1" style="max-width:100px">
      <button onclick="addToCart()">➕ Add to Cart</button>
    </div>
    <div id="cartSection" style="display:none">
      <h3 style="margin-bottom:15px">🛍️ Cart</h3>
      <div id="cartItems"></div>
      <p style="font-size:22px;text-align:right;font-weight:bold;margin-top:15px;color:#ff6b35">
        Grand Total: ₹<span id="cartTotal">0</span>
      </p>
      <button onclick="createInvoice()" style="background:#28a745;font-size:18px">💰 Generate Invoice</button>
    </div>
  </div>

  <div id="salesReportSection" class="dashboard section">
    <h1>📑 All Sales (Admin)</h1>
    <table id="salesTable">
      <thead><tr><th>Invoice #</th><th>Customer</th><th>Total</th><th>Date</th><th>Salesman</th><th>View</th></tr></thead>
      <tbody></tbody>
    </table>
  </div>

  <div id="mySalesSection" class="dashboard section">
    <h1>📜 My Sales</h1>
    <table id="mySalesTable">
      <thead><tr><th>Invoice #</th><th>Customer</th><th>Total</th><th>Date</th><th>View</th></tr></thead>
      <tbody></tbody>
    </table>
  </div>

  <div id="invoiceSection" class="dashboard section">
    <h1>🧾 Invoice</h1>
    <div id="invoiceContent" style="padding:30px;background:#f9f9f9;border-radius:15px;margin:20px 0;box-shadow:0 5px 20px rgba(0,0,0,.1)"></div>
    <div style="text-align:center;margin-top:20px">
      <button onclick="downloadPDF()" style="background:#007bff;width:auto;padding:15px 30px;margin:8px">📥 Download PDF</button>
      <button onclick="printInvoice()" style="background:#28a745;width:auto;padding:15px 30px;margin:8px">🖨️ Print</button>
      <button onclick="showSection('createSale')" style="background:#ff6b35;width:auto;padding:15px 30px;margin:8px">➕ New Sale</button>
      <button onclick="showSection('dashboard')" style="background:#6c757d;width:auto;padding:15px 30px;margin:8px">🔙 Dashboard</button>
    </div>
  </div>

</div>

<script>
let currentUser = null;
let products = [];
let currentInvoice = null;
let cart = [];

function api(action, method='GET', data=null) {
  const opts = { method };
  if (data) {
    const form = new FormData();
    for (const k in data) form.append(k, data[k]);
    opts.body = form;
  }
  return fetch('index.php?api=' + action, opts).then(r => r.json());
}

function toggleTheme() {
  document.body.classList.toggle('dark');
  document.getElementById('themeBtn').textContent =
    document.body.classList.contains('dark') ? '☀️' : '🌙';
}

// ---- AUTH ----
function login() {
  const username = document.getElementById('username').value.trim();
  const password = document.getElementById('password').value;
  api('login', 'POST', {username, password}).then(res => {
    if (res.ok) {
      currentUser = res.user;
      document.getElementById('loginScreen').style.display = 'none';
      document.getElementById('sidebar').style.display = 'block';
      document.getElementById('mainContent').style.display = 'block';
      document.getElementById('userRole').textContent = currentUser.role.toUpperCase() + ' · ' + currentUser.username;
      if (currentUser.role === 'admin') {
        document.querySelectorAll('.admin-only').forEach(el => el.style.display = 'block');
      }
      loadDashboard();
      showSection('dashboard');
    } else {
      alert('❌ ' + (res.msg || 'Login failed'));
    }
  }).catch(() => alert('❌ Cannot connect to server. Make sure PHP/MySQL is running.'));
}

function logout() {
  api('logout').then(() => location.reload());
}

// ---- NAV ----
function showSection(section) {
  document.querySelectorAll('.section').forEach(el => el.classList.remove('active'));
  const el = document.getElementById(section + 'Section');
  if (el) el.classList.add('active');
  if (section === 'products')     loadProducts();
  if (section === 'createSale')   loadProductsForSale();
  if (section === 'salesReport')  loadSalesTable(false);
  if (section === 'mySales')      loadSalesTable(true);
  if (section === 'dashboard')    loadDashboard();
}

// ---- DASHBOARD ----
function loadDashboard() {
  api('getDashboard').then(res => {
    if (!res.ok) return;
    document.getElementById('totalSales').textContent    = '₹' + res.total_sales.toLocaleString('en-IN');
    document.getElementById('totalOrders').textContent   = res.total_orders;
    document.getElementById('totalProducts').textContent = res.total_products;
    document.getElementById('lowStock').textContent      = res.low_stock;
  });
}

// ---- PRODUCTS ----
function loadProducts() {
  api('getProducts').then(res => {
    if (!res.ok) return;
    products = res.products;
    const tbody = document.querySelector('#productsTable tbody');
    tbody.innerHTML = '';
    products.forEach(p => {
      const row = tbody.insertRow();
      const initials = encodeURIComponent((p.name||'??').substring(0,2).toUpperCase());
      row.innerHTML = `
        <td><img src="${p.image || ''}" class="product-img"
          onerror="this.src='https://via.placeholder.com/70/ff6b35/fff?text=${initials}'"></td>
        <td>${p.name}</td>
        <td>₹${parseFloat(p.price).toFixed(2)}</td>
        <td ${p.stock < 10 ? 'class="low-stock"' : ''}>${p.stock}</td>
        <td><button onclick="deleteProduct(${p.id})"
          style="background:#dc3545;color:white;padding:8px 15px;border:none;border-radius:5px;cursor:pointer;width:auto">
          🗑️ Delete</button></td>`;
    });
  });
}

function addProduct() {
  const name  = document.getElementById('newProductName').value.trim();
  const price = document.getElementById('newProductPrice').value;
  const stock = document.getElementById('newProductStock').value;
  if (!name || !price || stock === '') { alert('⚠️ Fill all fields.'); return; }
  const initials = encodeURIComponent(name.substring(0,2).toUpperCase());
  const image = `https://via.placeholder.com/70/ff6b35/fff?text=${initials}`;
  api('addProduct', 'POST', {name, price, stock, image}).then(res => {
    if (res.ok) {
      document.getElementById('newProductName').value  = '';
      document.getElementById('newProductPrice').value = '';
      document.getElementById('newProductStock').value = '';
      loadProducts();
      loadDashboard();
    } else alert(res.msg);
  });
}

function deleteProduct(id) {
  if (!confirm('Delete this product?')) return;
  api('deleteProduct', 'POST', {id}).then(res => {
    if (res.ok) { loadProducts(); loadDashboard(); }
  });
}

// ---- CART ----
function loadProductsForSale() {
  api('getProducts').then(res => {
    if (!res.ok) return;
    products = res.products;
    const select = document.getElementById('productSelect');
    select.innerHTML = '<option value="">📦 Select Product...</option>';
    products.filter(p => p.stock > 0).forEach(p => {
      const opt = new Option(`${p.name}  |  ₹${p.price}  |  Stock: ${p.stock}`, p.id);
      select.add(opt);
    });
  });
}

function addToCart() {
  const productId = parseInt(document.getElementById('productSelect').value);
  const qty       = parseInt(document.getElementById('saleQuantity').value);
  if (!productId) { alert('⚠️ Select a product.'); return; }
  if (!qty || qty < 1) { alert('⚠️ Enter valid quantity.'); return; }
  const product = products.find(p => p.id == productId);
  if (!product) return;
  if (product.stock < qty) { alert(`❌ Only ${product.stock} units in stock.`); return; }
  const existing = cart.find(c => c.id == productId);
  if (existing) { existing.qty += qty; }
  else { cart.push({...product, qty}); }
  document.getElementById('cartSection').style.display = 'block';
  document.getElementById('productSelect').value = '';
  document.getElementById('saleQuantity').value  = '1';
  updateCart();
}

function updateCart() {
  const cartItems = document.getElementById('cartItems');
  cartItems.innerHTML = '';
  let total = 0;
  cart.forEach((item, index) => {
    const initials = encodeURIComponent((item.name||'??').substring(0,2).toUpperCase());
    const div = document.createElement('div');
    div.className = 'cart-item';
    div.innerHTML = `
      <div style="display:flex;align-items:center;flex:1">
        <img src="${item.image||''}" class="cart-img"
          onerror="this.src='https://via.placeholder.com/50/ff6b35/fff?text=${initials}'"
          style="margin-right:15px">
        <div><strong>${item.name}</strong><br>
          <small>x${item.qty} @ ₹${item.price} = ₹${(item.price*item.qty).toLocaleString('en-IN')}</small>
        </div>
      </div>
      <button onclick="removeFromCart(${index})"
        style="background:#dc3545;color:white;padding:10px 15px;border:none;border-radius:5px;cursor:pointer;width:auto">
        ✕ Remove</button>`;
    cartItems.appendChild(div);
    total += item.price * item.qty;
  });
  document.getElementById('cartTotal').textContent = total.toLocaleString('en-IN');
}

function removeFromCart(index) {
  cart.splice(index, 1);
  updateCart();
  if (cart.length === 0) document.getElementById('cartSection').style.display = 'none';
}

function createInvoice() {
  if (!cart.length) { alert('🛒 Cart is empty!'); return; }
  const customer = document.getElementById('customerName').value.trim() || 'Cash Customer';
  const mobile   = document.getElementById('customerMobile').value.trim();
  const items    = cart.map(c => ({id:c.id, name:c.name, qty:c.qty, price:c.price}));
  api('createSale', 'POST', {customer, mobile, items: JSON.stringify(items)}).then(res => {
    if (!res.ok) { alert(res.msg || 'Error creating sale'); return; }
    const sale = {
      id: res.sale_id, customer, mobile,
      total: res.total, date: res.date, salesman: res.salesman, items: cart
    };
    generateInvoicePreview(sale);
    currentInvoice = sale;
    showSection('invoice');
    cart = [];
    document.getElementById('cartSection').style.display = 'none';
    document.getElementById('customerName').value   = '';
    document.getElementById('customerMobile').value = '';
    loadDashboard();
  });
}

// ---- SALES TABLE ----
function loadSalesTable(my) {
  // BUG FIX: API URL was 'getSales&my=1' — must be separate GET param
  const url = my ? 'getSales&my=1' : 'getSales';
  api(url).then(res => {
    if (!res.ok) return;
    const tableId = my ? 'mySalesTable' : 'salesTable';
    const tbody   = document.querySelector('#' + tableId + ' tbody');
    tbody.innerHTML = '';
    if (!res.sales.length) {
      tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;color:#999;padding:30px">No sales yet.</td></tr>';
      return;
    }
    res.sales.forEach(s => {
      const row = tbody.insertRow();
      row.innerHTML = `
        <td>#${s.id}</td>
        <td>${s.customer}</td>
        <td>₹${parseFloat(s.total).toLocaleString('en-IN')}</td>
        <td>${new Date(s.date_time).toLocaleString('en-IN')}</td>
        ${my ? '' : `<td>${s.salesman}</td>`}
        <td><button onclick="viewSale(${s.id})"
          style="background:#007bff;color:white;padding:8px 15px;border:none;border-radius:5px;cursor:pointer;width:auto">
          👁️ View</button></td>`;
    });
  });
}

function viewSale(id) {
  // BUG FIX: was 'getSale&id='+id — correct form is ?api=getSale&id=
  api('getSale&id=' + id).then(res => {
    if (!res.ok) { alert('Sale not found'); return; }
    const sale = {
      ...res.sale,
      items: res.items.map(i => ({name:i.product_name, qty:i.qty, price:i.price}))
    };
    generateInvoicePreview(sale);
    currentInvoice = sale;
    showSection('invoice');
  });
}

// ---- INVOICE ----
function generateInvoicePreview(sale) {
  const content = `
  <div style="text-align:center;padding:30px;font-family:Arial">
    <h1 style="color:#ff6b35;font-size:36px;margin-bottom:5px">🍟 Joshi Enterprise</h1>
    <p style="font-size:16px;margin-bottom:5px;color:#666">Gondal · Snacks &amp; Namkeen</p>
    <hr style="border:2px solid #ff6b35;margin:15px 0">
    <h2 style="color:#ff6b35;margin:15px 0">Invoice #${sale.id}</h2>
    <div style="display:flex;justify-content:space-between;margin:20px 0;font-size:15px;text-align:left">
      <div><strong>Customer:</strong> ${sale.customer}<br><strong>Mobile:</strong> ${sale.mobile||'N/A'}</div>
      <div style="text-align:right"><strong>Date:</strong> ${sale.date_time||sale.date}<br><strong>Salesman:</strong> ${sale.salesman}</div>
    </div>
    <table style="width:100%;border-collapse:collapse;margin:20px 0">
      <tr style="background:#ff6b35;color:white">
        <th style="padding:12px;text-align:left">Product</th>
        <th style="padding:12px;width:70px;text-align:center">Qty</th>
        <th style="padding:12px;width:100px;text-align:right">Price</th>
        <th style="padding:12px;width:120px;text-align:right">Total</th>
      </tr>
      ${sale.items.map(item => `
      <tr style="border-bottom:1px solid #eee">
        <td style="padding:12px">${item.name}</td>
        <td style="padding:12px;text-align:center;font-weight:bold">${item.qty}</td>
        <td style="padding:12px;text-align:right">₹${item.price}</td>
        <td style="padding:12px;text-align:right;font-weight:bold">₹${(item.price*item.qty).toLocaleString('en-IN')}</td>
      </tr>`).join('')}
    </table>
    <div style="border-top:3px solid #ff6b35;padding-top:15px;font-size:26px;text-align:right;font-weight:bold;color:#ff6b35">
      Grand Total: ₹${parseFloat(sale.total).toLocaleString('en-IN')}
    </div>
    <p style="margin-top:25px;color:#888;font-style:italic;font-size:14px">
      Thank you for shopping at Joshi Enterprise! 🍟✨
    </p>
  </div>`;
  document.getElementById('invoiceContent').innerHTML = content;
}

async function downloadPDF() {
  if (!currentInvoice) { alert('No invoice loaded.'); return; }
  const { jsPDF } = window.jspdf;
  const doc = new jsPDF({ orientation:'portrait', unit:'mm', format:'a4' });
  const el  = document.getElementById('invoiceContent');
  try {
    const canvas  = await html2canvas(el, { scale:2, useCORS:true });
    const imgData = canvas.toDataURL('image/png');
    const pageW   = doc.internal.pageSize.getWidth();
    const imgW    = pageW - 20;
    const imgH    = imgW * (canvas.height / canvas.width);
    doc.addImage(imgData, 'PNG', 10, 10, imgW, Math.min(imgH, 277));
    doc.save(`Joshi_Invoice_${currentInvoice.id}.pdf`);
  } catch(e) {
    alert('PDF failed. Use Print instead.');
    console.error(e);
  }
}

function printInvoice() { window.print(); }

document.addEventListener('keydown', e => {
  if (e.key === 'Enter' && document.getElementById('loginScreen').style.display !== 'none') login();
});
</script>
</body>
</html>
