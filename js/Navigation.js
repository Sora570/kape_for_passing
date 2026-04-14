// Proper role-limited navigation 
function setupCashierMode() {
    const isCashier = window.USER_ROLE === 'cashier' ||
        document.body.classList.contains('cashier-mode') ||
        window.location.pathname.includes('cashier') ||
        sessionStorage.getItem('role') === 'cashier';

    if (isCashier) {
        //  Skip setup if the PHP already hidden the sections
        return true;
    }

    return false;
}

function hideAllSections() {
    const dashboardForm = document.getElementById("DashboardForm");
    if (dashboardForm) dashboardForm.style.display = "none";
    const productsForm = document.getElementById("ProductsForm");
    if (productsForm) productsForm.style.display = "none";
    const inventoryForm = document.getElementById("InventoryForm");
    if (inventoryForm) inventoryForm.style.display = "none";
    const profitTrackerForm = document.getElementById("ProfitTrackerForm");
    if (profitTrackerForm) profitTrackerForm.style.display = "none";
    const transactionsForm = document.getElementById("TransactionsForm");
    if (transactionsForm) transactionsForm.style.display = "none";
    const employeesForm = document.getElementById("EmployeesForm");
    if (employeesForm) employeesForm.style.display = "none";
    const settingsForm = document.getElementById("SettingsForm");
    if (settingsForm) settingsForm.style.display = "none";
    const ordersForm = document.getElementById("OrdersForm");
    if (ordersForm) ordersForm.style.display = "none";

    // Hide sub-order sections only for cashier context
    const pendingOrdersEl = document.getElementById("PendingOrdersForm");
    const completedOrdersEl = document.getElementById("CompletedOrdersForm");
    if (pendingOrdersEl) pendingOrdersEl.style.display = "none";
    if (completedOrdersEl) completedOrdersEl.style.display = "none";
}

function toggleOrdersSubmenu() {
    // Removed as Orders tab is cashier-only and no longer has submenus
    return;
}

function setActiveNav(anchorId) {
    const allItems = document.querySelectorAll('.navigation ul li');
    allItems.forEach(li => li.classList.remove('hovered'));
    const anchor = document.getElementById(anchorId);
    if (anchor && anchor.parentElement && anchor.parentElement.tagName === 'LI') {
        anchor.parentElement.classList.add('hovered');
    }
}

document.getElementById("Dashboard-button")?.addEventListener("click", function () {
    hideAllSections();
    const dashboardForm = document.getElementById("DashboardForm");
    if (dashboardForm) dashboardForm.style.display = "block";
    setActiveNav('Dashboard-button');

    // Trigger analytics load for admin dashboard
    if (window.USER_ROLE === 'admin' && typeof window.loadDashboardAnalytics === 'function') {
        window.loadDashboardAnalytics();
    }

    if (typeof window.refreshDashboard === 'function') {
        window.refreshDashboard({ silent: true, force: true });
    }
});

document.getElementById("ProductsForm-button")?.addEventListener("click", function () {
    hideAllSections();
    const productsForm = document.getElementById("ProductsForm");
    if (productsForm) productsForm.style.display = "block";
    setActiveNav('ProductsForm-button');

    // Load products when tab is opened
    if (typeof loadProducts === 'function') {
        loadProducts();
    }
    if (typeof loadCategories === 'function') {
        loadCategories();
    }
});

document.getElementById("InventoryForm-button")?.addEventListener("click", function () {
    hideAllSections();
    const inventoryForm = document.getElementById("InventoryForm");
    if (inventoryForm) inventoryForm.style.display = "block";
    setActiveNav('InventoryForm-button');

    // Initialize inventory when tab is opened
    if (typeof initializeInventory === "function") {
        initializeInventory();
    }

    if (typeof loadInventoryData === 'function') {
        loadInventoryData();
    }
});

document.getElementById("ProfitTrackerForm-button")?.addEventListener("click", function () {
    hideAllSections();
    const profitForm = document.getElementById("ProfitTrackerForm");
    if (profitForm) profitForm.style.display = "block";
    setActiveNav('ProfitTrackerForm-button');

    // Initialize profit tabs when section is shown
    if (typeof setupProfitTabs === 'function') {
        setupProfitTabs();
    }

    // Ensure PDF export button handler is set up
    const exportPdfBtn = document.getElementById('exportProfitPdfBtn');
    if (exportPdfBtn && typeof exportProfitPdf === 'function') {
        // Remove any existing listeners by cloning the button
        const newBtn = exportPdfBtn.cloneNode(true);
        exportPdfBtn.parentNode.replaceChild(newBtn, exportPdfBtn);
        newBtn.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            if (typeof exportProfitPdf === 'function') {
                exportProfitPdf();
            }
        });
    }

    if (typeof loadProfitTracker === 'function') {
        loadProfitTracker(true);
    }
});

document.getElementById("TransactionsForm-button")?.addEventListener("click", function () {
    hideAllSections();
    const transactionsForm = document.getElementById("TransactionsForm");
    if (transactionsForm) transactionsForm.style.display = "block";
    setActiveNav('TransactionsForm-button');

    // Load transactions when tab is opened
    if (typeof loadTransactions === 'function') {
        loadTransactions();
    }
    if (window.TransactionsManager && typeof window.TransactionsManager.loadTransactions === 'function') {
        window.TransactionsManager.loadTransactions();
    }
});

document.getElementById("EmployeesForm-button")?.addEventListener("click", function () {
    hideAllSections();
    const employeesForm = document.getElementById("EmployeesForm");
    if (employeesForm) employeesForm.style.display = "block";
    setActiveNav('EmployeesForm-button');

    // Load employees when tab is opened
    if (typeof loadEmployees === 'function') {
        loadEmployees();
    }
    if (typeof window.loadEmployees === 'function') {
        window.loadEmployees();
    }
});

document.getElementById("SettingsForm-button")?.addEventListener("click", function () {
    hideAllSections();
    const settingsForm = document.getElementById("SettingsForm");
    if (settingsForm) settingsForm.style.display = "block";
    setActiveNav('SettingsForm-button');

    if (typeof window.refreshSettingsConfig === 'function') {
        window.refreshSettingsConfig();
    }
});

// Orders button - Cashier only
document.getElementById("OrdersForm-button")?.addEventListener("click", function () {
    hideAllSections();
    const ordersForm = document.getElementById("OrdersForm");
    if (ordersForm) ordersForm.style.display = "block";
    setActiveNav('OrdersForm-button');

    // Initialize cashier POS Order view if needed
    if (window.USER_ROLE === 'cashier') {
        if (typeof loadTodaysStats === 'function') {
            loadTodaysStats();
        }
        if (typeof loadProducts === 'function') {
            loadProducts();
        }
    }
});

document.getElementById("product")?.addEventListener("click", function () {
    const dashboardForm = document.getElementById("DashboardForm");
    if (dashboardForm) dashboardForm.style.display = "none";
    const productsForm = document.getElementById("ProductsForm");
    if (productsForm) productsForm.style.display = "block";
    setActiveNav('ProductsForm-button');

    if (typeof loadProducts === 'function') {
        loadProducts();
    }
    if (typeof loadCategories === 'function') {
        loadCategories();
    }
});

document.getElementById("transactions")?.addEventListener("click", function () {
    const dashboardForm = document.getElementById("DashboardForm");
    if (dashboardForm) dashboardForm.style.display = "none";
    const transactionsForm = document.getElementById("TransactionsForm");
    if (transactionsForm) transactionsForm.style.display = "block";
    setActiveNav('TransactionsForm-button');

    if (window.TransactionsManager && typeof window.TransactionsManager.loadTransactions === 'function') {
        window.TransactionsManager.loadTransactions();
    }
});

const REFRESH_INTERVAL_MS = 60000;
let refreshIntervalId = null;

function getVisibleTabId() {
    const visibleSection = Array.from(document.querySelectorAll('section')).find(section => {
        return section.style.display !== 'none' && !section.classList.contains('hidden');
    });
    return visibleSection ? visibleSection.id : '';
}

function refreshActiveTab() {
    const activeId = getVisibleTabId();
    switch (activeId) {
        case 'DashboardForm':
            if (typeof window.refreshDashboard === 'function') {
                window.refreshDashboard({ silent: true, force: true });
            } else if (typeof window.loadDashboardAnalytics === 'function') {
                window.loadDashboardAnalytics();
            }
            break;
        case 'ProductsForm':
            if (typeof loadProducts === 'function') loadProducts();
            if (typeof loadCategories === 'function') loadCategories();
            break;
        case 'InventoryForm':
            if (typeof loadInventoryData === 'function') loadInventoryData();
            break;
        case 'ProfitTrackerForm':
            if (typeof loadProfitTracker === 'function') loadProfitTracker(true);
            break;
        case 'TransactionsForm':
            if (window.TransactionsManager && typeof window.TransactionsManager.loadTransactions === 'function') {
                window.TransactionsManager.loadTransactions();
            }
            break;
        case 'EmployeesForm':
            if (typeof window.loadEmployees === 'function') window.loadEmployees();
            break;
        case 'SettingsForm':
            if (typeof window.refreshSettingsConfig === 'function') window.refreshSettingsConfig();
            break;
        case 'OrdersForm':
            if (window.USER_ROLE === 'cashier') {
                if (typeof loadTodaysStats === 'function') loadTodaysStats();
                if (typeof loadProducts === 'function') loadProducts();
            }
            break;
        default:
            break;
    }
}

function startVisibleTabRefresh() {
    if (refreshIntervalId) return;
    refreshIntervalId = setInterval(refreshActiveTab, REFRESH_INTERVAL_MS);
}

// Ensure the default selected nav is correct on load
window.addEventListener('load', function () {
    // Check for cashier mode first
    const isCashier = setupCashierMode();

    if (isCashier) {
        // Cashiers start with Products tab open by default
        hideAllSections();
        const productsForm = document.getElementById('ProductsForm');
        if (productsForm) productsForm.style.display = 'block';
        setActiveNav('ProductsForm-button');

        // Initialize POS if available
        if (typeof initPOS === 'function') {
            setTimeout(initPOS, 100);
        }
    } else {
        // Admin defaults to Dashboard
        hideAllSections();
        const dashboardForm = document.getElementById('DashboardForm');
        if (dashboardForm) dashboardForm.style.display = 'block';
        setActiveNav('Dashboard-button');

        // Load dashboard analytics if available
        if (typeof window.loadDashboardAnalytics === 'function') {
            setTimeout(() => window.loadDashboardAnalytics(), 200);
        }
    }

    startVisibleTabRefresh();
});

// ----------------- Sign Out -----------------
document.getElementById('SignOutForm-button')?.addEventListener('click', async function (e) {
    e.preventDefault();

    // Show confirmation dialog
    const confirmed = await confirmAction('Are you sure you want to sign out?');

    if (confirmed) {
        fetch('db/logout.php', { method: 'POST' })
            .then(response => response.json())
            .then(data => {
                console.log('Logout successful:', data.message);
                window.location.href = 'login';
            })
            .catch(error => {
                console.error('Logout error:', error);
                // Still redirect even if there's an error
                window.location.href = 'login';
            });
    }
});
