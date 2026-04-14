// Dashboard Manager orchestrates analytics cards, charts, and real-time refreshes.
const dashboardData = {
  transactions: 0,
  dailySales: 0,
  expenses: 0,
  profit: 0,
  orders: 0,
  topProducts: [],
  dailySalesChart: [],
  recentTransactions: []
};

document.addEventListener('DOMContentLoaded', () => {
  initializeDashboard();
  setupRealTimeUpdates();
});

async function initializeDashboard() {
  try {
    await loadDashboardData();
    updateDashboardCards();
    updateCharts();
    showToast('Dashboard data loaded successfully', 'success');
  } catch (error) {
    console.error('Error initializing dashboard:', error);
    showToast('Failed to load dashboard data', 'error');
  }
}

async function loadDashboardData(options = {}) {
  const { force = false } = options;

  try {
    const analyticsResponse = await fetch(`db/dashboard_analytics.php?t=${Date.now()}`, { cache: 'no-store' });

    if (!analyticsResponse.ok) {
      throw new Error(`Analytics request failed: ${analyticsResponse.status}`);
    }

    // #region agent log
    const analyticsText = await analyticsResponse.text();
    let analytics;
    try {
      analytics = JSON.parse(analyticsText);
    } catch (parseErr) {
      const snippet = (analyticsText || '').substring(0, 200);
      fetch('http://127.0.0.1:7242/ingest/52f73af2-35b8-4a39-a25a-38e6ac41f884', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ location: 'DashboardManager.js:loadDashboardData', message: 'JSON parse failed', data: { url: 'dashboard_analytics.php', snippet, parseError: String(parseErr && parseErr.message) }, timestamp: Date.now(), hypothesisId: 'A' }) }).catch(() => {});
      throw parseErr;
    }
    // #endregion
    dashboardData.transactions = Number(analytics.today_orders || 0);
    dashboardData.topProducts = Array.isArray(analytics.top_products) ? analytics.top_products : [];
    dashboardData.dailySalesChart = Array.isArray(analytics.chart_data) ? analytics.chart_data : [];
    dashboardData.expenses = Number(analytics.daily_expenses || 0);
    dashboardData.profit = Number(
      analytics.daily_profit ?? (dashboardData.dailySales - dashboardData.expenses)
    );

    await loadTransactionData({ force });
  } catch (error) {
    console.error('Error loading dashboard data:', error);
    throw error;
  }
}

async function loadTransactionData({ force = false } = {}) {
  try {
    const params = new URLSearchParams({ limit: '100' });
    if (force) {
      params.set('_', Date.now().toString());
    }
    const response = await fetch(`db/transactions_get.php?${params.toString()}`, {
      cache: 'no-store'
    });
    if (!response.ok) {
      throw new Error(`Transactions request failed: ${response.status}`);
    }

    const data = await response.json();
    const transactions = Array.isArray(data.transactions) ? data.transactions : [];
    dashboardData.orders = Number(data.count ?? transactions.length ?? 0);
    dashboardData.recentTransactions = transactions;
  } catch (error) {
    console.error('Error loading transactions:', error);
    dashboardData.orders = 0;
    dashboardData.recentTransactions = [];
  }
}

function updateDashboardCards() {
  const setText = (id, value) => {
    const el = document.getElementById(id);
    if (el) el.textContent = value;
  };
  const formatCurrency = value => `\u20B1${Number(value || 0).toFixed(2)}`;

  setText('todayTransactions', dashboardData.transactions);
  setText('dailySalesAmount', formatCurrency(dashboardData.dailySales));
  setText('dailyProfitAmount', formatCurrency(dashboardData.profit));
  setText('dailyExpensesAmount', formatCurrency(dashboardData.expenses));
  setText('todayOrdersCount', dashboardData.orders);
  setText('todayRevenue', formatCurrency(dashboardData.dailySales));
}

function updateCharts() {
  if (typeof displayTop5Products === 'function') {
    displayTop5Products(dashboardData.topProducts);
  } else {
    updateTopProductsFallback();
  }

  if (typeof displayDailySalesChart === 'function') {
    displayDailySalesChart(
      dashboardData.dailySalesChart,
      dashboardData.orders,
      dashboardData.dailySales
    );
  } else {
    updateSalesChartFallback();
  }
}

function updateTopProductsFallback() {
  const listWrapper = document.getElementById('topProductsList');
  if (listWrapper) listWrapper.style.display = 'block';
  const container = document.querySelector('#topProductsDisplay .top-products-content ul');
  if (!container) return;

  if (!dashboardData.topProducts.length) {
    container.innerHTML = '<li class="empty">No orders yet today.</li>';
    return;
  }

  container.innerHTML = dashboardData.topProducts.map((product, index) => `
    <li class="top-product-item">
      <span class="product-rank">${index + 1}.</span>
      <span class="product-name">${escapeHtml(product.productName ?? product.name ?? 'Unknown Product')}</span>
      <span class="product-count">${product.total_orders ?? product.count ?? 0} orders</span>
    </li>
  `).join('');
}

function updateSalesChartFallback() {
  const loadingEl = document.querySelector('#salesChartContainer .sales-chart-loading');
  const contentEl = document.querySelector('#salesChartContainer .sales-chart-content');
  if (loadingEl) loadingEl.style.display = 'none';
  if (contentEl) contentEl.style.display = 'flex';
}

async function refreshDashboard({ silent = false, force = false } = {}) {
  try {
    await loadDashboardData({ force });
    updateDashboardCards();
    updateCharts();
    if (!silent) {
      showToast('Dashboard refreshed', 'success');
    }
  } catch (error) {
    console.error('Failed to refresh dashboard:', error);
    if (!silent) {
      showToast('Unable to refresh dashboard data', 'error');
    }
  }
}

function exportDashboardData() {
  const payload = {
    generatedAt: new Date().toISOString(),
    dashboardData
  };

  const blob = new Blob([JSON.stringify(payload, null, 2)], { type: 'application/json' });
  const url = URL.createObjectURL(blob);
  const anchor = document.createElement('a');
  anchor.href = url;
  anchor.download = `dashboard_export_${new Date().toISOString().split('T')[0]}.json`;
  document.body.appendChild(anchor);
  anchor.click();
  document.body.removeChild(anchor);
  URL.revokeObjectURL(url);

  showToast('Dashboard data exported successfully', 'success');
}

function setupRealTimeUpdates() {
  if (window.realTimeSync && typeof window.realTimeSync.addSyncCallback === 'function') {
    window.realTimeSync.addSyncCallback(() => refreshDashboard({ silent: true }));
  }
}

window.refreshDashboard = refreshDashboard;
window.exportDashboardData = exportDashboardData;
