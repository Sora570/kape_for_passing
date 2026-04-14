<?php
require_once __DIR__ . '/db/session_config.php';
require_once __DIR__ . '/db/auth_check.php';
if (!isset($_SESSION['username']) || !in_array($_SESSION['role'], ['admin', 'cashier'])) {
    header("Location: login");
    exit;
}
// Prevent Safari / Mac bfcache from serving a stale authenticated page after logout
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// Determine if user is super admin (no branch restriction)
$isSuperAdmin = (!isset($_SESSION['branch_id']) || $_SESSION['branch_id'] === null);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kape Timplado's</title>
    <link rel="icon" href="assest/icon/icons8-coffee-shop-64.png">
    <link rel="stylesheet" href="css/Dashboard.css">
    <link rel="stylesheet" href="css/TableActions.css">
    <link rel="stylesheet" href="css/Products.css">
    <link rel="stylesheet" href="css/Inventory.css">

    <link rel="stylesheet" href="css/settings.css">
    <link rel="stylesheet" href="css/Transactions.css">
    <link rel="stylesheet" href="css/Employees.css">
    <script src="js/auth_check.js"></script>
</head>
<body class="<?php echo ($_SESSION['role'] === 'cashier') ? 'cashier-mode' : ''; ?>">
<!-- Admin branch filter globals -->
<script>
  window.IS_SUPER_ADMIN = <?php echo $isSuperAdmin ? 'true' : 'false'; ?>;
  window.USER_ROLE      = <?php echo json_encode($_SESSION['role']); ?>;
  window.USER_NAME      = <?php echo json_encode($_SESSION['username']); ?>;

  // Returns current branch_filter value ('' means All Branches)
  window.getAdminBranchFilter = function() {
    if (!window.IS_SUPER_ADMIN) return '';
    return localStorage.getItem('adminBranchFilter') || '';
  };

  // Appends branch_filter to a URL if super admin has a filter selected
  window.withBranchFilter = function(url) {
    var bf = window.getAdminBranchFilter();
    if (!bf) return url;
    var sep = url.includes('?') ? '&' : '?';
    return url + sep + 'branch_filter=' + encodeURIComponent(bf);
  };
</script>
    <div id="container" class="container">
        <!-- ------------------------------------ Navgation Side Bar ------------------------------------ -->
        <div id="navigation" class="navigation">
            <ul>
                <li>
                    <a href="#">
                        <span class="icon"><img src="assest/image/logo.png" class="logo"></span>
                        <span class="title" style="font-size: 1.5em;font-weight: 500; margin-top: 15px;"><?php echo ($_SESSION['role'] === 'cashier') ? 'Cashier - Kape Timplado\'s' : 'Kape Timplado\'s'; ?></span>
                    </a>
                </li>
                <?php if ($_SESSION['role'] === 'admin'): ?>
                <li class="hovered">
					<a href="#" id="Dashboard-button">
						<span class="icon"><ion-icon name="home-outline"></ion-icon></span>
						<span class="title">Dashboard</span>
					</a>
				</li>
                <li>
                    <a href="#" id="ProfitTrackerForm-button">
                        <span class="icon"><ion-icon name="trending-up-outline"></ion-icon></span>
                        <span class="title">Profit & Expenses</span>
                    </a>
                </li>
                <li>
					<a href="#" id="ProductsForm-button">
						<span class="icon"><ion-icon name="fast-food-outline"></ion-icon></span>
						<span class="title">Products</span>
					</a>
				</li>
                <?php endif; ?>
                <?php if ($_SESSION['role'] === 'cashier'): ?>
                <li class="hovered">
                    <a href="#" id="ProductsForm-button">
                        <span class="icon"><ion-icon name="fast-food-outline"></ion-icon></span>
                        <span class="title">Products</span>
                    </a>
                </li>
                <?php endif; ?>
                <?php if ($_SESSION['role'] === 'admin'): ?>
                <li>
					<a href="#" id="InventoryForm-button">
						<span class="icon"><ion-icon name="archive-outline"></ion-icon></span>
						<span class="title">Inventory</span>
					</a>
				</li>
                <li>
					<a href="#" id="TransactionsForm-button">
						<span class="icon"><ion-icon name="card-outline"></ion-icon></span>
						<span class="title">Transactions</span>
					</a>
				</li>
                <li>
					<a href="#" id="EmployeesForm-button">
						<span class="icon"><ion-icon name="people-outline"></ion-icon></span>
						<span class="title">Employees</span>
					</a>
				</li>
                <li>
                    <a href="#" id="SettingsForm-button">
                        <span class="icon"><ion-icon name="cog-outline"></ion-icon></span>
                        <span class="title">Settings</span>
                    </a>
                </li>
                <?php endif; ?>
                <li>
					<a href="#" id="SignOutForm-button">
						<span class="icon"><ion-icon name="log-out-outline"></ion-icon></span>
						<span class="title">Sign Out</span>
					</a>
				</li>
            </ul>
        </div>

        <div class="main">
            <!-- ------------------------------------ Dashboard Form ------------------------------------ -->
            <section id="DashboardForm" <?php echo ($_SESSION['role'] === 'admin') ? '' : 'style="display:none;"'; ?>>
                <div class="topbar">
                    <div class="toggle">
                        <ion-icon name="menu-outline"></ion-icon>
                    </div>
                    <?php if ($isSuperAdmin): ?>
                    <!-- Branch filter — super admin only -->
                    <div class="branch-filter-wrap" id="adminBranchFilterWrap">
                        <ion-icon name="business-outline" style="color:#7f5539;"></ion-icon>
                        <select id="adminBranchFilter" class="branch-filter-select" title="Filter by branch">
                            <option value="">All Branches</option>
                        </select>
                    </div>
                    <?php endif; ?>
                    <!-- user -->
                    <div class="user-container">
                        <div class="user">
                            <img src="assest/image/User Image.jpg" alt="User">
                        </div>
                        <div id="userGreeting" class="user-greeting">
                            <?php echo "Hello, " . htmlspecialchars($_SESSION['username']) . " (" . ucfirst(htmlspecialchars($_SESSION['role'])) . ")!"; ?>
                        </div>
                    </div>
                </div>
    
                <div class="cardBox">
                    <div class="card" id="profitCard">
                        <div>
                            <div class="numbers" id="dailyProfitAmount">&#8369;0.00</div>
                            <div class="cardName">Daily Profit</div>
                        </div>
                        <div class="iconBx">
                            <ion-icon name="trending-up-outline"></ion-icon>
                        </div>
                    </div>
                    <div class="card" id="transactions">
                        <div>
                            <div class="numbers" id="todayTransactions">-</div>
                            <div class="cardName">Today's Transactions</div>
                        </div>
                        <div class="iconBx">
                            <ion-icon name="reader-outline"></ion-icon>
                        </div>
                    </div>
                    <div class="card" id="dailySales">
                        <div>
                            <div class="numbers" id="dailySalesAmount">&#8369;0.00</div>
                            <div class="cardName">Daily Sales</div>
                        </div>
                        <div class="iconBx">
                            <ion-icon name="cash-outline"></ion-icon>
                        </div>
                    </div>
                    <div class="card" id="expenses">
                        <div>
                            <div class="numbers" id="dailyExpensesAmount">&#8369;0.00</div>
                            <div class="cardName">Daily Expenses</div>
                        </div>
                        <div class="iconBx">
                            <ion-icon name="wallet-outline"></ion-icon>
                        </div>
                    </div>    
                </div>
    
                <div class="charts">
                    <div class="charts-card">
                      <h2 class="chart-title">Top 5 Most Ordered Products</h2>
                      <div id="bar-chart">Loading...</div>
                      <div id="topProductsList" style="display:none;">
                        <div id="topProductsDisplay">
                            <div class="top-products-error" style="color: #6b7280; padding: 2rem; text-align: center;">Click retry to load top products.</div>
                            <div class="top-products-content">
                                <ul></ul>
                            </div>
                        </div>
                      </div>
                    </div>
          
                    <div class="charts-card">
                      <h2 class="chart-title">Daily Sales Chart</h2>
                      <div id="dailySalesChart">
                        <div id="salesChartContainer">
                          <div class="sales-chart-loading">Loading daily sales data...</div>
                          <div class="sales-chart-content" style="display:none;">
                            <div id="daily-sales-chart"></div>
                          </div>
                          <div class="sales-chart-summary">
                            <div class="sales-info">
                              <span class="sales-label">Total Orders Today:</span>
                              <span id="todayOrdersCount">0</span>
                            </div>
                            <div class="sales-info">
                              <span class="sales-label">Revenue Today:</span>
                              <span id="todayRevenue">&#8369;0.00</span>
                            </div>
                          </div>
                        </div>
                      </div>
                    </div>
                </div>
            </section>

            <!-- ------------------------------------ Products Form ------------------------------------ -->
            <section id="ProductsForm" style="display:none;">
                <div class="products-root">
                    <div class="products-header">
                        <h1 class="products-title">🍽️ Products <span class="products-subtitle"></span></h1>
                    </div>

                    <!-- Product Actions & Filters -->
                    <div class="products-filters">
                        <div class="filter-row">
                            <button class="btn-primary" id="addProductBtn" onclick="showAddProductModal()">
                                <ion-icon name="add-outline"></ion-icon>
                                Add Product
                            </button>
                            <div class="search-group">
                                <input type="text" id="productSearch" placeholder="Search products..." class="filter-input">
                                <select id="categoryParentFilter" class="filter-select">
                                    <option value="">All Parent Categories</option>
                                </select>
                                <select id="categoryFilter" class="filter-select">
                                    <option value="">All Subcategories</option>
                                </select>
                                <select id="productStatusFilter" class="filter-select">
                                    <option value="">All Status</option>
                                    <option value="active">Active</option>
                                    <option value="inactive">Inactive</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Products Table -->
                    <div class="products-table-container">
                        <table class="products-table">
                            <thead>
                                <tr>
                                    <th>Product Name</th>
                                    <th>Category</th>
                                    <th>Price</th>
                                    <th>Status</th>
                                    <th>Created</th>
                                    <th style="text-align: center;">Actions</th>
                                    <th style="text-align: center;">
                                        Select<br>
                                        <input type="checkbox" id="selectAllProducts" style="margin-top: 4px;">
                                    </th>
                                </tr>
                            </thead>
                            <tbody id="productsTableBody">
                                <!-- Products will be loaded here -->
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

            <!-- ------------------------------------ Inventory Form ------------------------------------ -->
            <section id="InventoryForm" style="display:none;">
                <div class="inventory-root">
                    <div class="inventory-header">
                        <h1 class="inventory-title">📦 Inventory <span class="inventory-subtitle"></span></h1>
                    </div>

                    <!-- Summary Cards -->
                    <div class="inventory-summary">
                        <div class="summary-card">
                            <span class="summary-icon">📦</span>
                            <h3 class="summary-number" id="totalItems">0</h3>
                            <p class="summary-label">Total Items</p>
                        </div>
                        <div class="summary-card">
                            <span class="summary-icon">⚠️</span>
                            <h3 class="summary-number" id="lowStockItems">0</h3>
                            <p class="summary-label">Low Stock</p>
                        </div>
                        <div class="summary-card">
                            <span class="summary-icon">✅</span>
                            <h3 class="summary-number" id="inStockItems">0</h3>
                            <p class="summary-label">In Stock</p>
                        </div>
                        <div class="summary-card">
                            <span class="summary-icon">❌</span>
                            <h3 class="summary-number" id="outOfStockItems">0</h3>
                            <p class="summary-label">Out of Stock</p>
                        </div>
                    </div>

                    <!-- Inventory Filters -->
                    <div class="inventory-filters">
                        <div class="filter-row">
                            <button class="btn-primary" onclick="showAddStockModal()">
                                <ion-icon name="add-outline"></ion-icon>
                                Add Inventory Item
                            </button>
                            <button id="exportInventoryBtn" type="button" class="btn-secondary" onclick="exportInventory()">
                                <ion-icon name="download-outline"></ion-icon>
                                Export CSV
                            </button>
                            <input type="text" id="inventorySearch" placeholder="Search products..." class="filter-input">
                            <select id="stockFilter" class="filter-select" onchange="filterInventory()">
                                <option value="">All Stock Levels</option>
                                <option value="in-stock">In Stock</option>
                                <option value="low-stock">Low Stock</option>
                                <option value="out-of-stock">Out of Stock</option>
                            </select>
                            <select id="costingCategoryFilter" class="filter-select" hidden onchange="renderCostingTable()">
                                <option value="">All Categories</option>
                            </select>
                        </div>
                    </div>

                    <div class="inventory-tabs">
                        <button class="inventory-tab active" data-target="inventoryLowStockPanel">
                            Ingredients
                        </button>
                        <button class="inventory-tab" data-target="inventoryCostingPanel">
                            Costing & Profit
                        </button>
                        <button class="inventory-tab" data-target="inventoryProductionCostPanel">
                            Recipe
                        </button>
                    </div>

                    <div id="inventoryLowStockPanel" class="inventory-tab-panel active">
                        <div class="table-container">
                            <table class="inventory-table">
                                <thead>
                                    <tr>
                                        <th>Ingredient</th>
                                        <th>Unit</th>
                                        <th>Current Stock</th>
                                        <th>Reorder Point</th>
                                        <th>Cost of Pack</th>
                                        <th>Qty / Order</th>
                                        <th>Cost / Order</th>
                                        <th style="text-align: center;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="inventory-lowstock-list"></tbody>
                            </table>
                        </div>
                    </div>

                    <div id="inventoryCostingPanel" class="inventory-tab-panel">
                        <div class="table-container">
                            <table class="inventory-table">
                                <thead>
                                    <tr>
                                        <th>Product</th>
                                        <th>Variant</th>
                                        <th>Category</th>
                                        <th>Selling Price</th>
                                        <th>Cost Price</th>
                                        <th>Profit</th>
                                        <th>Margin</th>
                                    </tr>
                                </thead>
                                <tbody id="inventory-costing-list"></tbody>
                            </table>
                        </div>
                    </div>

                    <div id="inventoryProductionCostPanel" class="inventory-tab-panel">
                        <div style="margin-bottom: 15px;">
                            <label for="productionCostProductSelect" style="font-weight: 600; margin-right: 10px;">Select Product:</label>
                            <select id="productionCostProductSelect" class="filter-select" style="min-width: 250px;">
                                <option value="">Loading products...</option>
                            </select>
                            <button type="button" id="createProductBtn" class="btn-secondary" style="margin-left: 10px; padding: 10px 16px;">
                                + Create New Product
                            </button>
                            <button type="button" id="addIngredientBtn" class="btn-primary" style="margin-left: 10px; padding: 10px 16px;">
                                + Add Ingredient
                            </button>
                            <button type="button" id="copyRecipeBtn" class="btn-secondary" style="margin-left: 10px; padding: 10px 16px; display: none;">
                                Copy Recipe
                            </button>
                        </div>
                        <div id="recipeVariantPanel" style="display:none;margin-bottom:15px;"></div>
                        <div id="productionCostContext" class="inventory-subtitle" style="margin:4px 0 12px;"></div>
                        <div class="table-container">
                            <table class="inventory-table" id="productionCostTable">
                                <thead>
                                    <tr>
                                        <th>Ingredient</th>
                                        <th>Unit</th>
                                        <th>Pack Price</th>
                                        <th>Needed per Cup</th>
                                        <th>Price per Unit</th>
                                        <th>Ingredient Cost</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="production-cost-list"></tbody>
                                <tfoot>
                                    <tr style="background-color: #f0f0f0; font-weight: 600;">
                                        <td colspan="6" style="text-align: right; padding-right: 20px;">Total:</td>
                                        <td id="production-cost-total" style="text-align: center; font-weight: 700; color: #7f5539;">₱0.00</td>
                                        <td></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>

                </div>
            </section>
<?php if ($_SESSION['role'] === 'admin'): ?>
            <!-- ------------------------------------ Profit Tracker Form ------------------------------------ -->
            <section id="ProfitTrackerForm" style="display:none;">
                <div class="profit-tracker-root">
                    <div class="profit-header">
                        <div>
                            <h1 class="inventory-title">📊 Profit & Expenses</h1>
                            <p class="inventory-subtitle"></p>
                        </div>
                        <div class="profit-actions">
                            <button type="button" id="profitTrackerRefresh" class="btn-secondary">
                                <ion-icon name="refresh-outline"></ion-icon>
                                Refresh Data
                            </button>
                            <button type="button" id="exportProfitCsvBtn" class="btn-secondary" onclick="exportProfitTracker()">
                                <ion-icon name="download-outline"></ion-icon>
                                Export CSV
                            </button>
                            <button type="button" id="exportProfitPdfBtn" class="btn-secondary">
                                <ion-icon name="print-outline"></ion-icon>
                                Export PDF
                            </button>
                            <span class="profit-tracker-updated" id="profitTrackerUpdated"></span>
                        </div>
                    </div>

                    <div class="inventory-summary profit-summary">
                        <div class="summary-card">
                            <span class="summary-icon">₱</span>
                            <h3 class="summary-number" id="profitTrackerTotalRevenue">₱0.00</h3>
                            <p class="summary-label">12-Month Sales</p>
                        </div>
                        <div class="summary-card">
                            <span class="summary-icon">🧾</span>
                            <h3 class="summary-number" id="profitTrackerTotalExpenses">₱0.00</h3>
                            <p class="summary-label">12-Month Expenses</p>
                        </div>
                        <div class="summary-card">
                            <span class="summary-icon">📈</span>
                            <h3 class="summary-number" id="profitTrackerTotalProfit">₱0.00</h3>
                            <p class="summary-label">12-Month Profit</p>
                        </div>
                    </div>

                    <div class="profit-tabs">
                        <div class="profit-tab-buttons">
                            <button id="profitTabDay" class="profit-tab active">Day</button>
                            <button id="profitTabWeek" class="profit-tab">Week</button>
                            <button id="profitTabMonth" class="profit-tab">Month</button>
                            <button id="profitTabYear" class="profit-tab">Year</button>
                        </div>

                        <!-- Date Range Filter for Day Tab -->
                        <div class="profit-filters" id="profitFiltersContainer" style="display:none;">
                            <div class="filter-group">
                                <label for="profitDateFrom">Date:</label>
                                <input type="date" id="profitDateFrom" class="filter-input">
                            </div>
                            <div class="filter-group">
                                <label for="profitDateTo" style="margin-left: 0;">to</label>
                                <input type="date" id="profitDateTo" class="filter-input">
                            </div>
                            <button class="btn-primary" id="profitFilterBtn" onclick="filterProfitData()">
                                <ion-icon name="search-outline"></ion-icon>
                                Apply Filter
                            </button>
                            <div id="profitReportInfo"></div>
                        </div>

                        <!-- Year Selector for Year Tab -->
                        <div class="profit-filters" id="profitYearFilterContainer" style="visibility: hidden; opacity: 0; height: 0; padding: 0; margin: 0; transition: all 0.3s ease;">
                            <div class="filter-group">
                                <label for="profitYearSelector">Year:</label>
                                <input type="number" id="profitYearSelector" class="filter-input" min="2020" max="2099" placeholder="2024">
                            </div>
                            <button class="btn-primary" id="profitYearFilterBtn" onclick="applyYearFilter()">
                                <ion-icon name="search-outline"></ion-icon>
                                Apply
                            </button>
                        </div>

                        <div class="profit-tab-content" id="profitTabContent">
                            <div id="profit-content-loading" style="padding:30px; color:#6b7280; text-align:center;">Loading profit data...</div>
                        </div>
                    </div>
                </div>
            </section>
<?php endif; ?>
            

            
            <!-- ------------------ Transactions Tab ------------------ -->
            <section id="TransactionsForm" style="display:none;">
                <div class="transactions-root">
                    <div class="transactions-header">
                        <h1 class="transactions-title">Transactions <span class="transactions-subtitle"></span></h1>
                    </div>

                    <!-- Filter Controls -->
                    <div class="transactions-filters">
                        <div class="filter-row">
                            <select id="transactionStatusFilter" class="filter-select">
                                <option value="">All Status</option>
                                <option value="completed">Completed</option>
                                <option value="pending_void">Pending Void</option>
                                <option value="cancelled">Cancelled</option>
                            </select>
                            <select id="transactionPaymentFilter" class="filter-select">
                                <option value="">All Payment Methods</option>
                                <option value="cash">Cash</option>
                                <option value="gcash">GCash</option>
                                <option value="maya">PayMaya</option>
                            </select>
                            <input type="date" id="transactionDateFilter" class="filter-input">
                            <input type="text" id="transactionSearch" placeholder="Search by transaction ID (numbers only)" class="filter-input" inputmode="numeric" pattern="[0-9]*">
                        </div>
                    </div>

                    <!-- Transactions Table -->
                    <div class="transactions-table-container">
                        <div class="transactions-table-scroll">
                            <table id="transactionsTable" class="transactions-table">
                    <thead>
                            <tr>
                            <th>Transaction ID</th>
                            <th>Reference Number</th>
                            <th>Date & Time</th>
                            <th>CashierID</th>
                            <th>Items</th>
                            <th>Total</th>
                            <th>Payment Method</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                            <tbody id="transactionsTableBody">
                                <!-- Loaded via JavaScript -->
                            </tbody>
                </table>
                        </div>
                        
                        <!-- Summary Cards -->
                        <div class="transactions-summary">
                            <div class="summary-card">
                                <h4>Transactions for <span id="transactionsSummaryDate">Today</span></h4>
                                <span id="todaysRevenue">₱0.00</span>
                            </div>
                            <div class="summary-card">
                                <h4>Transaction Count</h4>
                                <span id="transactionCount">0</span>
                            </div>
                            <div class="summary-card">
                                <h4>Avg Transaction</h4>
                                <span id="averageTransaction">₱0.00</span>
                            </div>
                        </div>

                    </div>
                </div>
            </section>

            <!-- ------------------ Employees Tab ------------------ -->
            <section id="EmployeesForm" style="display:none;">
                <div class="employees-root">
                    <div class="employees-header">
                        <h1 class="employees-title">👥 Employees <span class="employees-subtitle"></span></h1>
                        <div id="employeeOnlineIndicator" class="employee-online-indicator"></div>
                    </div>

                    <!-- Employee Actions & Filters -->
                    <div class="employees-filters">
                        <div class="filter-row">
                            <button id="addEmployeeBtn" class="btn-primary" onclick="showAddEmployeeModal()">
                                <ion-icon name="person-add-outline"></ion-icon>
                                Add Employee
                            </button>
                            <div id="employeeBulkActions" class="employee-bulk-actions">
                                <span class="bulk-count"><strong id="selectedEmployeesCount">0</strong> selected</span>
                                <button id="employeeBulkAttachFileBtn" class="btn-primary" disabled>
                                    <ion-icon name="attach-outline"></ion-icon>
                                    Attach File
                                </button>
                                <button id="employeeBulkDeleteBtn" class="btn-danger" disabled>
                                    <ion-icon name="trash-outline"></ion-icon>
                                    Delete Selected
                                </button>
                            </div>
                            <input type="text" id="employeeSearch" placeholder="Search employees..." class="filter-input">
                            <select id="employeeRoleFilter" class="filter-select">
                                <option value="">All Roles</option>
                                <option value="admin">Admin</option>
                                <option value="cashier">Cashier</option>
                            </select>
                        </div>
                    </div>

                    <!-- Employees Table -->
                    <div class="employees-table-container">
                        <table class="employees-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Username</th>
                                    <th>Employee ID</th>
                                    <th>Role</th>
                                    <th>Created</th>
                                    <th>Last Login</th>
                                    <th style="text-align: center;">Actions</th>
                                    <th style="text-align: center;">
                                        Select<br>
                                        <input type="checkbox" id="employeesSelectAll" class="select-all-checkbox" style="margin-top: 4px;">
                                    </th>
                                </tr>
                            </thead>
                            <tbody id="employeesTableBody">
                                <!-- Employees will be loaded here -->
                            </tbody>
                        </table>
                    </div>

                    <!-- Add Employee Modal -->
                    <div id="addEmployeeModal" class="modal" style="display:none;">
                        <div class="modal-content employee-modal">
                            <span class="close" onclick="closeAddEmployeeModal()">&times;</span>
                            <h2>Add New Employee</h2>
                            
                            <!-- Tab Navigation -->
                            <div class="employee-tabs">
                                <button type="button" class="tab-button active" onclick="switchEmployeeTab('basic')">
                                    <ion-icon name="person-outline"></ion-icon>
                                    Basic Information
                                </button>
                                <button type="button" class="tab-button" onclick="switchEmployeeTab('login')">
                                    <ion-icon name="lock-closed-outline"></ion-icon>
                                    Login Information
                                </button>
                            </div>
                            
                            <form id="addEmployeeForm">
                                <!-- Basic Information Tab -->
                                <div id="basicInfoTab" class="tab-content active">
                                    <div class="form-row">
                                        <div class="form-group">
                                            <label for="employeeFirstName">First Name:</label>
                                            <input type="text" id="employeeFirstName" required pattern="[A-Za-z\s]+" inputmode="text">
                                        </div>
                                        <div class="form-group">
                                            <label for="employeeLastName">Last Name:</label>
                                            <input type="text" id="employeeLastName" required pattern="[A-Za-z\s]+" inputmode="text">
                                        </div>
                                    </div>
                                    <div class="form-group">
                                        <label for="employeeEmail">Email:</label>
                                        <input type="email" id="employeeEmail" required>
                                    </div>
                                    <div class="form-group">
                                        <label for="employeePhone">Phone Number:</label>
                                        <input type="tel" id="employeePhone" required inputmode="numeric" pattern="[0-9]{11}" maxlength="11" placeholder="09123456789">
                                    </div>
                                    <div class="form-group">
                                        <label for="employeeAddress">Address:</label>
                                        <textarea id="employeeAddress" rows="3" required></textarea>
                                    </div>
                                    <div class="tab-navigation">
                                        <button type="button" class="btn-secondary" onclick="closeAddEmployeeModal()">Cancel</button>
                                        <button type="button" class="btn-primary" onclick="switchEmployeeTab('login')">Next: Login Info</button>
                                    </div>
                                </div>
                                
                                <!-- Login Information Tab -->
                                <div id="loginInfoTab" class="tab-content">
                                    <div class="form-group">
                                        <label for="employeeUsername">Username:</label>
                                        <input type="text" id="employeeUsername" required>
                                    </div>
                                    <div class="form-group" id="employeePasswordGroup" style="display:none;">
                                        <label for="employeePassword">Password:</label>
                                        <input type="password" id="employeePassword" autocomplete="new-password">
                                    </div>
                                    <div class="form-group">
                                        <label for="employeeRole">Role:</label>
                                        <select id="employeeRole" required>
                                            <option value="cashier">Cashier</option>
                                            <option value="admin">Admin</option>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label for="employeeBranch">Branch:</label>
                                        <select id="employeeBranch" name="branch_id">
                                            <option value="">Loading branches...</option>
                                        </select>
                                        <small>Assign employee to a branch (for sales and inventory)</small>
                                    </div>
                                    <div class="form-group" id="employeePinGroup">
                                        <label for="employeePin">4-Digit PIN:</label>
                                        <input type="password" id="employeePin" maxlength="4" pattern="[0-9]{4}" placeholder="1234" required inputmode="numeric">
                                        <small>4-digit PIN for POS login</small>
                                    </div>
                                    <div class="form-group">
                                        <label for="employeeId">Employee ID:</label>
                                        <input type="text" id="employeeId" placeholder="EMP001" required>
                                        <small>Unique employee identifier</small>
                                    </div>
                                    <div class="tab-navigation">
                                        <button type="button" class="btn-secondary" onclick="switchEmployeeTab('basic')">Back: Basic Info</button>
                                        <button type="submit" class="btn-primary">Add Employee</button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Attach File Modal -->
            <div id="attachFileModal" class="modal">
                <div class="modal-content employee-modal">
                    <span class="close" onclick="closeAttachFileModal()">&times;</span>
                    <h2>Send File to Employees</h2>
                    
                    <form id="attachFileForm">
                        <div class="form-group">
                            <label for="attachFileInput">Select File:</label>
                            <input type="file" id="attachFileInput" required>
                            <small>Supported formats: PDF, DOC, DOCX, XLS, XLSX, TXT, JPG, PNG (Max 10MB)</small>
                        </div>
                        
                        <div class="form-group">
                            <label for="attachFileMessage">Message (Optional):</label>
                            <textarea id="attachFileMessage" rows="4" placeholder="Add a message to include with the file..."></textarea>
                        </div>

                        <div class="form-group">
                            <label>Recipients:</label>
                            <div id="attachFileRecipientsList" style="max-height: 200px; overflow-y: auto; border: 1px solid #ddd; border-radius: 6px; padding: 10px;">
                                <!-- Recipients will be listed here -->
                            </div>
                        </div>

                        <div class="form-group">
                            <label id="attachFileSelectedCount" style="color: #666; font-weight: normal;">0 recipients selected</label>
                        </div>

                        <div class="tab-navigation">
                            <button type="button" class="btn-secondary" onclick="closeAttachFileModal()">Cancel</button>
                            <button type="submit" class="btn-primary">Send File</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- ------------------------------------ Settings Form ------------------------------------ -->
            <section id="SettingsForm" style="display:none;">
                <div class="settings-root">
                    <div class="settings-header">
                        <h2 class="page-title">⚙️ Settings <span class="page-subtitle"></span></h2>
                        <p class="settings-lede"></p>
                    </div>

                    <div class="settings-tabs-container">
                        <div class="settings-tabs-navigation">
                            <button class="tab-button active" data-settings-tab="configuration">
                                <ion-icon name="build-outline"></ion-icon>
                                <span>Configuration</span>
                            </button>
                            <button class="tab-button" data-settings-tab="audit">
                                <ion-icon name="shield-checkmark-outline"></ion-icon>
                                <span>Audit & Security</span>
                            </button>
                        </div>
                    </div>

                    <div class="settings-content">
                        <div id="settings-configuration-tab" class="settings-tab-content active">
                            <div class="config-card-grid" id="settingsCardGrid">
                                <button class="config-card active" data-module="branches">
                                    <div class="card-icon">🏢</div>
                                    <div class="card-info">
                                        <h4>Branch Management</h4>
                                        <p>Locations, managers, and status</p>
                                    </div>
                                </button>
                                <button class="config-card" data-module="categories">
                                    <div class="card-icon">📂</div>
                                    <div class="card-info">
                                        <h4>Product Categories</h4>
                                        <p>Group drinks, food, and specials</p>
                                    </div>
                                </button>
                                <button class="config-card" data-module="addons">
                                    <div class="card-icon">➕</div>
                                    <div class="card-info">
                                        <h4>Add-ons</h4>
                                        <p>Extra shots, toppings, syrups</p>
                                    </div>
                                </button>
                                <button class="config-card" data-module="flavors">
                                    <div class="card-icon">🎨</div>
                                    <div class="card-info">
                                        <h4>Flavors</h4>
                                        <p>Flavor shots tied to inventory</p>
                                    </div>
                                </button>
                                <button class="config-card" data-module="units">
                                    <div class="card-icon">⚖️</div>
                                    <div class="card-info">
                                        <h4>Measurement Units</h4>
                                        <p>Base units & conversions</p>
                                    </div>
                                </button>
                                <button class="config-card" data-module="payment-receivers">
                                    <div class="card-icon">💳</div>
                                    <div class="card-info">
                                        <h4>Payment Receiver Numbers</h4>
                                        <p>Manage GCash & PayMaya receiver numbers</p>
                                    </div>
                                </button>
                                <button class="config-card" data-module="void-notifications">
                                    <div class="card-icon">📧</div>
                                    <div class="card-info">
                                        <h4>Void Notifications</h4>
                                        <p>Configure who gets emailed when orders are voided</p>
                                    </div>
                                </button>
                            </div>

                            <div id="settingsModulePlaceholder" class="settings-module-placeholder hidden">
                                <p>Select a configuration card to get started.</p>
                            </div>

                            <div id="settingsConfigModules">
                                <section class="settings-module active" data-module="branches">
                                    <div class="module-header">
                                        <div>
                                            <h3>🏢 Branch Management</h3>
                                            <p>Keep Main Branch and Branch 2 aligned with accurate status and location info.</p>
                                        </div>
                                        <div class="module-actions">
                                            <button type="button" class="btn-link" data-action="settings-back">← Back to cards</button>
                                        </div>
                                    </div>
                                    <div class="module-grid">
                                        <div class="module-panel">
                                            <div class="panel-header">
                                                <h4>Branch Directory</h4>
                                                <button type="button" class="btn-secondary" id="refreshBranchesBtn">
                                                    <ion-icon name="refresh-outline"></ion-icon>
                                                    Refresh
                                                </button>
                                            </div>
                                            <div class="table-wrapper">
                                                <table class="settings-table">
                                                    <thead>
                                                        <tr>
                                                            <th>Branch</th>
                                                            <th>Location</th>
                                                            <th>Status</th>
                                                            <th>Actions</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody id="branchTableBody">
                                                        <tr>
                                                            <td colspan="4" class="empty-state">Loading branches...</td>
                                                        </tr>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                        <div class="module-panel form-panel">
                                            <h4 id="branchFormTitle">Add Branch</h4>
                                            <form id="branchForm" class="settings-form">
                                                <input type="hidden" id="branchIdInput" name="branch_id">
                                                <div class="form-group">
                                                    <label for="branchNameInput">Branch Name</label>
                                                    <input type="text" id="branchNameInput" name="branch_name" placeholder="e.g., Main Branch" required>
                                                </div>
                                                <div class="form-group">
                                                    <label for="branchAddressInput">Location / Address</label>
                                                    <textarea id="branchAddressInput" name="address" rows="3" placeholder="Downtown, Cebu City"></textarea>
                                                </div>
                                                <div class="form-group">
                                                    <label for="branchStatusSelect">Status</label>
                                                    <select id="branchStatusSelect" name="status">
                                                        <option value="active">Active</option>
                                                        <option value="inactive">Inactive</option>
                                                    </select>
                                                </div>
                                                <div class="form-actions">
                                                    <button type="submit" class="btn-primary" id="branchSubmitBtn">Save Branch</button>
                                                    <button type="button" class="btn-secondary" id="branchFormReset">Clear</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </section>

                                <section class="settings-module" data-module="categories">
                                    <div class="module-header">
                                        <div>
                                            <h3>📂 Product Categories</h3>
                                            <p>Group coffees, foods, and specials for faster filtering across both stores.</p>
                                        </div>
                                        <div class="module-actions">
                                            <button type="button" class="btn-link" data-action="settings-back">← Back to cards</button>
                                        </div>
                                    </div>
                                    <div class="module-grid">
                                        <div class="module-panel">
                                            <div class="panel-header">
                                                <h4>Category List</h4>
                                                <button type="button" class="btn-secondary" id="refreshCategoriesBtn">
                                                    <ion-icon name="refresh-outline"></ion-icon>
                                                    Refresh
                                                </button>
                                            </div>
                                            <div class="table-wrapper">
                                                <table class="settings-table">
                                                    <thead>
                                                        <tr>
                                                            <th>Category</th>
                                                            <th>Parent</th>
                                                            <th>Status</th>
                                                            <th>Actions</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody id="categoryTableBody">
                                                        <tr>
                                                            <td colspan="4" class="empty-state">Loading categories...</td>
                                                        </tr>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                        <div class="module-panel form-panel">
                                            <h4 id="categoryFormTitle">Add Category</h4>
                                            <form id="categoryForm" class="settings-form">
                                                <input type="hidden" id="categoryIdInput" name="categoryID">
                                                <div class="form-group">
                                                    <label for="categoryNameInput">Category Name</label>
                                                    <input type="text" id="categoryNameInput" name="categoryName" placeholder="e.g., Coffee Based" required>
                                                </div>
                                                <div class="form-group">
                                                    <label for="categoryParentSelect">Parent Category</label>
                                                    <select id="categoryParentSelect" name="parent_id">
                                                        <option value="">None (Top-level)</option>
                                                    </select>
                                                </div>
                                                <div class="form-group">
                                                    <label for="categoryStatusSelect">Status</label>
                                                    <select id="categoryStatusSelect" name="status">
                                                        <option value="active">Active</option>
                                                        <option value="inactive">Inactive</option>
                                                    </select>
                                                </div>
                                                <div class="form-actions">
                                                    <button type="submit" class="btn-primary" id="categorySubmitBtn">Save Category</button>
                                                    <button type="button" class="btn-secondary" id="categoryFormReset">Clear</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </section>

                                <section class="settings-module" data-module="addons">
                                    <div class="module-header">
                                        <div>
                                            <h3>➕ Add-ons</h3>
                                            <p>Standardize pricing for extra shots, syrups, and toppings.</p>
                                        </div>
                                        <div class="module-actions">
                                            <button type="button" class="btn-link" data-action="settings-back">← Back to cards</button>
                                        </div>
                                    </div>
                                    <div class="module-grid">
                                        <div class="module-panel">
                                            <div class="panel-header">
                                                <h4>Add-on Catalog</h4>
                                                <button type="button" class="btn-secondary" id="refreshAddonsBtn">
                                                    <ion-icon name="refresh-outline"></ion-icon>
                                                    Refresh
                                                </button>
                                            </div>
                                            <div class="table-wrapper">
                                                <table class="settings-table">
                                                    <thead>
                                                        <tr>
                                                            <th>Add-on</th>
                                                            <th>Price</th>
                                                            <th>Status</th>
                                                            <th>Actions</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody id="addonTableBody">
                                                        <tr>
                                                            <td colspan="4" class="empty-state">Loading add-ons...</td>
                                                        </tr>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                        <div class="module-panel form-panel">
                                            <h4 id="addonFormTitle">Add Add-on</h4>
                                            <form id="addonForm" class="settings-form">
                                                <input type="hidden" id="addonIdInput" name="addon_id">
                                                <div class="form-group">
                                                    <label for="addonNameInput">Add-on Name</label>
                                                    <input type="text" id="addonNameInput" name="addon_name" placeholder="Extra Shot" required>
                                                </div>
                                                <div class="form-group">
                                                    <label for="addonPriceInput">Price (₱)</label>
                                                    <input type="number" id="addonPriceInput" name="price" min="0" step="0.25" placeholder="15.00" required>
                                                </div>
                                                <div class="form-group">
                                                    <label for="addonStatusSelect">Status</label>
                                                    <select id="addonStatusSelect" name="status">
                                                        <option value="active">Active</option>
                                                        <option value="inactive">Inactive</option>
                                                    </select>
                                                </div>
                                                <div class="form-actions">
                                                    <button type="submit" class="btn-primary" id="addonSubmitBtn">Save Add-on</button>
                                                    <button type="button" class="btn-secondary" id="addonFormReset">Clear</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </section>

                                <section class="settings-module" data-module="flavors">
                                    <div class="module-header">
                                        <div>
                                            <h3>🎨 Flavors</h3>
                                            <p>Map flavor shots to real inventory items to keep costing accurate.</p>
                                        </div>
                                        <div class="module-actions">
                                            <button type="button" class="btn-link" data-action="settings-back">← Back to cards</button>
                                        </div>
                                    </div>
                                    <div class="module-grid">
                                        <div class="module-panel">
                                            <div class="panel-header">
                                                <h4>Flavor Library</h4>
                                                <button type="button" class="btn-secondary" id="refreshFlavorsBtn">
                                                    <ion-icon name="refresh-outline"></ion-icon>
                                                    Refresh
                                                </button>
                                            </div>
                                            <div class="table-wrapper">
                                                <table class="settings-table">
                                                    <thead>
                                                        <tr>
                                                            <th>Flavor</th>
                                                            <th>Inventory Link</th>
                                                            <th>Status</th>
                                                            <th>Actions</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody id="flavorTableBody">
                                                        <tr>
                                                            <td colspan="4" class="empty-state">Loading flavors...</td>
                                                        </tr>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                        <div class="module-panel form-panel">
                                            <h4 id="flavorFormTitle">Add Flavor</h4>
                                            <form id="flavorForm" class="settings-form">
                                                <input type="hidden" id="flavorIdInput" name="flavor_id">
                                                <div class="form-group">
                                                    <label for="flavorNameInput">Flavor Name</label>
                                                    <input type="text" id="flavorNameInput" name="flavor_name" placeholder="Caramel" required>
                                                </div>
                                                <div class="form-group">
                                                    <label for="flavorInventorySelect">Inventory Item (optional)</label>
                                                    <select id="flavorInventorySelect" name="inventory_id">
                                                        <option value="">No inventory link</option>
                                                    </select>
                                                </div>
                                                <div class="form-group">
                                                    <label for="flavorAmountInput">Amount per Serving</label>
                                                    <input type="number" id="flavorAmountInput" name="amount_per_serving" min="0" step="0.1" placeholder="0">
                                                    <small class="field-hint">Used for automated deductions.</small>
                                                </div>
                                                <div class="form-group">
                                                    <label for="flavorStatusSelect">Status</label>
                                                    <select id="flavorStatusSelect" name="status">
                                                        <option value="active">Active</option>
                                                        <option value="inactive">Inactive</option>
                                                    </select>
                                                </div>
                                                <div class="form-actions">
                                                    <button type="submit" class="btn-primary" id="flavorSubmitBtn">Save Flavor</button>
                                                    <button type="button" class="btn-secondary" id="flavorFormReset">Clear</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </section>

                                <section class="settings-module" data-module="units">
                                    <div class="module-header">
                                        <div>
                                            <h3>⚖️ Measurement Units</h3>
                                            <p>Define base units and conversion factors for recipes and costing.</p>
                                        </div>
                                        <div class="module-actions">
                                            <button type="button" class="btn-link" data-action="settings-back">← Back to cards</button>
                                        </div>
                                    </div>
                                    <div class="module-grid">
                                        <div class="module-panel">
                                            <div class="panel-header">
                                                <h4>Units</h4>
                                                <button type="button" class="btn-secondary" id="refreshUnitsBtn">
                                                    <ion-icon name="refresh-outline"></ion-icon>
                                                    Refresh
                                                </button>
                                            </div>
                                            <div class="table-wrapper">
                                                <table class="settings-table">
                                                    <thead>
                                                        <tr>
                                                            <th>Unit</th>
                                                            <th>Symbol</th>
                                                            <th>Conversion</th>
                                                            <th>Base</th>
                                                            <th>Actions</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody id="unitTableBody">
                                                        <tr>
                                                            <td colspan="5" class="empty-state">Loading units...</td>
                                                        </tr>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                        <div class="module-panel form-panel">
                                            <h4 id="unitFormTitle">Add Unit</h4>
                                            <form id="unitForm" class="settings-form">
                                                <input type="hidden" id="unitIdInput" name="unit_id">
                                                <div class="form-group">
                                                    <label for="unitNameInput">Unit Name</label>
                                                    <input type="text" id="unitNameInput" name="unit_name" placeholder="Gram" required>
                                                </div>
                                                <div class="form-group">
                                                    <label for="unitSymbolInput">Symbol</label>
                                                    <input type="text" id="unitSymbolInput" name="unit_symbol" placeholder="g" maxlength="10" required>
                                                </div>
                                                <div class="form-group">
                                                    <label for="unitConversionInput">Conversion Factor</label>
                                                    <input type="number" id="unitConversionInput" name="conversion_factor" min="0.0001" step="0.0001" value="1" required>
                                                    <small class="field-hint">Relative to your base unit.</small>
                                                </div>
                                                <div class="form-group checkbox-field">
                                                    <label>
                                                        <input type="checkbox" id="unitBaseCheckbox" name="is_base_unit">
                                                        Set as base unit
                                                    </label>
                                                </div>
                                                <div class="form-actions">
                                                    <button type="submit" class="btn-primary" id="unitSubmitBtn">Save Unit</button>
                                                    <button type="button" class="btn-secondary" id="unitFormReset">Clear</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </section>

                                <section class="settings-module" data-module="payment-receivers">
                                    <div class="module-header">
                                        <div>
                                            <h3>💳 Payment Receiver Numbers</h3>
                                            <p>Maintain the GCash and PayMaya numbers shown at checkout.</p>
                                        </div>
                                        <div class="module-actions">
                                            <button type="button" class="btn-link" data-action="settings-back">← Back to cards</button>
                                        </div>
                                    </div>
                                    <div class="module-grid">
                                        <div class="module-panel">
                                            <div class="panel-header">
                                                <h4>Receiver Directory</h4>
                                                <button type="button" class="btn-secondary" id="refreshPaymentReceiversBtn">
                                                    <ion-icon name="refresh-outline"></ion-icon>
                                                    Refresh
                                                </button>
                                            </div>
                                            <div class="table-wrapper">
                                                <table class="settings-table">
                                                    <thead>
                                                        <tr>
                                                            <th>Provider</th>
                                                            <th>Label</th>
                                                            <th>Phone Number</th>
                                                            <th>Status</th>
                                                            <th>Actions</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody id="paymentReceiverTableBody">
                                                        <tr>
                                                            <td colspan="5" class="empty-state">Loading receiver numbers...</td>
                                                        </tr>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                        <div class="module-panel form-panel">
                                            <h4 id="paymentReceiverFormTitle">Add Receiver</h4>
                                            <form id="paymentReceiverForm" class="settings-form">
                                                <input type="hidden" id="paymentReceiverIdInput" name="receiver_id">
                                                <div class="form-group">
                                                    <label for="paymentReceiverProvider">Provider</label>
                                                    <select id="paymentReceiverProvider" name="provider" required>
                                                        <option value="gcash">GCash</option>
                                                        <option value="paymaya">PayMaya</option>
                                                    </select>
                                                </div>
                                                <div class="form-group">
                                                    <label for="paymentReceiverLabel">Label (optional)</label>
                                                    <input type="text" id="paymentReceiverLabel" name="label" placeholder="Main counter">
                                                </div>
                                                <div class="form-group">
                                                    <label for="paymentReceiverNumber">Phone Number</label>
                                                    <input type="tel" id="paymentReceiverNumber" name="phone_number" placeholder="09xx xxx xxxx" pattern="^09\d{9}$" maxlength="11" required>
                                                    <small class="field-hint">Use 11 digits starting with 09.</small>
                                                </div>
                                                <div class="form-group">
                                                    <label for="paymentReceiverStatus">Status</label>
                                                    <select id="paymentReceiverStatus" name="status">
                                                        <option value="active">Active</option>
                                                        <option value="inactive">Inactive</option>
                                                    </select>
                                                </div>
                                                <div class="form-actions">
                                                    <button type="submit" class="btn-primary" id="paymentReceiverSubmitBtn">Save Receiver</button>
                                                    <button type="button" class="btn-secondary" id="paymentReceiverFormReset">Clear</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </section>

                                <section class="settings-module" data-module="void-notifications">
                                    <div class="module-header">
                                        <div>
                                            <h3>📧 Void Notifications</h3>
                                            <p>Set the email recipients who should be notified when orders are voided.</p>
                                        </div>
                                        <div class="module-actions">
                                            <button type="button" class="btn-link" data-action="settings-back">← Back to cards</button>
                                        </div>
                                    </div>
                                    <div class="module-grid">
                                        <div class="module-panel form-panel">
                                            <h4>Notification Recipient</h4>
                                            <form id="voidNotificationForm" class="settings-form">
                                                <div class="form-group">
                                                    <label for="voidNotificationEmail">Notification Emails</label>
                                                    <input type="text" id="voidNotificationEmail" name="void_notification_emails" placeholder="owner1@kapetimplado.com, owner2@kapetimplado.com" required>
                                                    <p class="helper-text">Comma-separated list (1–3 emails) for void approval requests. Leave blank to disable.</p>
                                                </div>
                                                <div class="form-actions">
                                                    <button type="submit" class="btn-primary" id="voidNotificationSubmit">Save Email</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </section>
                            </div>
                        </div>

                        <div id="settings-audit-tab" class="settings-tab-content">
                        <!-- Audit Navigation Section -->
                        <div class="audit-navigation-section">
                            <div class="audit-navigation-header">
                                <h3>🛡️ Audit & Security Management</h3>
                                <p class="audit-description">Monitor employee activity and system security logs</p>
                            </div>
                            
                            <div class="audit-navigation-buttons">
                                <button class="audit-nav-button active" onclick="showAuditSection('logs')">
                                    <ion-icon name="list-outline"></ion-icon>
                                    <span>Activity Logs</span>
                                </button>
                                <button class="audit-nav-button" onclick="showAuditSection('security')">
                                    <ion-icon name="shield-outline"></ion-icon>
                                    <span>Security Dashboard</span>
                                </button>
                                <button class="audit-nav-button" onclick="showAuditSection('reports')">
                                    <ion-icon name="document-text-outline"></ion-icon>
                                    <span>Audit Reports</span>
                                </button>
                            </div>
                        </div>

                        <!-- Audit Logs Section -->
                        <div id="audit-logs-section" class="audit-content-section active">
                            <div class="audit-header">
                                <h3>Employee Activity Logs</h3>
                                <div class="audit-controls">
                                    <button class="btn-primary" onclick="exportAuditLogs()">
                                        <ion-icon name="download-outline"></ion-icon>
                                        Export CSV
                                    </button>
                                </div>
                            </div>
                            
                            <div class="audit-filters">
                                <div class="filter-group">
                                    <label for="auditDateFrom">Date Range:</label>
                                    <input type="date" id="auditDateFrom" class="filter-input">
                                    <span>to</span>
                                    <input type="date" id="auditDateTo" class="filter-input">
                                </div>
                                <div class="filter-group">
                                    <label for="auditUserFilter">Employee:</label>
                                    <select id="auditUserFilter" class="filter-select">
                                        <option value="">All Employees</option>
                                    </select>
                                </div>
                                <div class="filter-group">
                                    <label for="auditActionFilter">Action:</label>
                                    <select id="auditActionFilter" class="filter-select">
                                        <option value="">All Actions</option>
                                        <option value="login">Login</option>
                                        <option value="logout">Logout</option>
                                        <option value="failed_login">Failed Login</option>
                                        <option value="product_add">Product Added</option>
                                        <option value="product_update">Product Updated</option>
                                        <option value="product_delete">Product Deleted</option>
                                        <option value="employee_add">Employee Added</option>
                                        <option value="employee_update">Employee Updated</option>
                                        <option value="employee_delete">Employee Deleted</option>
                                    </select>
                                </div>
                                <button class="btn-primary" onclick="filterAuditLogs()">
                                    <ion-icon name="search-outline"></ion-icon>
                                    Filter
                                </button>
                            </div>
                            
                            <div class="audit-table-container">
                                <table class="audit-table">
                                    <thead>
                                        <tr>
                                            <th>Timestamp</th>
                                            <th>Employee</th>
                                            <th>Action</th>
                                            <th>Details</th>
                                            <th>IP Address</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody id="auditLogsBody">
                                        <tr>
                                            <td colspan="6" class="loading-spinner">Loading audit logs...</td>
                                        </tr>
                                    </tbody>
                                </table>
                    </div>

                            <div class="audit-pagination">
                                <button class="btn-secondary" onclick="previousAuditPage()" id="prevAuditBtn" disabled>
                                    <ion-icon name="chevron-back-outline"></ion-icon>
                                    Previous
                                </button>
                                <span id="auditPageInfo">Page 1 of 1</span>
                                <button class="btn-secondary" onclick="nextAuditPage()" id="nextAuditBtn" disabled>
                                    Next
                                    <ion-icon name="chevron-forward-outline"></ion-icon>
                                </button>
                            </div>
                        </div>

                        <!-- Security Dashboard Section -->
                        <div id="audit-security-section" class="audit-content-section">
                            <div class="security-dashboard">
                                <h3>🔒 Security Dashboard</h3>
                                <div class="security-stats-grid">
                                    <div class="security-stat-card">
                                        <div class="stat-icon">
                                            <ion-icon name="warning-outline"></ion-icon>
                                        </div>
                                        <div class="stat-content">
                                            <h4>Failed Logins (24h)</h4>
                                            <p class="stat-value" id="failedLogins">0</p>
                                        </div>
                                    </div>
                                    <div class="security-stat-card">
                                        <div class="stat-icon">
                                            <ion-icon name="people-outline"></ion-icon>
                                        </div>
                                        <div class="stat-content">
                                            <h4>Active Sessions</h4>
                                            <p class="stat-value" id="activeSessions">0</p>
                                        </div>
                                    </div>
                                    <div class="security-stat-card">
                                        <div class="stat-icon">
                                            <ion-icon name="log-in-outline"></ion-icon>
                                        </div>
                                        <div class="stat-content">
                                            <h4>Total Logins Today</h4>
                                            <p class="stat-value" id="totalLogins">0</p>
                                        </div>
                                    </div>
                                    <div class="security-stat-card">
                                        <div class="stat-icon">
                                            <ion-icon name="time-outline"></ion-icon>
                                        </div>
                                        <div class="stat-content">
                                            <h4>Last Activity</h4>
                                            <p class="stat-value" id="lastActivity">-</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Audit Reports Section -->
                        <div id="audit-reports-section" class="audit-content-section">
                            <div class="audit-reports">
                                <h3>📊 Audit Reports</h3>
                                <div class="reports-grid">
                                    <div class="report-card">
                                        <h4>Employee Activity Summary</h4>
                                        <p>Generate comprehensive reports of employee activities</p>
                                        <button class="btn-primary" onclick="generateEmployeeReport()">
                                            <ion-icon name="download-outline"></ion-icon>
                                            Generate Report
                                        </button>
                                    </div>
                                    <div class="report-card">
                                        <h4>Security Events Report</h4>
                                        <p>Export security events and login attempts</p>
                                        <button class="btn-primary" onclick="generateSecurityReport()">
                                            <ion-icon name="shield-outline"></ion-icon>
                                            Generate Report
                                        </button>
                                    </div>
                                    <div class="report-card">
                                        <h4>System Activity Log</h4>
                                        <p>Complete system activity and changes log</p>
                                        <button class="btn-primary" onclick="generateSystemReport()">
                                            <ion-icon name="document-text-outline"></ion-icon>
                                            Generate Report
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        </div>

                    </div>
                </div>
            </section>




            <div id="toast" class="toast"><span id="toast-message"></span></div>


        </div>    

        
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

    <script type="module" src="https://unpkg.com/ionicons@5.5.2/dist/ionicons/ionicons.esm.js"></script>
	<script nomodule src="https://unpkg.com/ionicons@5.5.2/dist/ionicons/ionicons.js"></script>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/apexcharts/3.51.0/apexcharts.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="js/uiToast.js"></script>
    <script src="js/errorMonitor.js"></script>
    <script src="js/dataService.js"></script>
    <script src="js/productService.js"></script>
    <script src="js/modalHelper.js"></script>
  
    <script src="js/Dashboard.js"></script>
    <script src="js/DashboardManager.js"></script>
    <script src="js/RealTimeSync.js"></script>
    <script src="js/Navigation.js"></script>
    <script src="js/ProductSync.js"></script>
    <script src="js/Inventory.js"></script>
    <script src="js/ProductManagement.js"></script>
    <script src="js/SettingsManager.js"></script>
    <?php if ($_SESSION['role'] === 'cashier'): ?>
    <script src="js/POS.js"></script>
    <?php endif; ?>
    <?php if ($_SESSION['role'] === 'admin'): ?>
    <script src="js/dashboard_analytics.js"></script>
    <script src="js/Transactions.js"></script>
    <script src="js/Employees.js"></script>
    <?php endif; ?>

    <script>
        // Transfer PHP session data to JavaScript
        window.USER_ROLE = '<?php echo htmlspecialchars($_SESSION['role'] ?? 'admin'); ?>';
        window.USER_NAME = '<?php echo htmlspecialchars($_SESSION['username'] ?? 'System Administrator'); ?>';
        console.log('User role set as:', window.USER_ROLE);
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

    <?php if ($isSuperAdmin): ?>
    <style>
        .branch-filter-wrap {
            display: flex;
            align-items: center;
            gap: 6px;
            background: rgba(127,85,57,0.08);
            border: 1px solid rgba(127,85,57,0.25);
            border-radius: 8px;
            padding: 5px 10px;
            margin-right: auto;
            margin-left: 16px;
            transition: background 0.2s;
        }
        .branch-filter-wrap:focus-within {
            background: rgba(127,85,57,0.15);
            border-color: #7f5539;
        }
        .branch-filter-select {
            background: transparent;
            border: none;
            outline: none;
            font-size: 13px;
            font-weight: 600;
            color: #7f5539;
            cursor: pointer;
            min-width: 140px;
        }
        .branch-filter-select option { color: #333; background: #fff; }
    </style>
    <script>
    (function() {
        var sel = document.getElementById('adminBranchFilter');
        if (!sel) return;

        // Restore persisted selection
        var saved = localStorage.getItem('adminBranchFilter') || '';
        sel.value = saved;

        // Load branch options from API
        fetch('db/branches_getAll.php')
            .then(function(r){ return r.json(); })
            .then(function(data) {
                var branches = Array.isArray(data) ? data : (data.branches || []);
                branches.forEach(function(b) {
                    var opt = document.createElement('option');
                    opt.value = b.branch_id;
                    opt.textContent = b.branch_name;
                    if (String(b.branch_id) === String(saved)) opt.selected = true;
                    sel.appendChild(opt);
                });
            })
            .catch(function(e){ console.warn('Could not load branches for filter:', e); });

        // On change: persist and reload all active data
        sel.addEventListener('change', function() {
            localStorage.setItem('adminBranchFilter', sel.value);

            // Reload whichever tab is currently visible
            if (typeof window.loadDashboardAnalytics === 'function') {
                var dash = document.getElementById('DashboardForm');
                if (dash && dash.style.display !== 'none') window.loadDashboardAnalytics();
            }
            if (window.TransactionsManager && typeof window.TransactionsManager.loadTransactions === 'function') {
                var tx = document.getElementById('TransactionsForm');
                if (tx && tx.style.display !== 'none') window.TransactionsManager.loadTransactions();
            }
            if (typeof window.loadProfitTracker === 'function') {
                var pt = document.getElementById('ProfitTrackerForm');
                if (pt && pt.style.display !== 'none') window.loadProfitTracker(true);
            }
            if (typeof window.loadInventoryData === 'function') {
                var inv = document.getElementById('InventoryForm');
                if (inv && inv.style.display !== 'none') window.loadInventoryData();
            }
        });
    })();
    </script>
    <?php endif; ?>
</body>
</html>



