<?php
require_once __DIR__ . '/db/session_config.php';
require_once __DIR__ . '/db/auth_check.php';
if (!isset($_SESSION['username']) || $_SESSION['role'] !== 'cashier') {
  header("Location: login");
  exit;
}
// Prevent Safari / Mac bfcache from serving a stale authenticated page after logout
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// Look up the cashier's branch name for display
$cashierBranchId   = isset($_SESSION['branch_id']) ? (int) $_SESSION['branch_id'] : null;
$cashierBranchName = 'All Branches';
if ($cashierBranchId !== null) {
  require_once __DIR__ . '/db/db_connect.php';
  $brStmt = $conn->prepare('SELECT branch_name FROM branches WHERE branch_id = ? LIMIT 1');
  if ($brStmt) {
    $brStmt->bind_param('i', $cashierBranchId);
    $brStmt->execute();
    $brRes = $brStmt->get_result()->fetch_assoc();
    if ($brRes) {
      $cashierBranchName = $brRes['branch_name'];
    }
    $brStmt->close();
  }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Cashier POS — Kape Timplado's (Responsive)</title>

  <!-- Icons & Fonts -->
  <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
  <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
  <script src="js/uiToast.js"></script>
  <script src="js/dataService.js"></script>
  <script src="js/productService.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
  <script src="js/auth_check.js"></script>
  <script src="js/modalHelper.js"></script>
  <!-- Branch context globals -->
  <script>
    window.CASHIER_BRANCH_ID   = <?php echo $cashierBranchId === null ? 'null' : (int)$cashierBranchId; ?>;
    window.CASHIER_BRANCH_NAME = <?php echo json_encode($cashierBranchName); ?>;
  </script>
  <link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@300;400;500;600;700&display=swap" rel="stylesheet">

  <style>
    :root{
      --brown:#7f5539;
      --brown-dark:#6d4329;
      --beige:#faf6f3;
      --accent:#3D2B1F;
      --bg:#f7f4f2;
      --card:#fff;
      --glass: rgba(255,255,255,0.7);
      --success: #28a745;
      --danger: #dc3545;
      --muted: #3D2B1F;
      --radius: 12px;
      --max-width: 1300px;
      --gap: 14px;
    }

    *{box-sizing:border-box}
    html,body{height:100%;margin:0;font-family:'Fredoka',system-ui,Arial;background:linear-gradient(180deg,#fbf9f7,#f0e9e5);color:#2b2020}
    a{color:inherit;text-decoration:none}

    /* Container */
    .app {
      max-width: 100%;
      margin: 0;
      background: linear-gradient(180deg, rgba(255,255,255,0.9), rgba(250,246,243,0.9));
      border-radius: 0;
      box-shadow: none;
      overflow: hidden;
      display: grid;
      grid-template-columns: 260px 1fr;
      gap: 0;
      min-height: 100vh;
      width: 100%;
    }

    /* Sidebar / Navigation */
    .sidebar {
      background: linear-gradient(180deg,var(--brown) 0%, #6a3f2e 100%);
      color: #fff;
      padding: 18px 12px;
      display:flex;
      flex-direction:column;
      gap: 12px;
      min-height: 320px;
    }
    .brand {
      display:flex;align-items:center;gap:10px;padding:6px 12px;border-radius:10px;background:rgba(255,255,255,0.06)
    }
    .brand img{width:42px;height:42px;border-radius:6px;object-fit:cover;box-shadow:0 2px 6px rgba(0,0,0,0.15)}
    .brand .title{font-size:1.1rem;font-weight:600}
    .nav-list{margin-top:8px;display:flex;flex-direction:column;gap:8px}
    .nav-item{display:flex;align-items:center;gap:12px;padding:10px;border-radius:10px;cursor:pointer;color:#fff;transition:background .15s}
    .nav-item ion-icon{font-size:20px}
    .nav-item.active, .nav-item:hover{background:rgba(255,255,255,0.07)}
    .nav-item .label{font-weight:600}
    .sidebar .userbox{margin-top:auto;padding:10px;border-radius:10px;background:rgba(0,0,0,0.06);display:flex;align-items:center;gap:12px}
    .userbox img{width:44px;height:44px;border-radius:50%;object-fit:cover;border:2px solid rgba(255,255,255,0.12)}
    .userbox .name{font-size:0.95rem}
    .signout{margin-top:8px;background:rgba(255,255,255,0.08);border:none;padding:8px;border-radius:8px;color:#fff;cursor:pointer}

    /* Main */
    .main {
      padding: 16px;
      display:flex;
      flex-direction:column;
      gap:12px;
      min-height: calc(100vh - 100px);
    }

    .topbar {
      display:flex;
      align-items:center;
      gap:12px;
      justify-content:space-between;
    }
    .topbar .left {
      display:flex;align-items:center;gap:8px;
    }
    .topbar .toggle {display:none;padding:8px;border-radius:10px;background:var(--card);cursor:pointer;box-shadow:0 2px 6px rgba(20,10,8,0.05)}
    .search {
      display:flex;align-items:center;gap:8px;background:var(--card);padding:8px;border-radius:10px;box-shadow:0 2px 6px rgba(20,10,8,0.03)
    }
    .search input{border:0;outline:none;font-size:14px;background:transparent;padding:6px}

    .category-select {
      padding: 8px 12px;
      border-radius: 10px;
      border: 1px solid rgba(0,0,0,0.06);
      background: var(--card);
      font-size: 14px;
      color: var(--muted);
    }

    .cart-btn {
      display: flex;
      align-items: center;
      gap: 6px;
      padding: 8px 12px;
      border-radius: 10px;
      background: var(--card);
      border: 1px solid rgba(0,0,0,0.06);
      cursor: pointer;
      font-size: 14px;
      color: var(--brown);
      transition: background 0.15s;
    }

    .cart-btn:hover {
      background: var(--beige);
    }

    .cart-btn ion-icon {
      font-size: 18px;
    }

    .badge {
      background: var(--danger);
      color: white;
      border-radius: 50%;
      width: 20px;
      height: 20px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 12px;
      font-weight: 600;
      min-width: 20px;
    }

    /* POS layout (Products + Cart) */
    .pos {
      display: flex;
      flex-direction: row;
      height: calc(100vh - 140px);
      gap: 18px;
    }

    /* Menu section */
    .menu {
      background:var(--card);
      padding:12px;border-radius:12px;box-shadow:0 6px 18px rgba(35,25,20,0.04);
      display:flex;flex-direction:column;gap:12px; flex: 1; overflow: hidden;
    }

    .products-grid {
      display:grid;
      grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
      gap:12px;
      overflow-y:auto;
      height: calc(100% - 50px);
      padding-bottom:6px;
    }
    .product-card {
      background:linear-gradient(180deg,var(--glass),var(--card));
      border-radius:12px;padding:12px;display:flex;flex-direction:column;gap:8px;align-items:stretch;
      cursor:pointer;transition:transform .12s, box-shadow .12s;
      aspect-ratio: 1;
      justify-content: center;
      box-shadow: 0 4px 12px rgba(0,0,0,0.1);
    }
    .product-card:hover{transform:translateY(-4px);box-shadow:0 10px 30px rgba(30,15,10,0.06)}
    .product-meta{display:flex;flex-direction:column;gap:4px;}
    .product-meta .name{font-weight:600;font-size:15px;color:var(--brown-dark); text-align:center; word-break: break-word;}
    .product-meta .category{font-size:12px;color:var(--muted); text-align:center; word-break: break-word; margin-bottom: 8px;}
    .product-meta .sizes{display:flex;gap:6px;flex-wrap:wrap;justify-content:center}
    .size-btn{
      padding:6px 12px;border-radius:8px;border:1px solid rgba(0,0,0,0.06);background:var(--beige);font-size:12px;cursor:pointer;font-weight:500
    }

    /* Cart */
    .cart {
      background:var(--card);padding:12px;border-radius:12px;box-shadow:0 6px 18px rgba(35,25,20,0.04);display:flex;flex-direction:column; width: 320px; flex-shrink: 0; overflow-y: auto;
    }
    .cart .cart-header{display:flex;align-items:center;justify-content:space-between;gap:8px}
    .cart-items{margin-top:10px;display:flex;flex-direction:column;gap:8px;overflow:auto;max-height:52vh;padding-right:6px}
    .cart-items .empty-state {
      text-align: center;
      padding: 40px 20px;
      color: var(--muted);
      font-size: 16px;
      opacity: 0.7;
    }
    .cart-item{display:flex;align-items:center;gap:10px;padding:8px;border-radius:10px;background:linear-gradient(180deg, #fff,#fbf6f4)}
    .ci-info{flex:1;display:flex;flex-direction:column;gap:4px}
    .ci-controls{display:flex;align-items:center;gap:6px}
    .qty-btn{padding:6px 8px;border-radius:8px;background:transparent;border:1px solid rgba(0,0,0,0.06);cursor:pointer}
    .remove-btn{background:transparent;border:0;color:var(--danger);cursor:pointer;font-size:14px}

    .cart-footer{margin-top:auto;padding-top:10px;border-top:1px dashed rgba(0,0,0,0.06);display:flex;flex-direction:column;gap:8px}
    .totals{display:flex;flex-direction:column;gap:6px}
    .totals .row{display:flex;justify-content:space-between;font-weight:600}
    .checkout-btn{margin-top:6px;padding:12px;border-radius:10px;border:0;background:var(--brown);color:#fff;font-weight:700;cursor:pointer}

    /* Orders & Closeout sections (simple) */
    .section-card{background:var(--card);padding:14px;border-radius:12px;box-shadow:0 6px 18px rgba(35,25,20,0.04); min-height: calc(100vh - 200px); display: flex; flex-direction: column;}
    .orders-table{width:100%;border-collapse:collapse}
    .orders-table th, .orders-table td{padding:8px;border-bottom:1px solid rgba(0,0,0,0.05);text-align:left;font-size:13px}
    .order-date-filter{display:flex;gap:6px;font-size:13px}
    .order-date-filter .filter-options{display:flex;gap:6px;flex-wrap:wrap}
    .order-date-filter button{border:1px solid rgba(0,0,0,0.1);background:#fff;padding:4px 8px;border-radius:8px;font-size:12px;cursor:pointer}
    .order-date-filter button.active{background:var(--brown);color:#fff;border-color:var(--brown)}
    .order-detail div{margin-bottom:6px;font-size:14px}
    .order-items{margin-top:4px;font-size:13px;line-height:1.4}
    .closeout-grid{display:grid;grid-template-columns:2fr 1fr;gap:20px;margin-top:12px}
    .closeout-card{background:var(--card);padding:16px;border-radius:14px;box-shadow:0 4px 14px rgba(0,0,0,0.05)}
    .shift-metrics{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:16px;margin-top:12px}
    .shift-metric h2{margin:0;font-size:24px;color:var(--brown)}
    .shift-metric .label{font-size:12px;color:var(--muted);margin:0 0 4px}
    .shift-times{display:flex;gap:24px;flex-wrap:wrap;margin-top:16px}
    .shift-times .label{font-size:12px;color:var(--muted);margin:0 0 4px}
    .closeout-right{display:flex;flex-direction:column;gap:16px}
    .payment-line{display:flex;justify-content:space-between;font-size:14px;margin-bottom:8px}
    .closeout-table-wrapper{max-height:360px;overflow:auto;margin-top:12px}
    .closeout-table th{color:#9a816f;font-size:12px;text-transform:uppercase}
    .closeout-table td{font-size:13px}
    @media(max-width:1024px){
      .closeout-grid{grid-template-columns:1fr;gap:16px}
      .closeout-right{flex-direction:row;flex-wrap:wrap}
      .closeout-right .closeout-card{flex:1;min-width:220px}
    }
    .orders-actions button{margin-right:8px;padding:6px 10px;border-radius:8px;border:0;cursor:pointer}

    /* Checkout modal */
    .modal-backdrop{position:fixed;inset:0;background:rgba(0,0,0,0.4);display:flex;align-items:center;justify-content:center;z-index:60}
    .modal{background:var(--card);padding:18px;border-radius:12px;max-width:520px;width:100%;box-shadow:0 20px 60px rgba(10,10,10,0.2)}
    .modal h3{margin-top:0}

    /* ========== COMPREHENSIVE RESPONSIVE DESIGN FOR CASHIER ========== */
    
    /* Tablet Landscape (1024px+) */
    @media screen and (min-width: 1024px) {
      .app {
        grid-template-columns: 280px 1fr;
      }
      .sidebar {
        padding: 18px 12px;
      }
      .products-grid {
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
      }
    }
    
    /* Tablet Portrait - 768px to 1023px */
    @media screen and (max-width: 1023px) and (min-width: 768px) {
      .app {
        grid-template-columns: 1fr;
      }
      .sidebar {
        flex-direction: row;
        gap: 6px;
        align-items: center;
        padding: 10px;
        overflow-x: auto;
        min-height: 60px;
        white-space: nowrap;
      }
      .sidebar .brand {
        display: none;
      }
      .sidebar .userbox {
        margin-left: auto;
        margin-top: 0;
      }
      .nav-list {
        flex-direction: row;
        margin-top: 0;
        gap: 4px;
      }
      .nav-item {
        padding: 8px 10px;
        white-space: nowrap;
        font-size: 12px;
      }
      .nav-item .label {
        display: none;
      }
      .topbar {
        flex-wrap: wrap;
        gap: 8px;
      }
      .topbar .left {
        width: 100%;
      }
      .topbar > div:last-child {
        width: 100%;
      }
      .pos {
        flex-direction: column;
        height: auto;
        gap: 10px;
      }
      .products-grid {
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        height: 50vh;
        gap: 10px;
      }
      .cart {
        width: 100%;
        order: 2;
      }
      .cart-items {
        max-height: 300px;
      }
    }
    
    /* Small Tablet / Large Mobile - 481px to 767px */
    @media screen and (max-width: 767px) and (min-width: 481px) {
      .app {
        grid-template-columns: 1fr;
      }
      .sidebar {
        flex-direction: row;
        gap: 6px;
        align-items: center;
        padding: 10px;
        overflow-x: auto;
        min-height: 56px;
        white-space: nowrap;
      }
      .sidebar .brand {
        display: none;
      }
      .sidebar .userbox {
        margin-left: auto;
        margin-top: 0;
        font-size: 11px;
      }
      .nav-list {
        flex-direction: row;
        margin-top: 0;
        gap: 4px;
      }
      .nav-item {
        padding: 8px 8px;
        white-space: nowrap;
        font-size: 11px;
        gap: 6px;
      }
      .nav-item .label {
        display: inline;
      }
      .topbar {
        flex-direction: column;
        gap: 8px;
      }
      .topbar .left {
        width: 100%;
      }
      .topbar > div:last-child {
        width: 100%;
        flex-wrap: wrap;
        gap: 8px;
      }
      .search input {
        font-size: 1rem;
      }
      .category-select {
        font-size: 1rem;
        min-height: 40px;
      }
      .cart-btn {
        font-size: 1rem;
        min-height: 40px;
      }
      .products-grid {
        grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
        height: auto;
        max-height: 45vh;
        gap: 8px;
      }
      .product-card {
        padding: 8px;
      }
      .product-meta .name {
        font-size: 12px;
      }
      .size-btn {
        padding: 5px 8px;
        font-size: 10px;
      }
      .cart {
        width: 100%;
        order: 2;
        max-height: 35vh;
      }
      .cart-items {
        max-height: 25vh;
      }
    }
    
    /* Mobile - < 481px */
    @media screen and (max-width: 480px) {
      * {
        -webkit-tap-highlight-color: transparent;
      }
      
      .app {
        grid-template-columns: 1fr;
        min-height: 100vh;
      }
      
      .sidebar {
        flex-direction: row;
        gap: 4px;
        align-items: center;
        padding: 8px;
        overflow-x: auto;
        min-height: 52px;
        white-space: nowrap;
        -webkit-overflow-scrolling: touch;
      }
      
      .sidebar .brand {
        display: none;
      }
      
      .sidebar .userbox {
        margin-left: auto;
        margin-top: 0;
        padding: 6px;
        font-size: 10px;
      }
      
      .userbox img {
        width: 32px;
        height: 32px;
      }
      
      .nav-list {
        flex-direction: row;
        margin-top: 0;
        gap: 2px;
      }
      
      .nav-item {
        padding: 6px 6px;
        white-space: nowrap;
        font-size: 9px;
        gap: 4px;
        flex-shrink: 0;
      }
      
      .nav-item ion-icon {
        font-size: 14px;
      }
      
      .nav-item .label {
        display: none;
      }
      
      .main {
        padding: 8px;
        gap: 8px;
        min-height: calc(100vh - 60px);
      }
      
      .topbar {
        flex-direction: column;
        gap: 6px;
      }
      
      .topbar .left {
        width: 100%;
        gap: 6px;
      }
      
      .topbar .toggle {
        display: block;
        padding: 6px;
      }
      
      .topbar > div:last-child {
        width: 100%;
        flex-wrap: wrap;
        gap: 6px;
      }
      
      h2 {
        font-size: 1rem;
        margin: 0;
      }
      
      .search {
        flex: 1;
        min-width: 100px;
        padding: 6px;
        gap: 6px;
      }
      
      .search input {
        font-size: 1rem;
        padding: 6px;
      }
      
      .category-select {
        font-size: 1rem;
        padding: 8px;
        min-height: 44px;
        flex: 1;
      }
      
      .cart-btn {
        font-size: 1rem;
        padding: 8px 10px;
        min-height: 44px;
      }
      
      .pos {
        flex-direction: column;
        height: auto;
        gap: 8px;
      }
      
      .menu {
        flex: 1;
        padding: 8px;
      }
      
      .products-grid {
        grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
        gap: 6px;
        height: auto;
        max-height: 40vh;
        overflow-y: auto;
        -webkit-overflow-scrolling: touch;
        padding-bottom: 4px;
      }
      
      .product-card {
        padding: 6px;
        aspect-ratio: 1;
      }
      
      .product-meta .name {
        font-size: 11px;
      }
      
      .product-meta .category {
        font-size: 9px;
      }
      
      .size-btn {
        padding: 4px 6px;
        font-size: 9px;
      }
      
      .cart {
        width: 100%;
        padding: 8px;
        max-height: 30vh;
      }
      
      .cart .cart-header {
        gap: 6px;
      }
      
      .cart .cart-header h3 {
        font-size: 1rem;
      }
      
      .cart-items {
        max-height: 20vh;
        gap: 6px;
        padding-right: 4px;
      }
      
      .cart-item {
        padding: 6px;
        gap: 8px;
      }
      
      .ci-info {
        gap: 2px;
        font-size: 12px;
      }
      
      .qty-btn {
        padding: 4px 6px;
        font-size: 12px;
      }
      
      .cart-footer {
        padding-top: 8px;
        gap: 6px;
      }
      
      .totals .row {
        font-size: 16px;
      }
      
      .checkout-btn {
        padding: 10px;
        font-size: 14px;
        min-height: 44px;
      }
      
      .section-card {
        padding: 8px;
        min-height: auto;
      }
      
      .section-card h3 {
        font-size: 0.95rem;
      }
      
      .orders-table {
        font-size: 12px;
        display: block;
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
        width: 100%;
      }
      
      .orders-table thead {
        display: none;
      }
      
      .orders-table tr {
        display: block;
        margin-bottom: 8px;
        border: 1px solid rgba(0,0,0,0.05);
        padding: 8px;
        background: var(--card);
      }
      
      .orders-table td {
        display: block;
        text-align: right;
        padding-left: 40%;
        position: relative;
        border: none;
        padding-bottom: 4px;
        font-size: 11px;
      }
      
      .orders-table td:first-child {
        text-align: left;
        padding-left: 0;
        font-weight: 600;
        margin-bottom: 6px;
      }
      
      .orders-table td:before {
        content: attr(data-label);
        position: absolute;
        left: 8px;
        font-weight: 600;
        width: 35%;
        text-align: left;
        font-size: 10px;
        color: var(--muted);
      }
      
      .orders-table td:first-child:before {
        display: none;
      }
      
      .order-date-filter {
        flex-direction: column;
        width: 100%;
      }
      
      .order-date-filter .filter-options {
        width: 100%;
        gap: 4px;
      }
      
      .order-date-filter button {
        flex: 1;
        padding: 6px;
        font-size: 11px;
      }
      
      .closeout-grid {
        grid-template-columns: 1fr;
        gap: 10px;
      }
      
      .shift-metrics {
        grid-template-columns: repeat(2, 1fr);
        gap: 10px;
      }
      
      .shift-metric h2 {
        font-size: 18px;
      }
      
      .modal {
        max-width: 95vw;
        padding: 12px;
      }
      
      button {
        min-height: 44px;
        font-size: 14px;
      }
    }

    /* small helpers */
    .muted{color:var(--muted);font-size:12px}
    .flex{display:flex;gap:8px;align-items:center}
    .small{font-size:13px}
    .pill{padding:6px 10px;border-radius:999px;background:rgba(0,0,0,0.04)}

    .btn-primary{
      background:var(--brown);
      color:#fff;
      border:none;
      border-radius:10px;
      padding:12px 16px;
      cursor:pointer;
      font-family:inherit;
      font-size:0.95rem;
      font-weight:700;
      transition:background 0.2s ease;
    }

    .btn-primary:hover{
      background:var(--brown-dark);
    }

    .btn-secondary{
      background:#6b7280;
      color:#fff;
      border:none;
      border-radius:8px;
      padding:10px 16px;
      cursor:pointer;
      font-family:inherit;
      font-size:0.95rem;
    }

    .btn-secondary:hover{
      background:#4b5563;
    }

    .btn-danger{
      background:var(--danger);
      color:#fff;
      border:none;
      border-radius:8px;
      padding:10px 16px;
      cursor:pointer;
      font-family:inherit;
      font-size:0.95rem;
    }

    .btn-danger:hover{
      filter:brightness(0.95);
    }

    /* Toast notification */
    .toast{position:fixed;top:20px;right:20px;background:var(--danger);color:#fff;padding:12px 16px;border-radius:8px;box-shadow:0 4px 12px rgba(0,0,0,0.3);z-index:1000;opacity:0;transform:translateY(-20px);transition:opacity 0.3s, transform 0.3s;max-width:300px;word-wrap:break-word}
    .toast.show{opacity:1;transform:translateY(0)}
    .toast.success{background:var(--success)}
  </style>
</head>
<body>
  <div class="app" id="app">
    <!-- Sidebar -->
    <aside class="sidebar" id="sidebar">
      <div class="brand">
        <img src="assest/image/logo.png" alt="logo" onerror="this.src='assest/image/no-image.png'">
        <div>
          <div style="font-size:1rem;font-weight:700">Kape Timplado's</div>
          <div style="font-size:12px;color:rgba(255,255,255,0.85)">Cashier</div>
        </div>
      </div>

      <nav class="nav-list" id="nav">
        <div class="nav-item active" data-section="ProductsForm"><ion-icon name="fast-food-outline"></ion-icon><span class="label">Products</span></div>
        <div class="nav-item" data-section="OrdersForm"><ion-icon name="receipt-outline"></ion-icon><span class="label">Orders</span></div>
        <div class="nav-item" data-section="CloseoutForm"><ion-icon name="calculator-outline"></ion-icon><span class="label">Close-Out</span></div>
        <div class="nav-item" id="signOutBtn"><ion-icon name="log-out-outline"></ion-icon><span class="label">Sign Out</span></div>
      </nav>

      <div class="userbox">
        <img src="assest/image/User Image.jpg" alt="user" onerror="this.src='assest/image/no-image.png'">
        <div>
          <div class="name">Hello, <?php echo htmlspecialchars($_SESSION['username'] ?? 'Cashier'); ?></div>
          <div style="font-size:11px;opacity:0.8;margin-top:2px;"><?php echo htmlspecialchars($cashierBranchName); ?></div>
        </div>
      </div>
    </aside>

    <!-- Main -->
    <main class="main">
      <div class="topbar">
        <div class="left">
          <button class="toggle" id="sidebarToggle"><ion-icon name="menu-outline"></ion-icon></button>
          <h2 style="margin:0">Point of Sale & Orders</h2>
        </div>

        <div style="display:flex;align-items:center;gap:10px">
          <div class="search">
            <ion-icon name="search-outline"></ion-icon>
            <input id="globalSearch" placeholder="Search products..." />
          </div>
          <select id="categoryFilter" class="category-select">
            <option value="">All Categories</option>
          </select>
          <button id="cartBtn" class="cart-btn">
            <ion-icon name="cart-outline"></ion-icon>
            Cart <span id="cartBadge" class="badge">0</span>
          </button>
        </div>
      </div>

      <!-- Sections -->
      <section id="ProductsForm" class="section-card" style="display:block">
        <div class="pos">
          <!-- Menu -->
          <div class="menu">

            <div class="products-grid" id="productsGrid" aria-live="polite">
              <!-- product cards inserted here -->
            </div>
          </div>

          <!-- Cart -->
          <aside class="cart">
            <div class="cart-header">
              <h3 style="margin:0">Cart</h3>
              <div class="muted small">₱ currency</div>
            </div>

            <div class="cart-items" id="cartItems">
              <div class="muted empty-state">Cart is empty</div>
            </div>

            <div class="cart-footer">
              <div class="totals">
                <div class="row" style="font-size:18px"><strong>Total</strong><strong id="total">₱0.00</strong></div>
              </div>
              <div style="display:flex;gap:8px;align-items:center;margin-top:6px">
                <button class="checkout-btn" id="checkoutBtn">Checkout</button>
                <button id="clearCartBtn" style="background: #e5e7eb; color: #374151; border: none; padding: 8px 16px; border-radius: 8px; cursor: pointer; font-family: inherit; font-size: 0.9rem; font-weight: 500;">Clear Cart</button>
              </div>
            </div>
          </aside>
        </div>
      </section>

      <!-- Orders -->
      <section id="OrdersForm" class="section-card" style="display:none">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;flex-wrap:wrap;gap:12px">
          <h3 style="margin:0">Orders</h3>
          <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap">
            <input id="orderSearch" placeholder="Search orders..." style="padding:8px;border-radius:8px;border:1px solid rgba(0,0,0,0.06)">
            <div class="order-date-filter">
              <div class="filter-options">
                <button type="button" data-range="today">Today</button>
                <button type="button" data-range="yesterday">Yesterday</button>
                <button type="button" data-range="week">This Week</button>
                <button type="button" data-range="all">All</button>
              </div>
            </div>
          </div>
        </div>

        <div style="flex: 1; overflow: auto;">
          <table class="orders-table" aria-live="polite" style="width: 100%;">
            <thead>
              <tr><th>ID</th><th>Items</th><th>Total</th><th>Status</th><th>Reference Number</th><th>Timestamp</th><th>Actions</th></tr>
            </thead>
            <tbody id="ordersTableBody">
              <!-- orders -->
            </tbody>
          </table>
        </div>
      </section>

      <!-- Closeout -->
      <section id="CloseoutForm" class="section-card" style="display:none">
        <div class="closeout-grid">
          <div class="closeout-left">
            <div class="closeout-card shift-card">
              <div class="shift-card-header">
                <div>
                  <h3 style="margin:0">Close-Out / End of Shift</h3>
                  <p class="muted small" style="margin:2px 0 0">Shift summary for today &middot; <span id="closeoutBranchLabel"></span></p>
                </div>
              </div>
              <div class="shift-metrics">
                <div class="shift-metric">
                  <p class="label">Total Orders (Today)</p>
                  <h2 id="closeoutTotalOrders">0</h2>
                </div>
                <div class="shift-metric">
                  <p class="label">Gross Sales (Today)</p>
                  <h2 id="closeoutGrossSales">₱0.00</h2>
                </div>
                <div class="shift-metric">
                  <p class="label">Net Sales (Today)</p>
                  <h2 id="closeoutNetSales">₱0.00</h2>
                </div>
              </div>
              <div class="shift-times">
                <div>
                  <p class="label">Shift Time In</p>
                  <strong id="closeoutShiftStart">-</strong>
                </div>
                <div>
                  <p class="label">Shift Time Out</p>
                  <strong id="closeoutShiftEnd">-</strong>
                </div>
              </div>
            </div>

            <div class="closeout-card">
              <div class="closeout-table-header">
                <h4 style="margin:0">Sales Breakdown by Product</h4>
              </div>
              <div class="closeout-table-wrapper">
                <table class="orders-table closeout-table">
                  <thead>
                    <tr>
                      <th>Product</th>
                      <th>Qty Sold</th>
                      <th>Gross</th>
                      <th>Cost</th>
                      <th>Net</th>
                    </tr>
                  </thead>
                  <tbody id="closeoutSalesBody">
                    <tr><td colspan="5" class="muted">No sales data for today</td></tr>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
          <aside class="closeout-right">
            <div class="closeout-card payment-card">
              <h4 style="margin-top:0">Payment Breakdown</h4>
              <div class="payment-line"><span>Cash</span><strong id="closeoutCashTotal">₱0.00</strong></div>
              <div class="payment-line"><span>GCash / E-wallet</span><strong id="closeoutGcashTotal">₱0.00</strong></div>
            </div>
            <div class="closeout-card">
              <button class="checkout-btn" id="exportCloseoutBtn" style="width:100%;justify-content:center">Generate End-of-Shift Report</button>
            </div>
          </aside>
        </div>
      </section>
    </main>
  </div>

  <!-- Modal placeholder -->
  <div id="modalRoot"></div>

  <script>
    /* ----------------------
       Simple responsive POS JS
       - loads products (tries backend else uses mock)
       - search, filter
       - cart functionality (qty, remove)
       - checkout modal (simulate)
       - orders & closeout summary
    ----------------------- */

    (function(){
      // State
      let products = [];
      let categories = [];
      let cart = [];
      let orders = [];
      const useMock = false;
      let latestCloseoutSummary = null;
      let checkoutInProgress = false;
      let checkoutToken = null;
      const productNameLookup = {};
      const sizeNameLookup = {};
      let productCostMap = null;
      let productCostPromise = null;

      // Set branch context label in Close-Out header
      (function() {
        const branchLabel = document.getElementById('closeoutBranchLabel');
        if (branchLabel) {
          branchLabel.textContent = window.CASHIER_BRANCH_NAME || '';
        }
      })();

      // DOM refs
      const productsGrid = document.getElementById('productsGrid');
      const categoryFilter = document.getElementById('categoryFilter');
      const globalSearch = document.getElementById('globalSearch');
      const cartItemsEl = document.getElementById('cartItems');
      const totalEl = document.getElementById('total');
      const checkoutBtn = document.getElementById('checkoutBtn');
      const clearCartBtn = document.getElementById('clearCartBtn');
      const cartBtn = document.getElementById('cartBtn');
      const cartBadge = document.getElementById('cartBadge');

      const navItems = document.querySelectorAll('.nav-item');
      const sections = { ProductsForm: document.getElementById('ProductsForm'), OrdersForm: document.getElementById('OrdersForm'), CloseoutForm: document.getElementById('CloseoutForm') };

      // Try to fetch products from backend, else fallback
      async function fetchProducts(){
        if (!useMock){
          try {
            if (window.ProductService) {
              const data = await ProductService.fetchProducts({ includeInactive: false, includeUnits: true, status: 'active' });
              products = Array.isArray(data.products) ? data.products : Array.isArray(data) ? data : [];
            } else {
              const res = await fetch('db/products_getAll.php?status=active&format=payload', {cache:'no-store'});
              if (!res.ok) throw new Error('Network response not ok');
              const data = await res.json();
              products = data.products || data;
            }
            products = normalizeProductList(products)
              .filter(product => {
                const activeValue = product.isActive ?? product.is_active ?? 1;
                return activeValue !== false && Number(activeValue) !== 0;
              });
            updateProductLookups(products);
            if (!Array.isArray(products) || products.length === 0) throw new Error('No products');
            buildCategories();
            renderProducts();
            return;
          } catch (err) {
            console.error('Backend products fetch failed', err);
            products = [];
            buildCategories();
            renderProducts();
            if (productsGrid) {
              productsGrid.innerHTML = '<div class="muted" style="padding:2rem;text-align:center;">No products available</div>';
            }
          }
        }
      }

      function buildCategories(){
        const unique = {};
        categories = [];
        products.forEach(p => {
          if (!unique[p.categoryID]) {
            unique[p.categoryID] = p.categoryName || 'Uncategorized';
            categories.push({categoryID: p.categoryID, categoryName: p.categoryName || 'Uncategorized'});
          }
        });
        updateCategorySelect();
      }

      function updateCategorySelect(){
        categoryFilter.innerHTML = '<option value="">All Categories</option>';
        categories.forEach(c => {
          const opt = document.createElement('option');
          opt.value = c.categoryID;
          opt.textContent = c.categoryName;
          categoryFilter.appendChild(opt);
        });
      }

      function normalizeProductList(list) {
        if (!Array.isArray(list)) return [];
        return list.map(product => ({
          ...product,
          addons: normalizeAddons(product?.addons),
          flavors: normalizeFlavors(product?.flavors)
        }));
      }

      function normalizeSizes(rawSizes) {
        const sizeMap = new Map();
        if (Array.isArray(rawSizes)) {
          rawSizes.forEach(raw => {
            // Support new variant_id from product_variants table, fallback to sizeID
            const variantId = Number(raw.variant_id ?? raw.variantId ?? raw.sizeID ?? raw.id ?? raw.sizeId ?? 0);
            if (!Number.isFinite(variantId) || variantId === 0) return;
            const priceValue = Number(raw.price ?? raw.defaultPrice ?? raw.basePrice ?? raw.unitPrice ?? 0);
            const price = Number.isFinite(priceValue) ? priceValue : 0;
            // Prefer size_label from product_variants, fallback to sizeName/name/variant_name
            let label = String(raw.size_label ?? raw.sizeName ?? raw.name ?? raw.variant_name ?? raw.label ?? variantId).trim();
            
            // Only include sizes with non-zero prices
            if (price > 0) {
              const key = String(label).toLowerCase();
              const existing = sizeMap.get(key);
              if (!existing || price > existing.price) {
                sizeMap.set(key, {
                  variantId,      // New schema: variant_id
                  sizeID: variantId,  // Legacy compatibility
                  sizeName: label,
                  price
                });
              }
            }
          });
        }
        return Array.from(sizeMap.values());
      }

      function updateProductLookups(list) {
        if (!Array.isArray(list)) return;
        list.forEach(product => {
          const productId = product.productID ?? product.id;
          if (productId) {
            productNameLookup[productId] = product.productName || product.name || `Product #${productId}`;
          }
          if (Array.isArray(product.sizes)) {
            product.sizes.forEach(size => {
              const sizeId = size.sizeID ?? size.sizeId ?? size.id;
              if (sizeId) {
                sizeNameLookup[sizeId] = size.sizeName || size.name || '';
              }
            });
          }
        });
      }

      function formatSizeLabel(label, categoryName = '') {
        const value = String(label ?? '').trim();
        if (!value) return '';
        
        // Check if this is a snacks product - if so, use 'pc', otherwise use 'oz'
        const isSnacks = String(categoryName || '').toLowerCase().includes("mpop's snacks");
        const unitSymbol = isSnacks ? 'pc' : 'oz';
        
        // Just append the unit symbol to the value
        if (/^\d+(\.\d+)?$/.test(value)) {
          return `${value}${unitSymbol}`;
        }
        
        return value;
      }

      // Render products grid (filtered)
      function renderProducts(filtered){
        const list = filtered || products;
        productsGrid.innerHTML = '';
        if (!list || list.length === 0){
          productsGrid.innerHTML = '<div class="muted empty-state">No products found</div>';
          return;
        }
        list.forEach(p => {
          const card = document.createElement('div');
          card.className = 'product-card';
          card.setAttribute('data-id', p.productID);
          card.innerHTML = `
            <div class="product-meta">
              <div class="name">${escapeHtml(p.productName)}</div>
              <div class="category muted small">${escapeHtml(p.categoryName || '')}</div>
              <div class="sizes"></div>
            </div>
          `;
          const uniqueSizes = normalizeSizes(p.sizes);
          // Filter out zero prices when calculating lowest price
          const nonZeroPrices = uniqueSizes.length 
            ? uniqueSizes.map(s => Number(s.price)).filter(price => price > 0)
            : (p.price && Number(p.price) > 0 ? [Number(p.price)] : []);
          const lowestPrice = nonZeroPrices.length > 0 ? Math.min(...nonZeroPrices) : 0;
          const sizesDiv = card.querySelector('.sizes');
          sizesDiv.textContent = lowestPrice > 0 
            ? (uniqueSizes.length ? 'From ₱' + formatNumber(lowestPrice) : 'Price: ₱' + formatNumber(lowestPrice))
            : 'Price: ₱' + formatNumber(0);

          // Make the entire card clickable to open a selection modal (size, qty, addons)
          card.addEventListener('click', () => {
            openProductModal(p);
          });

          productsGrid.appendChild(card);
        });
      }

      // Utility: escape html
      function escapeHtml(s){ return (''+s).replace(/[&<>"'`]/g, c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;','`':'&#96;'})[c]); }

      // Formatting
      function formatNumber(n){ return Number(n).toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2}); }
      const peso = '\u20B1';
      function currency(n){ return peso + formatNumber(n); }
      function formatDateTime(ts){
        if(!ts) return '-';
        const d = new Date(ts);
        if (isNaN(d)) return ts;
        return d.toLocaleDateString() + ' ' + d.toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'});
      }

      function parseOrderTimestamp(raw) {
        if (!raw) return null;
        let value = raw;
        if (typeof value === 'string') {
          value = value.trim();
          if (value.length === 10 && /^\d{4}-\d{2}-\d{2}$/.test(value)) {
            value += 'T00:00:00';
          } else if (value.includes(' ') && !value.includes('T')) {
            value = value.replace(' ', 'T');
          }
        }
        const date = new Date(value);
        if (isNaN(date) && typeof raw === 'string') {
          const fallback = new Date(raw);
          return isNaN(fallback) ? null : fallback;
        }
        return isNaN(date) ? null : date;
      }

      function isSameDay(dateA, dateB) {
        if (!(dateA instanceof Date) || !(dateB instanceof Date)) return false;
        return dateA.getFullYear() === dateB.getFullYear() &&
               dateA.getMonth() === dateB.getMonth() &&
               dateA.getDate() === dateB.getDate();
      }

      async function ensureProductCostMap() {
        if (productCostMap) return productCostMap;
        if (productCostPromise) return productCostPromise;
        productCostPromise = fetch('db/inventory_costing.php', { cache: 'no-store' })
          .then(res => res.json())
          .then(data => {
            productCostMap = new Map();
            if (Array.isArray(data)) {
              data.forEach(entry => {
                const productName = normalizeKeyPart(entry.Product || entry.product);
                const sizeLabel = normalizeSizeKey(entry.Size || entry.size);
                const costValue = Number(entry.Cost ?? entry.cost ?? 0);
                if (!productName) return;
                const key = `${productName}|${sizeLabel}`;
                productCostMap.set(key, costValue);
                if (!sizeLabel) {
                  productCostMap.set(`${productName}|`, costValue);
                }
              });
            }
            return productCostMap;
          })
          .catch(err => {
            console.warn('Failed to load product costing data:', err);
            productCostPromise = null;
            return null;
          });
        return productCostPromise;
      }

      function normalizeKeyPart(value) {
        if (value === null || value === undefined) return '';
        return String(value).trim().toLowerCase();
      }

      function normalizeSizeKey(value) {
        const base = normalizeKeyPart(value);
        if (!base) return '';
        if (base.includes('oz')) return base;
        if (/^\d+(\.\d+)?$/.test(base)) {
          return `${base}oz`;
        }
        return base;
      }

      function getCostForProduct(productName, sizeLabel) {
        if (!productCostMap) return 0;
        const baseKey = `${normalizeKeyPart(productName)}|${normalizeSizeKey(sizeLabel)}`;
        if (productCostMap.has(baseKey)) {
          return Number(productCostMap.get(baseKey)) || 0;
        }
        const fallbackKey = `${normalizeKeyPart(productName)}|`;
        return Number(productCostMap.get(fallbackKey)) || 0;
      }

      function parseOrderItems(raw) {
        if (!raw) return [];
        try {
          const parsed = JSON.parse(raw);
          return Array.isArray(parsed) ? parsed : [];
        } catch (error) {
          return [];
        }
      }

      // Default configs (used when backend data missing)
      const DEFAULT_ADDONS = [
        { addon_id: 1, name: 'Milk', price: 10 },
        { addon_id: 2, name: 'Espresso Shot', price: 15 },
        { addon_id: 3, name: 'Syrup', price: 10 },
        { addon_id: 4, name: 'Strawberry Puree', price: 12 },
        { addon_id: 5, name: 'Condensed Milk', price: 10 },
        { addon_id: 6, name: 'Salted Cream', price: 15 }
      ];

      const DEFAULT_FLAVORS = [
        { flavor_id: 1, name: 'Snowcheese' },
        { flavor_id: 2, name: 'BBQ' },
        { flavor_id: 3, name: 'Teriyaki' },
        { flavor_id: 4, name: 'Chili' },
        { flavor_id: 5, name: 'Yangnyeom' },
        { flavor_id: 6, name: 'Cheese' },
        { flavor_id: 7, name: 'Buffalo' },
        { flavor_id: 8, name: 'Sweet & Sour' },
        { flavor_id: 9, name: 'Bechamel' },
        { flavor_id: 10, name: 'Honey Butter' }
      ];

      function normalizeAddons(addons) {
        if (!Array.isArray(addons)) return [];
        const map = new Map();
        addons.forEach(raw => {
          const addonId = Number(raw.addon_id ?? raw.id ?? raw.addonId ?? 0);
          if (!addonId) return;
          const price = Number(raw.price ?? 0);
          const name = (raw.name ?? raw.addon_name ?? raw.label ?? `Addon #${addonId}`).toString();
          map.set(addonId, {
            addon_id: addonId,
            id: addonId,
            name,
            price: Number.isFinite(price) ? price : 0
          });
        });
        return Array.from(map.values());
      }

      function normalizeFlavors(flavors) {
        if (!Array.isArray(flavors)) return [];
        const map = new Map();
        flavors.forEach(raw => {
          const flavorId = Number(raw.flavor_id ?? raw.id ?? raw.flavorId ?? 0);
          if (!flavorId) return;
          const name = (raw.name ?? raw.flavor_name ?? raw.label ?? `Flavor #${flavorId}`).toString();
          map.set(flavorId, {
            flavor_id: flavorId,
            id: flavorId,
            name
          });
        });
        return Array.from(map.values());
      }

      // Only addons linked to this product via product_addons (use has_addons)
      function getProductAddons(product) {
        if (!product || !product.has_addons) return [];
        return normalizeAddons(product.addons || []);
      }

      // Only flavors linked to this product via food_flavors (use has_flavors)
      function getProductFlavors(product) {
        if (!product || !product.has_flavors) return [];
        return normalizeFlavors(product.flavors || []);
      }

      function getAddonLabel(addon) {
        if (!addon) return '';
        if (typeof addon === 'string') return addon.replace(/_/g, ' ');
        return addon.name || addon.label || addon.key || '';
      }

      // Open a modal to select size, quantity, and addons/flavors (product-specific)
      function openProductModal(product) {
        const sizes = product.sizes || [];
        const validSizes = sizes.filter(s => Number(s.price) > 0);
        const sizeOptionsHtml = validSizes.length 
          ? validSizes.map((s, idx) => `<label style="display:block; margin-bottom:6px;"><input type="radio" name="selectedSize" value="${s.sizeID}" data-price="${s.price}" ${idx === 0 ? 'checked' : ''}> ${escapeHtml(formatSizeLabel(s.sizeName, product.categoryName))} — ₱${formatNumber(s.price)}</label>`).join('') 
          : (sizes.length ? `<label style="display:block; margin-bottom:6px;"><input type="radio" name="selectedSize" value="" data-price="${product.price || 0}" checked> Default — ₱${formatNumber(product.price || 0)}</label>` : `<label style="display:block; margin-bottom:6px;"><input type="radio" name="selectedSize" value="" data-price="${product.price || 0}" checked> Default — ₱${formatNumber(product.price || 0)}</label>`);

        const categoryName = (product.categoryName || '').toLowerCase().replace(/&#039;|&apos;|'/g, "'");
        const isSnackItem = categoryName.includes("mpop's snacks");
        const addonList = getProductAddons(product).map(a => ({ key: a.addon_id, label: a.name, price: Number(a.price) || 0 }));
        const flavorList = getProductFlavors(product).map(f => ({ key: f.flavor_id, label: f.name }));

        const addonsSection = addonList.length ? `
            <div style="margin-top:12px; margin-bottom:8px;"><strong>Addons</strong></div>
            <div id="modalAddons">${addonList.map(a => `<label style="display:block; margin-bottom:6px;"><input type="checkbox" class="addon-checkbox" data-addon-id="${a.key}" data-price="${a.price}"> ${escapeHtml(a.label)} ${a.price ? '(+₱' + formatNumber(a.price) + ')' : ''}</label>`).join('')}</div>
          ` : '';
        const flavorsSection = flavorList.length ? `
            <div style="margin-top:12px; margin-bottom:8px;"><strong>Flavor</strong></div>
            <div id="modalFlavors">${flavorList.map((f, idx) => `<label style="display:block; margin-bottom:6px;"><input type="radio" name="selectedFlavor" class="flavor-radio" value="${f.key}" data-flavor-id="${f.key}" ${idx === 0 ? 'checked' : ''}> ${escapeHtml(f.label)}</label>`).join('')}</div>
          ` : '';

        const content = `
          <div>
            <div style="margin-bottom:12px; font-weight:600;">${escapeHtml(product.productName)}</div>
            <div style="margin-bottom:8px;"><strong>Size</strong></div>
            <div id="modalSizes">${sizeOptionsHtml}</div>
            <div style="margin-top:8px; margin-bottom:8px;"><strong>Quantity</strong></div>
            <div><input type="number" id="modalQty" value="1" min="1" style="width:80px; padding:6px; border-radius:6px; border:1px solid rgba(0,0,0,0.08);"></div>
            ${addonsSection}
            ${flavorsSection}
            <div style="margin-top:12px; font-weight:700">Per unit: <span id="modalUnitPrice">₱0.00</span></div>
            <div style="margin-top:6px; font-weight:700">Total: <span id="modalTotalPrice">₱0.00</span></div>
          </div>
          <div style="display:flex; gap:8px; margin-top:16px; justify-content:flex-end;">
            <button id="modalCancelBtn" class="btn-secondary">Cancel</button>
            <button id="modalAddBtn" class="btn-primary">Add to cart</button>
          </div>
        `;

        const { overlay, container, body } = ModalHelper.open({ id: 'product-modal-' + product.productID, title: 'Add to order', content, width: '520px', onOpen: ({ body }) => {
          const unitEl = body.querySelector('#modalUnitPrice');
          const totalEl = body.querySelector('#modalTotalPrice');
          const qtyEl = body.querySelector('#modalQty');

          function computePrices() {
            const selectedSize = body.querySelector('input[name="selectedSize"]:checked');
            const base = Number(selectedSize?.dataset?.price || 0);
            let addonTotal = 0;
            body.querySelectorAll('.addon-checkbox:checked').forEach(cb => {
              addonTotal += Number(cb.dataset.price || 0);
            });
            const unit = base + addonTotal;
            const qty = Math.max(1, parseInt(qtyEl.value) || 1);
            unitEl.textContent = currency(unit);
            totalEl.textContent = currency(unit * qty);
          }

          body.querySelectorAll('input[name="selectedSize"]').forEach(el => el.addEventListener('change', computePrices));
          body.querySelectorAll('.addon-checkbox').forEach(el => el.addEventListener('change', computePrices));
          body.querySelectorAll('.flavor-radio').forEach(el => el.addEventListener('change', computePrices));
          qtyEl.addEventListener('input', computePrices);

          computePrices();

          body.querySelector('#modalCancelBtn').addEventListener('click', () => ModalHelper.close(overlay.id));
          body.querySelector('#modalAddBtn').addEventListener('click', () => {
            const selectedSizeId = body.querySelector('input[name="selectedSize"]:checked')?.value || null;
            const selectedSize = validSizes.length ? validSizes.find(s => String(s.sizeID) === String(selectedSizeId)) || null : null;
            const qty = Math.max(1, parseInt(qtyEl.value) || 1);
            const addons = Array.from(body.querySelectorAll('.addon-checkbox:checked')).map(cb => {
              const id = parseInt(cb.dataset.addonId, 10) || 0;
              const price = Number(cb.dataset.price) || 0;
              const addon = addonList.find(a => a.key === id);
              return { addon_id: id, price, name: addon ? addon.label : `Addon #${id}` };
            }).filter(a => a.addon_id > 0);
            const flavorRadio = body.querySelector('input[name="selectedFlavor"]:checked');
            const flavorId = flavorRadio ? (parseInt(flavorRadio.dataset.flavorId, 10) || null) : null;
            const flavorName = flavorId && flavorList.length ? (flavorList.find(f => f.key === flavorId) || {}).label || '' : '';

            addToCart(product, selectedSize, qty, addons, flavorId, flavorName);
            ModalHelper.close(overlay.id);
          });
        }});
      }

      // Cart logic: product, size, qty, addons (array of { addon_id, price, name? }), flavorId (number|null), flavorName (string)
      function addToCart(product, size, qty = 1, addons = [], flavorId = null, flavorName = ''){
        const basePrice = size ? Number(size.price) : (product.price ? Number(product.price) : 0);
        const addonArr = Array.isArray(addons) ? addons : [];
        const addonPrice = addonArr.reduce((sum, a) => sum + (Number(a.price) || 0), 0);
        const unitPrice = basePrice + addonPrice;

        const item = {
          cartID: Date.now() + Math.floor(Math.random()*1000),
          productID: product.productID,
          name: product.productName,
          variantId: size ? (size.variantId ?? size.sizeID) : null,
          sizeID: size ? (size.variantId ?? size.sizeID) : null,
          sizeName: size ? size.sizeName : '',
          categoryName: product.categoryName || '',
          basePrice: basePrice,
          addonPrice: addonPrice,
          addons: addonArr,
          flavorId: flavorId != null ? flavorId : null,
          flavorName: flavorName || '',
          price: unitPrice,
          qty: qty,
          image: product.image_url || ''
        };

        const existing = cart.find(c => c.productID === item.productID && c.variantId === item.variantId && c.flavorId === item.flavorId && JSON.stringify(c.addons || []) === JSON.stringify(item.addons || []));
        if (existing){
          existing.qty += item.qty;
        } else {
          cart.push(item);
        }
        renderCart();
      }

      function renderCart(){
        cartItemsEl.innerHTML = '';
        if (cart.length === 0){
          cartItemsEl.innerHTML = '<div class="muted empty-state">Cart is empty</div>';
          updateTotals();
          if (cartBadge) cartBadge.textContent = '0';
          // Hide clear button when cart is empty
          if (clearCartBtn) clearCartBtn.style.display = 'none';
          return;
        }
        // Show clear button when cart has items
        if (clearCartBtn) clearCartBtn.style.display = 'inline-block';
        cart.forEach(ci => {
          const el = document.createElement('div');
          el.className = 'cart-item';
          el.innerHTML = `
            <div class="ci-info" style="flex:1;">
              <div style="display:flex;justify-content:space-between;align-items:center">
                <div style="font-weight:600">${escapeHtml(ci.name)}</div>
                <div style="font-size:13px;color:var(--muted)">${ci.sizeName ? formatSizeLabel(ci.sizeName, ci.categoryName || '') : ''}</div>
              </div>
              <div style="margin-top:6px; color:var(--muted); font-size:0.9rem">
                ${[ci.addons && ci.addons.length ? ('Addons: ' + ci.addons.map(a => escapeHtml(a.name || getAddonLabel(a))).join(', ')) : '', ci.flavorName ? ('Flavor: ' + escapeHtml(ci.flavorName)) : ''].filter(Boolean).join(' · ')}
              </div>
              <div style="display:flex;justify-content:space-between;align-items:center;margin-top:6px">
                <div class="ci-controls">
                  <button class="qty-btn" data-action="decrease">-</button>
                  <input type="number" class="qty-input" value="${ci.qty}" min="1" style="padding:6px 8px;border-radius:8px;background:#fff;border:1px solid rgba(0,0,0,0.04);width:60px;text-align:center;">
                  <button class="qty-btn" data-action="increase">+</button>
                </div>
                <div style="text-align:right">
                  <div style="font-weight:700">${currency(ci.price * ci.qty)}</div>
                  <div style="font-size:12px;color:var(--muted)">₱${formatNumber(ci.price)} each</div>
                  <button class="remove-btn small" data-action="remove">Remove</button>
                </div>
              </div>
            </div>
          `;
          // Buttons
          el.querySelector('[data-action=increase]').addEventListener('click', ()=> { ci.qty++; renderCart(); });
          el.querySelector('[data-action=decrease]').addEventListener('click', ()=> { if (ci.qty>1) ci.qty--; else removeFromCart(ci.cartID); renderCart(); });
          el.querySelector('[data-action=remove]').addEventListener('click', ()=> { removeFromCart(ci.cartID); });
          // Input event
          const qtyInput = el.querySelector('.qty-input');
          qtyInput.addEventListener('input', (e)=> {
            const val = parseInt(e.target.value);
            if (isNaN(val) || val < 1) {
              e.target.value = ci.qty; // revert
            } else {
              ci.qty = val;
              renderCart();
            }
          });
          cartItemsEl.appendChild(el);
        });
        updateTotals();
        if (cartBadge) cartBadge.textContent = cart.length;
      }

      function removeFromCart(cartID){
        cart = cart.filter(c=> c.cartID !== cartID);
        renderCart();
      }

      function clearCart(){
        cart = [];
        renderCart();
      }

      function updateTotals(){
        const subtotal = cart.reduce((s,i)=> s + (i.price * i.qty), 0);
        const discount = 0; // placeholder for discount logic
        const tax = 0; // tax removed
        const total = subtotal - discount + tax;

        totalEl.textContent = currency(total);
      }

      // Toast notification
      function showToast(message, type = 'error') {
        if (window.showToast) {
          window.showToast(message, type);
          return;
        }
        const toast = document.createElement('div');
        toast.className = `toast ${type === 'success' ? 'success' : ''}`;
        toast.textContent = message;
        document.body.appendChild(toast);
        setTimeout(() => toast.classList.add('show'), 10);
        setTimeout(() => {
          toast.classList.remove('show');
          setTimeout(() => document.body.removeChild(toast), 300);
        }, 3000);
      }

      // Inventory check
      async function checkInventory(){
        try {
          const res = await fetch('db/inventory_get.php');
          if (!res.ok) throw new Error('Failed to fetch inventory');
          const inventory = await res.json();
          const sizeGroups = {};
          cart.forEach(item => {
            if (item.sizeID) {
              if (!sizeGroups[item.sizeID]) sizeGroups[item.sizeID] = { qty: 0, sizeName: item.sizeName };
              sizeGroups[item.sizeID].qty += item.qty;
            }
          });
          for (const sizeID in sizeGroups) {
            const group = sizeGroups[sizeID];
          const cupItem = inventory.find(i => i.InventoryName === 'Cup' && i.Size === group.sizeName && i.Unit === 'Ounce');
            if (cupItem && cupItem['Current Stock'] < group.qty) {
              showToast(`Insufficient stock for ${group.sizeName}oz cups: need ${group.qty}, have ${cupItem['Current Stock']}`);
              return false;
            }
          }
          return true;
        } catch (err) {
          console.error('Inventory check failed:', err);
          showToast('Unable to check inventory. Please try again.');
          return false;
        }
      }

      // Checkout
      async function loadPaymentReceivers() {
        try {
          const res = await fetch('db/payment_receivers_get.php', { cache: 'no-store' });
          if (!res.ok) throw new Error('Failed to fetch receivers');
          const data = await res.json();
          const list = Array.isArray(data.receivers) ? data.receivers : [];
          return list.reduce((acc, item) => {
            const provider = (item.provider || '').toLowerCase();
            if (!acc[provider]) acc[provider] = [];
            acc[provider].push(item);
            return acc;
          }, {});
        } catch (error) {
          console.warn('Unable to load payment receivers', error);
          return { gcash: [], paymaya: [] };
        }
      }

      function buildReceiverOptions(receivers = []) {
        if (!receivers.length) {
          return '<option value="">No receiver numbers configured</option>';
        }
        return '<option value="">Select receiver number</option>' + receivers.map((receiver) => {
          const label = receiver.label ? `${receiver.label} (${receiver.phone_number})` : receiver.phone_number;
          return `<option value="${escapeHtml(receiver.phone_number)}">${escapeHtml(label)}</option>`;
        }).join('');
      }

      async function openCheckout(){
        if (cart.length === 0) { showToast('Cart is empty', 'warning'); return; }
        if (checkoutInProgress) { return; }
        const hasStock = await checkInventory();
        if (!hasStock) return;
        checkoutToken = generateCheckoutToken();
        const subtotal = cart.reduce((s,i)=> s + (i.price * i.qty), 0);
        const tax = 0; // tax removed
        const total = subtotal + tax;
        const receiversByProvider = await loadPaymentReceivers();
        const modal = createModal(`
          <h3>Checkout</h3>
          <div style="margin-top:8px">
            <div class="small muted">Items: ${cart.length}</div>
            <div style="display:flex;justify-content:space-between;font-size:18px;margin-top:6px"><strong>Total</strong><div>${currency(total)}</div></div>
          </div>
          <div id="cashTenderedSection" style="margin-top:12px; display:none;">
            <label class="small muted" for="cashTenderedInput">Cash Tendered</label>
            <input id="cashTenderedInput" type="number" min="0" step="0.01" style="width:100%;padding:8px;margin-top:6px" placeholder="0.00">
            <div style="display:flex;justify-content:space-between;margin-top:8px">
              <span class="small muted">Change</span>
              <strong id="cashChangeValue">${currency(0)}</strong>
            </div>
          </div>
          <div id="ewalletSection" style="margin-top:12px; display:none;">
            <label class="small muted" for="receiverNumberSelect">Receiver Number</label>
            <select id="receiverNumberSelect" style="width:100%;padding:8px;margin-top:6px">
              ${buildReceiverOptions(receiversByProvider.gcash || [])}
            </select>
            <label class="small muted" for="payerLast4Input" style="margin-top:10px;display:block;">Customer Last 4 Digits</label>
            <input id="payerLast4Input" type="text" inputmode="numeric" maxlength="4" style="width:100%;padding:8px;margin-top:6px" placeholder="1234">
          </div>
          <div style="margin-top:12px;display:flex;gap:8px;justify-content:flex-end;flex-wrap:wrap">
            <button id="payCash" class="pill">Pay Cash</button>
            <button id="payGcash" class="pill">Pay Gcash</button>
            <button id="payPaymaya" class="pill">Pay Maya</button>
            <button id="changePaymentMethod" class="pill" style="display:none">Change Method</button>
            <button id="cancelModal" class="pill">Cancel</button>
          </div>
        `);

        const cashSection = document.getElementById('cashTenderedSection');
        const cashInput = document.getElementById('cashTenderedInput');
        const changeValueEl = document.getElementById('cashChangeValue');
        const payCashBtn = document.getElementById('payCash');
        const payGcashBtn = document.getElementById('payGcash');
        const payPaymayaBtn = document.getElementById('payPaymaya');
        const changePaymentBtn = document.getElementById('changePaymentMethod');
        const ewalletSection = document.getElementById('ewalletSection');

        const setPaymentMode = (mode = '') => {
          const buttons = [
            { key: 'cash', el: payCashBtn },
            { key: 'gcash', el: payGcashBtn },
            { key: 'paymaya', el: payPaymayaBtn }
          ];
          buttons.forEach(({ key, el }) => {
            if (!el) return;
            if (!mode) {
              el.style.display = '';
            } else if (key === mode) {
              el.style.display = '';
            } else {
              el.style.display = 'none';
            }
          });
          if (changePaymentBtn) {
            changePaymentBtn.style.display = mode ? '' : 'none';
          }
        };
        const receiverSelect = document.getElementById('receiverNumberSelect');
        const payerLast4Input = document.getElementById('payerLast4Input');

        // Track if current cash amount is invalid
        let isInvalidAmount = false;

        // Sanitize cash input to remove invalid characters
        const sanitizeCashInput = () => {
          if (!cashInput) return;
          let value = cashInput.value;
          // Remove anything except digits and decimal point (strips -, +, e, E, etc.)
          value = value.replace(/[^0-9.]/g, '');
          // Ensure only one decimal point
          const parts = value.split('.');
          if (parts.length > 2) {
            value = parts[0] + '.' + parts.slice(1).join('');
          }
          // Update input if value changed
          if (cashInput.value !== value) {
            cashInput.value = value;
          }
        };

        const updateChangeDisplay = () => {
          sanitizeCashInput();
          
          const cashValue = parseFloat(cashInput?.value || '0') || 0;
          const changeValue = Math.max(0, cashValue - total);
          
          // Visual feedback based on validity
          if (cashInput.value && cashValue > 0 && cashValue < total) {
            // Invalid amount - less than total
            cashInput.style.borderColor = '#e74c3c';
            cashInput.style.borderWidth = '2px';
            isInvalidAmount = true;
          } else if (cashInput.value && cashValue >= total) {
            // Valid amount - equal or greater than total
            cashInput.style.borderColor = '#27ae60';
            cashInput.style.borderWidth = '2px';
            isInvalidAmount = false;
          } else {
            // Empty or zero
            cashInput.style.borderColor = '';
            cashInput.style.borderWidth = '';
            isInvalidAmount = false;
          }
          
          if (changeValueEl) changeValueEl.textContent = currency(changeValue);
        };

        const openEwalletSection = (provider) => {
          if (cashSection) cashSection.style.display = 'none';
          if (ewalletSection) ewalletSection.style.display = 'block';
          if (receiverSelect) {
            receiverSelect.innerHTML = buildReceiverOptions(receiversByProvider[provider] || []);
          }
          if (payerLast4Input) payerLast4Input.value = '';
        };

        if (cashInput) {
          cashInput.addEventListener('input', updateChangeDisplay);
          
          // Block invalid characters from being typed (-, e, E, +)
          cashInput.addEventListener('keydown', (e) => {
            const invalidKeys = ['-', 'e', 'E', '+'];
            if (invalidKeys.includes(e.key)) {
              e.preventDefault();
              return;
            }
          });
          
          // Clear invalid input when user clicks/focuses the field
          cashInput.addEventListener('focus', () => {
            if (isInvalidAmount) {
              cashInput.value = '';
              cashInput.style.borderColor = '';
              cashInput.style.borderWidth = '';
              isInvalidAmount = false;
              if (changeValueEl) changeValueEl.textContent = currency(0);
            }
          });
        }

        document.getElementById('cancelModal').addEventListener('click', ()=> modal.close());
        changePaymentBtn?.addEventListener('click', () => {
          setPaymentMode('');
          if (cashSection) cashSection.style.display = 'none';
          if (ewalletSection) ewalletSection.style.display = 'none';
          if (payCashBtn) payCashBtn.textContent = 'Pay Cash';
        });
        payCashBtn.addEventListener('click', ()=> {
          if (cashSection && cashSection.style.display === 'none') {
            setPaymentMode('cash');
            cashSection.style.display = 'block';
            if (ewalletSection) ewalletSection.style.display = 'none';
            payCashBtn.textContent = 'Complete Cash';
            if (cashInput) cashInput.focus();
            return;
          }
          updateChangeDisplay();
          const cashValue = parseFloat(cashInput?.value || '0') || 0;
          if (cashValue < total) {
            showToast('Cash tendered must be at least the total amount.');
            return;
          }
          processPayment('Cash', total, modal, cashValue, '', '');
        });
        payGcashBtn.addEventListener('click', ()=> {
          if (ewalletSection && ewalletSection.style.display === 'none') {
            setPaymentMode('gcash');
            openEwalletSection('gcash');
            return;
          }
          const receiverNumber = receiverSelect?.value || '';
          const payerLast4 = (payerLast4Input?.value || '').trim();
          if (!receiverNumber || payerLast4.length !== 4) {
            showToast('Enter receiver number and last 4 digits.');
            return;
          }
          processPayment('Gcash', total, modal, null, receiverNumber, payerLast4);
        });
        payPaymayaBtn.addEventListener('click', ()=> {
          if (ewalletSection && ewalletSection.style.display === 'none') {
            setPaymentMode('paymaya');
            openEwalletSection('paymaya');
            return;
          }
          const receiverNumber = receiverSelect?.value || '';
          const payerLast4 = (payerLast4Input?.value || '').trim();
          if (!receiverNumber || payerLast4.length !== 4) {
            showToast('Enter receiver number and last 4 digits.');
            return;
          }
          processPayment('Paymaya', total, modal, null, receiverNumber, payerLast4);
        });
      }

      function generateCheckoutToken(){
        if (window.crypto && crypto.randomUUID) {
          return crypto.randomUUID();
        }
        return `checkout_${Date.now()}_${Math.random().toString(16).slice(2)}`;
      }

      async function processPayment(method, totalAmount, modal, cashTendered = null, receiverNumber = '', payerLast4 = ''){
        if (checkoutInProgress) {
          return;
        }
        checkoutInProgress = true;
        const payCashBtn = document.getElementById('payCash');
        const payGcashBtn = document.getElementById('payGcash');
        const payPaymayaBtn = document.getElementById('payPaymaya');
        const cancelBtn = document.getElementById('cancelModal');
        if (payCashBtn) payCashBtn.disabled = true;
        if (payGcashBtn) payGcashBtn.disabled = true;
        if (payPaymayaBtn) payPaymayaBtn.disabled = true;
        if (cancelBtn) cancelBtn.disabled = true;
        if (checkoutBtn) checkoutBtn.disabled = true;

        try {
          // Validate cart data before sending
          if (!cart || cart.length === 0) {
            throw new Error('Cart is empty');
          }

          const normalizedMethod = (method || '').toString().toLowerCase();

          if (normalizedMethod !== 'cash') {
            const digits = (payerLast4 || '').replace(/\D/g, '').slice(-4);
            if (!receiverNumber || digits.length !== 4) {
              throw new Error('Receiver number and last 4 digits are required.');
            }
            payerLast4 = digits;
          }

          // Prepare cart items with validation
          const cartItems = cart.map(item => {
            if (!item.productID || !item.price || !item.qty) {
              throw new Error(`Invalid cart item: ${JSON.stringify(item)}`);
            }
            return {
              productID: item.productID,
              variant_id: item.variantId || item.sizeID || null,  // New schema: variant_id
              sizeID: item.variantId || item.sizeID || null,      // Legacy fallback
              quantity: item.qty,
              unitPrice: item.price,
              totalPrice: item.price * item.qty,
              addons: item.addons || [],
              flavor_id: item.flavorId || null   // Include flavor for snacks
            };
          });


          const formData = new FormData();
          formData.append('cartItems', JSON.stringify(cartItems));
          formData.append('paymentMethod', method);
          if (normalizedMethod === 'cash') {
            formData.append('cashTendered', (cashTendered ?? 0).toString());
          } else {
            formData.append('cashTendered', '0');
            formData.append('receiverNumber', receiverNumber);
            formData.append('payerLast4', payerLast4);
          }
          formData.append('cashReceived', totalAmount.toString());
          formData.append('discountType', 'none');
          formData.append('discountPercentage', '0');
          formData.append('checkoutToken', checkoutToken || generateCheckoutToken());


          const res = await fetch('db/checkout_process.php', {
            method: 'POST',
            body: formData
          });


          if (!res.ok) {
            const errorText = await res.text();
            console.error('HTTP Error:', res.status, errorText);
            throw new Error(`HTTP ${res.status}: ${errorText}`);
          }

          const data = await res.json();

          if (data.status === 'success') {
            // Store receipt for potential printing/display
            window._lastReceipt = data.receipt || {};
            const branchLabel = (data.receipt && data.receipt.branchName) ? ` · ${data.receipt.branchName}` : '';
            showToast(`Order completed successfully!${branchLabel}`, 'success');
            modal.close();
            clearCart();
            await loadCloseoutSummary();
            await loadOrders(); // Update the Orders table dynamically
          } else {
            console.error('Backend error:', data.message);
            showToast(data.message || 'Checkout failed');
          }
        } catch (err) {
          console.error('Checkout error details:', err);
          console.error('Error stack:', err.stack);
          showToast(`Checkout failed: ${err.message}`);
        } finally {
          checkoutInProgress = false;
          if (payCashBtn) payCashBtn.disabled = false;
          if (payGcashBtn) payGcashBtn.disabled = false;
          if (payPaymayaBtn) payPaymayaBtn.disabled = false;
          if (cancelBtn) cancelBtn.disabled = false;
          if (checkoutBtn) checkoutBtn.disabled = false;
        }
      }

      async function fetchOrdersData() {
        const response = await fetch('db/orders_get.php');
        const data = await response.json();
        if (data.status !== 'success') {
          throw new Error(data.message || 'Failed to load orders');
        }
        return [...(data.pending || []), ...(data.pending_void || []), ...(data.completed || []), ...(data.cancelled || [])];
      }

      async function loadCloseoutSummary() {
        try {
          const data = await fetchOrdersData();
          orders = data;
          renderOrders();
          await buildCloseoutSummary(orders);
        } catch (error) {
          console.error('Error loading closeout summary:', error);
          showToast('Error loading closeout summary', 'error');
        }
      }

      async function loadOrders(){
        try {
          const data = await fetchOrdersData();
          orders = data;
          renderOrders();
          await buildCloseoutSummary(orders);
        } catch (error) {
          console.error('Error loading orders:', error);
          showToast('Error loading orders', 'error');
        }
      }

      async function buildCloseoutSummary(orderList = orders) {
        const totalEl = document.getElementById('closeoutTotalOrders');
        if (!totalEl) return;
        const grossEl = document.getElementById('closeoutGrossSales');
        const netEl = document.getElementById('closeoutNetSales');
        const timeInEl = document.getElementById('closeoutShiftStart');
        const timeOutEl = document.getElementById('closeoutShiftEnd');
        const cashEl = document.getElementById('closeoutCashTotal');
        const gcashEl = document.getElementById('closeoutGcashTotal');
        const salesBody = document.getElementById('closeoutSalesBody');

        const today = new Date();
        today.setHours(0,0,0,0);

        const todaysOrders = (orderList || []).filter(order => {
          const dateObj = parseOrderTimestamp(order.created_at || order.createdAt);
          return dateObj ? isSameDay(dateObj, today) : false;
        });

        if (todaysOrders.length === 0) {
          latestCloseoutSummary = null;
          totalEl.textContent = '0';
          grossEl.textContent = currency(0);
          netEl.textContent = currency(0);
          if (timeInEl) timeInEl.textContent = '-';
          if (timeOutEl) timeOutEl.textContent = '-';
          if (cashEl) cashEl.textContent = currency(0);
          if (gcashEl) gcashEl.textContent = currency(0);
          if (salesBody) salesBody.innerHTML = '<tr><td colspan="5" class="muted">No sales data for today</td></tr>';
          return;
        }

        updateProductLookups(products);
        await ensureProductCostMap();

        let gross = 0;
        let totalCost = 0;
        let firstOrderTime = null;
        let lastOrderTime = null;
        const paymentTotals = { cash: 0, gcash: 0 };
        const productSales = new Map();

        todaysOrders.forEach(order => {
          const amount = Number(order.totalAmount || 0);
          gross += amount;
          const created = parseOrderTimestamp(order.created_at || order.createdAt);
          if (created) {
            if (!firstOrderTime || created < firstOrderTime) firstOrderTime = created;
            if (!lastOrderTime || created > lastOrderTime) lastOrderTime = created;
          }

          const method = (order.paymentMethod || '').toString().toLowerCase();
          if (method.includes('gcash') || method.includes('wallet') || method.includes('paymaya')) {
            paymentTotals.gcash += amount;
          } else if (method.includes('cash')) {
            paymentTotals.cash += amount;
          }

          const items = Array.isArray(order.items_array) ? order.items_array : parseOrderItems(order.orderSummaryRaw || order.orderSummary);
          items.forEach(item => {
            const qty = Number(item.quantity ?? item.qty ?? 1) || 1;
            const unitPrice = Number(item.unitPrice ?? item.price ?? 0) || 0;
            const productId = item.productID ?? item.productId ?? item.id ?? 0;
            const sizeId = item.sizeID ?? item.sizeId ?? null;
            const productName = productNameLookup[productId] || item.productName || `Product #${productId || ''}`;
            const sizeLabel = item.sizeLabel || sizeNameLookup[sizeId] || item.sizeName || '';
            const lineGross = qty * unitPrice;
            const unitCost = getCostForProduct(productName, sizeLabel);
            const lineCost = unitCost * qty;
            totalCost += lineCost;

            const key = productName;
            const existing = productSales.get(key) || { name: productName, qty: 0, gross: 0, cost: 0 };
            existing.qty += qty;
            existing.gross += lineGross;
            existing.cost += lineCost;
            productSales.set(key, existing);
          });
        });

        const net = gross - totalCost;
        totalEl.textContent = todaysOrders.length.toString();
        grossEl.textContent = currency(gross);
        netEl.textContent = currency(net);
        if (timeInEl) timeInEl.textContent = firstOrderTime ? formatDateTime(firstOrderTime) : '-';
        if (timeOutEl) timeOutEl.textContent = lastOrderTime ? formatDateTime(lastOrderTime) : '-';
        if (cashEl) cashEl.textContent = currency(paymentTotals.cash);
        if (gcashEl) gcashEl.textContent = currency(paymentTotals.gcash);

        const salesRows = [];
        if (salesBody) {
          const rows = Array.from(productSales.values()).sort((a, b) => b.gross - a.gross);
          if (!rows.length) {
            salesBody.innerHTML = '<tr><td colspan="5" class="muted">No sales data for today</td></tr>';
          } else {
            salesBody.innerHTML = '';
            rows.forEach(row => {
              const netValue = row.gross - row.cost;
              salesRows.push({
                name: row.name,
                qty: row.qty,
                gross: row.gross,
                cost: row.cost,
                net: netValue
              });
              const tr = document.createElement('tr');
              tr.innerHTML = `
                <td>${escapeHtml(row.name)}</td>
                <td>${row.qty}</td>
                <td>${currency(row.gross)}</td>
                <td>${currency(row.cost)}</td>
                <td>${currency(netValue)}</td>
              `;
              salesBody.appendChild(tr);
            });
          }
        }

        latestCloseoutSummary = {
          totalOrders: todaysOrders.length,
          gross,
          net,
          payments: paymentTotals,
          shiftStart: firstOrderTime ? firstOrderTime.toISOString() : null,
          shiftEnd: lastOrderTime ? lastOrderTime.toISOString() : null,
          sales: salesRows
        };
      }

      function renderOrders(){
        const tbody = document.getElementById('ordersTableBody');
        tbody.innerHTML = '';
        if (orders.length === 0){
          tbody.innerHTML = '<tr><td colspan="7" class="muted">No orders yet</td></tr>';
          return;
        }
        const searchTerm = (orderSearchInput?.value || '').toLowerCase();
        const selectedRange = currentDateRange;
        let rendered = 0;
        orders.forEach(o => {
          if (!matchesDateFilter(o, selectedRange)) {
            return;
          }
          if (!matchesOrderSearch(o, searchTerm)) {
            return;
          }
          const tr = document.createElement('tr');
          const status = (o.status || 'pending').toLowerCase();
          const isCancelled = status === 'cancelled';
          const isPendingVoid = status === 'pending_void' || status === 'pending-void' || status === 'pending void';
          const statusLabel = isPendingVoid ? 'Pending Void' : (o.status || 'pending');
          
          tr.innerHTML = `<td>${escapeHtml(o.orderID || o.id || 'N/A')}</td>
                          <td>${o.items || 'No items'}</td>
                          <td>${currency(o.totalAmount || o.total || 0)}</td>
                          <td>${escapeHtml(statusLabel)}</td>
                          <td>${escapeHtml(o.referenceNumber || '')}</td>
                          <td>${escapeHtml(formatDateTime(o.created_at || o.createdAt))}</td>
                          <td>
                            ${isCancelled ? '<span style="color: #b91c1c;">Cancelled</span>' : isPendingVoid ? '<span style="color: #6d28d9;">Pending Void</span>' : `<button class="btn-danger" style="padding: 0.4rem 0.8rem; font-size: 0.875rem;" onclick="event.stopPropagation(); voidOrderFromTable(${o.orderID || o.id});">Void</button>`}
                          </td>`;
          tr.style.cursor = 'pointer';
          tr.addEventListener('click', () => openOrderModal(o));
          tbody.appendChild(tr);
          rendered++;
        });
        if (!rendered) {
          tbody.innerHTML = '<tr><td colspan="7" class="muted">No orders found for this filter</td></tr>';
        }
      }

      // Void order from table
      async function voidOrderFromTable(orderID) {
        // Create custom modal for void reason
        const modal = createModal(`
          <h3 style="margin-top: 0; color: #dc3545;">Void Order #${orderID}</h3>
          <p style="margin: 0.5rem 0; color: #6b7280;">Please provide a reason for voiding this order:</p>
          <textarea id="voidReasonInput" 
                    style="width: 100%; min-height: 80px; padding: 0.5rem; border: 1px solid #d1d5db; border-radius: 6px; font-family: inherit; resize: vertical;"
                    placeholder="e.g., Customer requested cancellation, Wrong order, etc."
                    autofocus></textarea>
          <div style="display: flex; gap: 0.5rem; justify-content: flex-end; margin-top: 1rem;">
            <button class="pill" id="cancelVoid" style="background: #6b7280;">Cancel</button>
            <button class="pill" id="confirmVoid" style="background: #dc3545;">Void Order</button>
          </div>
        `);

        const reasonInput = document.getElementById('voidReasonInput');
        const cancelBtn = document.getElementById('cancelVoid');
        const confirmBtn = document.getElementById('confirmVoid');

        // Focus the textarea
        setTimeout(() => reasonInput?.focus(), 100);

        // Handle cancel
        cancelBtn.addEventListener('click', () => {
          modal.close();
        });

        // Handle confirm
        confirmBtn.addEventListener('click', async () => {
          const reason = reasonInput.value.trim();
          
          if (!reason) {
            showToast('Please enter a reason for voiding', 'error');
            reasonInput.focus();
            return;
          }

          // Disable buttons during processing
          confirmBtn.disabled = true;
          cancelBtn.disabled = true;
          confirmBtn.textContent = 'Processing...';

          try {
            const formData = new FormData();
            formData.append('sale_id', orderID);
            formData.append('orderID', orderID);
            formData.append('void_reason', reason);
            formData.append('reason', reason);

            const response = await fetch('db/orders_void.php', {
              method: 'POST',
              body: formData
            });

            const result = await response.json();
            
            if (result.status === 'success' || result.success) {
              showToast('Order voided successfully', 'success');
              modal.close();
              await loadOrders(); // Refresh orders list
              renderOrders();
            } else {
              showToast(result.message || 'Failed to void order', 'error');
              confirmBtn.disabled = false;
              cancelBtn.disabled = false;
              confirmBtn.textContent = 'Void Order';
            }
          } catch (error) {
            console.error('Void order error:', error);
            showToast('Error voiding order', 'error');
            confirmBtn.disabled = false;
            cancelBtn.disabled = false;
            confirmBtn.textContent = 'Void Order';
          }
        });

        // Allow Enter key to submit (but not Shift+Enter for multiline)
        reasonInput.addEventListener('keydown', (e) => {
          if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            confirmBtn.click();
          }
        });
      }

      // Expose to global scope for onclick handler
      window.voidOrderFromTable = voidOrderFromTable;

      const orderSearchInput = document.getElementById('orderSearch');
      let currentDateRange = 'today';
      setupDateFilter();

      if (orderSearchInput) {
        orderSearchInput.addEventListener('input', () => {
          renderOrders();
        });
      }

      const exportCloseoutBtn = document.getElementById('exportCloseoutBtn');
      if (exportCloseoutBtn) {
        exportCloseoutBtn.addEventListener('click', downloadCloseoutReport);
      }

      function matchesOrderSearch(order, term) {
        if (!term) return true;
        const orderId = (order.orderID || order.id || '').toString().toLowerCase();
        const items = (order.items || '').toLowerCase();
        const reference = (order.referenceNumber || order.orderID || order.id || '').toString().toLowerCase();
        return orderId.includes(term) || items.includes(term) || reference.includes(term);
      }

      function openOrderModal(order) {
        const itemsHtml = order.items || 'No items';
        const modal = createModal(`
          <h3>Order Details</h3>
          <div class="order-detail">
            <div><strong>Order ID:</strong> ${escapeHtml(order.orderID || order.id || 'N/A')}</div>
            <div><strong>Date & Time:</strong> ${escapeHtml(formatDateTime(order.created_at || order.createdAt))}</div>
            <div><strong>Payment Method:</strong> ${escapeHtml((order.paymentMethod || 'Unknown').toUpperCase())}</div>
            <div><strong>Total:</strong> ${currency(order.totalAmount || 0)}</div>
            <div><strong>Reference #:</strong> ${escapeHtml(order.referenceNumber || '')}</div>
            <div>
              <strong>Items:</strong>
              <div class="order-items">${itemsHtml}</div>
            </div>
          </div>
          <div style="text-align:right;margin-top:12px;">
            <button class="pill" id="closeOrderDetail">Close</button>
          </div>
        `);
        document.getElementById('closeOrderDetail').addEventListener('click', () => modal.close());
      }

      function setupDateFilter() {
        const buttons = document.querySelectorAll('.order-date-filter button');
        buttons.forEach(btn => {
          if (btn.dataset.range === currentDateRange) {
            btn.classList.add('active');
          }
          btn.addEventListener('click', () => {
            currentDateRange = btn.dataset.range;
            buttons.forEach(b => b.classList.toggle('active', b === btn));
            renderOrders();
          });
        });
      }

      function matchesDateFilter(order, range) {
        if (range === 'all') return true;
        const created = parseOrderTimestamp(order.created_at || order.createdAt || order.timestamp);
        if (!created) return true;
        const today = new Date();
        today.setHours(0,0,0,0);
        const orderDate = new Date(created);
        orderDate.setHours(0,0,0,0);

        if (range === 'today') {
          return orderDate.getTime() === today.getTime();
        }
        if (range === 'yesterday') {
          const yesterday = new Date(today);
          yesterday.setDate(yesterday.getDate() - 1);
          return orderDate.getTime() === yesterday.getTime();
        }
        if (range === 'week') {
          const startOfWeek = new Date(today);
          const day = startOfWeek.getDay();
          const diff = day === 0 ? -6 : 1 - day;
          startOfWeek.setDate(startOfWeek.getDate() + diff);
          startOfWeek.setHours(0,0,0,0);
          const endOfWeek = new Date(startOfWeek);
          endOfWeek.setDate(endOfWeek.getDate() + 7);
          return orderDate >= startOfWeek && orderDate < endOfWeek;
        }
        return true;
      }

      function toCSVField(value) {
        if (value === null || value === undefined) {
          return '""';
        }
        const stringValue = String(value).replace(/"/g, '""');
        return `"${stringValue}"`;
      }

      async function downloadCloseoutReport() {
        if (!latestCloseoutSummary) {
          await buildCloseoutSummary(orders);
        }
        if (!latestCloseoutSummary) {
          showToast('No shift data available to export', 'warning');
          return;
        }

        const summary = latestCloseoutSummary;
        const shiftDate = summary.shiftStart
          ? summary.shiftStart.slice(0, 10)
          : new Date().toISOString().slice(0, 10);
        const formatTimeOnly = (value) => {
          if (!value) return '-';
          const d = new Date(value);
          return isNaN(d) ? '-' : d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        };

        const sheetData = [
          ['Close-Out / End of Shift Report', ''],
          [''],
          ['Shift Date:', shiftDate],
          ['Shift Time In:', formatTimeOnly(summary.shiftStart)],
          ['Shift Time Out:', formatTimeOnly(summary.shiftEnd)],
          [''],
          ['Total Orders (Today):', summary.totalOrders],
          ['Gross Sales (Today):', formatNumber(summary.gross || 0)],
          ['Net Sales (Today):', formatNumber(summary.net || 0)],
          [''],
          ['Payment Breakdown:', ''],
          ['Cash', formatNumber(summary.payments.cash || 0)],
          ['GCash / E-wallet', formatNumber(summary.payments.gcash || 0)],
          [''],
          ['Sales Breakdown by Product', ''],
          ['Product', 'Qty Sold', 'Gross', 'Cost', 'Net'],
          ...summary.sales.map(row => [
            row.name,
            row.qty,
            formatNumber(row.gross || 0),
            formatNumber(row.cost || 0),
            formatNumber(row.net || 0)
          ])
        ];

        // Convert to CSV format
        const csvContent = sheetData
          .map(row => row.map(toCSVField).join(','))
          .join('\n');

        // Create and download CSV file
        const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = `closeout_${shiftDate}.csv`;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        URL.revokeObjectURL(url);
        
        showToast('End-of-shift report exported successfully', 'success');
      }

      // Modal helper
      function createModal(innerHtml){
        const wrapper = document.createElement('div');
        wrapper.className = 'modal-backdrop';
        wrapper.innerHTML = `<div class="modal">${innerHtml}</div>`;
        document.getElementById('modalRoot').appendChild(wrapper);
        wrapper.addEventListener('click', (e)=> { if (e.target === wrapper) close(); });
        function close(){ wrapper.remove(); }
        return { el: wrapper, close };
      }

      // Search and filters
      function applyFilters(){
        const q = (globalSearch.value || '').toLowerCase();
        const cat = categoryFilter.value;
        let filtered = products.filter(p => {
          const matchesQ = p.productName.toLowerCase().includes(q) || (p.categoryName && p.categoryName.toLowerCase().includes(q));
          const matchesCat = !cat || p.categoryID == cat;
          return matchesQ && matchesCat;
        });
        renderProducts(filtered);
      }

      // Global search: switch view to orders if query looks like order id; else filter products
      globalSearch.addEventListener('input', (e)=>{
        const v = (e.target.value || '').trim();
        if (!v) return;
        // If starts with ORD -> switch to orders view and filter table
        if (/^ord/i.test(v)){
          showSection('OrdersForm');
          // filter orders table simple
          const rows = Array.from(document.querySelectorAll('#ordersTableBody tr'));
          rows.forEach(r => {
            const matches = r.innerText.toLowerCase().includes(v.toLowerCase());
            r.style.display = matches ? '' : 'none';
          });
        } else {
          // filter products
          applyFilters();
          showSection('ProductsForm');
        }
      });

      // UI events
      categoryFilter.addEventListener('change', applyFilters);
      clearCartBtn.addEventListener('click', async ()=> {
        const confirmed = await confirmAction('Clear cart?');
        if (confirmed) {
          clearCart();
          showToast('Cart cleared', 'success');
        }
      });
      checkoutBtn.addEventListener('click', openCheckout);
      // Cart button
      cartBtn.addEventListener('click', () => {
        showSection('ProductsForm');
        const cartEl = document.querySelector('.cart');
        if (cartEl) {
          cartEl.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
      });

      // Navigation
      navItems.forEach(n => {
        n.addEventListener('click', ()=> {
          navItems.forEach(x=>x.classList.remove('active'));
          n.classList.add('active');
          showSection(n.dataset.section);
        });
      });
      function showSection(id){
        Object.keys(sections).forEach(k => sections[k].style.display = (k===id) ? 'block' : 'none');
      }

      // Sidebar toggle (mobile)
      document.getElementById('sidebarToggle').addEventListener('click', ()=>{
        document.getElementById('sidebar').style.display = document.getElementById('sidebar').style.display === 'none' ? '' : 'none';
      });

      // Sign out functionality
      document.getElementById('signOutBtn').addEventListener('click', async ()=> {
        const confirmed = await confirmAction('Sign out?');
        if (confirmed) {
          try {
            // Call logout endpoint to clear session
            const response = await fetch('db/logout.php', { method: 'POST' });
            // Redirect to login page regardless of response
            window.location.href = 'login';
          } catch (error) {
            console.error('Logout error:', error);
            // Still redirect even if logout fails
            window.location.href = 'login';
          }
        }
      });



      // Init
      (function init(){
        // try backend first, fallback to mock data if unavailable
        fetchProducts();
        renderCart();
        loadOrders(); // Load orders from backend instead of using mock data
      })();

      // small helper fallback: attempt to fetch categories endpoint (if backend available)
      async function tryLoadCategories(){
        if (!useMock){
          try {
            if (window.DataService) {
              categories = await DataService.fetchCategories();
            } else {
              const res = await fetch('db/categories_getAll.php');
              if (!res.ok) throw new Error('no cat');
              categories = await res.json();
            }
            if (Array.isArray(categories)) {
              updateCategorySelect();
            }
          } catch(e) {
            console.warn('Category preload failed:', e);
          }
        }
      }

      // expose some helpers for integration
      window.POS = {
        addToCart: addToCart,
        clearCart: clearCart,
        getCart: ()=> cart,
        setUseMock: (b)=> { useMock = !!b; fetchProducts(); }
      };

    })();
  </script>

  <script>
    // bfcache logout fix — Safari on Mac restores pages from an in-memory snapshot
    // (bfcache) after logout. When event.persisted is true the page was restored
    // from bfcache; we re-check the session and redirect if it is gone.
    window.addEventListener('pageshow', function (event) {
      if (event.persisted) {
        fetch('db/auth_check_ajax.php', {
          method: 'GET',
          credentials: 'same-origin',
          cache: 'no-store'
        })
        .then(function (res) { return res.json(); })
        .then(function (data) {
          if (!data.authenticated) {
            window.location.replace('login');
          }
        })
        .catch(function () {
          // On any network/parse error, redirect to login to be safe
          window.location.replace('login');
        });
      }
    });
  </script>
</body>
</html>



