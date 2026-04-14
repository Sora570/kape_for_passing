const PESO_SIGN = "₱";
const chartRegistry = window.dashboardCharts = window.dashboardCharts || {};

async function loadDashboardAnalytics() {
  const timestamp = Date.now();
  const baseUrl = 'db/dashboard_analytics.php?t=' + timestamp;
  const url = (typeof window.withBranchFilter === 'function') ? window.withBranchFilter(baseUrl) : baseUrl;

  try {
    const response = await fetch(url);
    if (!response.ok) {
      throw new Error('HTTP ' + response.status);
    }

    // #region agent log
    const text = await response.text();
    let data;
    try {
      data = JSON.parse(text);
    } catch (parseErr) {
      fetch('http://127.0.0.1:7242/ingest/52f73af2-35b8-4a39-a25a-38e6ac41f884', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ location: 'dashboard_analytics.js:loadDashboardAnalytics', message: 'JSON parse failed', data: { url: 'dashboard_analytics.php', snippet: (text || '').substring(0, 200) }, timestamp: Date.now(), hypothesisId: 'A' }) }).catch(() => {});
      throw parseErr;
    }
    // #endregion
    if (data.error) {
      throw new Error(data.error);
    }

    displayDailySales(data.daily_sales);
    displayDailySalesChart(data.chart_data, data.today_orders, data.daily_sales);
    displayTop5Products(data.top_products);
  } catch (err) {
    console.error('Failed loading analytics', err);
    displayDailySalesError();
    displayTop5ProductsError();
    displayDailySalesChartError();
  }
}

function displayDailySales(salesAmount) {
  const element = document.getElementById('dailySalesAmount');
  if (!element) {
    console.warn('Sales element not found');
    return;
  }

  const amount = Number(salesAmount || 0);
  element.textContent = PESO_SIGN + amount.toFixed(2);
}

function displayDailySalesError() {
  const element = document.getElementById('dailySalesAmount');
  if (!element) return;

  element.textContent = PESO_SIGN + '0.00';
}

function displayTop5Products(products) {
  const listElement = document.getElementById('topProductsList');
  if (listElement) {
    listElement.style.display = 'block';
  }

  const listContainer = listElement ? listElement.querySelector('#topProductsDisplay .top-products-content ul') : null;
  if (listContainer) {
    listContainer.innerHTML = '';
  }

  renderTopProductsChart(products);

  if (!listContainer) return;

  if (!Array.isArray(products) || products.length === 0) {
    listContainer.innerHTML = '<p style="color:#6b7280; padding: 2rem; text-align:center;">No orders yet today.</p>';
    return;
  }

  const fragment = document.createDocumentFragment();
  products.forEach((product, index) => {
    const listItem = document.createElement('li');
    listItem.className = 'top-product-item flex-between p-1 fs-0 mb-1';
    listItem.style.cssText = [
      'padding: 0.5rem 0.75rem',
      'background: white',
      'border-left: 3px solid var(--brown)',
      'border-radius: 3px',
      'display:flex',
      'justify-content: space-between',
      'align-items:center',
      'margin-bottom: 0.5rem',
      'font-size: 0.875rem'
    ].join('; ');

    const details = document.createElement('div');
    details.style.flex = '1';
    const units = Number(product?.quantity || 0);
    const orders = Number(product?.count || 0);
    const name = escapeHtml(product?.name || product?.productName || 'Unknown Product');
    details.innerHTML = '<span class="fw-600">' + name + '<span><br><small style="color: #6b7280;font-size: 0.75rem">' + units + ' units &bull; ' + orders + ' orders</small></span></span>';

    const rank = document.createElement('div');
    rank.className = 'fw-bold ml-1 fs-0';
    rank.style.cssText = 'color: var(--brown); font-size: 0.75rem;';
    rank.textContent = '#' + (index + 1);

    listItem.append(details);
    listItem.append(rank);
    fragment.appendChild(listItem);
  });

  listContainer.appendChild(fragment);
}

function displayTop5ProductsError() {
  const fallbackEl = document.getElementById('topProductsList');
  if (fallbackEl) {
    fallbackEl.style.display = 'block';
    const listContainer = fallbackEl.querySelector('#topProductsDisplay .top-products-content ul');
    if (listContainer) {
      listContainer.innerHTML = '<p style="padding: 2rem; text-align:center; color: #6b7280;">Unable to load top products.</p>';
    }
  }

  renderTopProductsChart([]);
}

function displayDailySalesChart(chartData, todayOrdersCount, dailySalesValue) {
  const loadingEl = document.querySelector('.sales-chart-loading');
  const contentEl = document.querySelector('.sales-chart-content');

  if (loadingEl) loadingEl.style.display = 'none';
  if (contentEl) contentEl.style.display = 'block';

  const ordersEl = document.getElementById('todayOrdersCount');
  const revenueEl = document.getElementById('todayRevenue');
  if (ordersEl) ordersEl.textContent = String(todayOrdersCount || 0);
  if (revenueEl) revenueEl.textContent = PESO_SIGN + Number(dailySalesValue || 0).toFixed(2);

  renderDailySalesChart(chartData);
}

function displayDailySalesChartError() {
  const contentEl = document.querySelector('.sales-chart-content');
  if (contentEl) contentEl.style.display = 'block';

  const ordersEl = document.getElementById('todayOrdersCount');
  const revenueEl = document.getElementById('todayRevenue');
  if (ordersEl) ordersEl.textContent = '0';
  if (revenueEl) revenueEl.textContent = PESO_SIGN + '0.00';

  renderDailySalesChart([]);
}

function renderTopProductsChart(products) {
  const container = document.querySelector('#bar-chart');
  if (!container || typeof ApexCharts === 'undefined') return;

  destroyChart('topProducts');
  container.innerHTML = '';

  const hasData = Array.isArray(products) && products.length > 0;
  const categories = hasData ? products.map(p => escapeHtml(p?.name || p?.productName || 'Unknown Product')) : ['No sales yet'];
  const quantities = hasData ? products.map(p => Number(p?.quantity || 0)) : [0];
  const orders = hasData ? products.map(p => Number(p?.count || 0)) : [0];

  const options = {
    chart: {
      type: 'bar',
      height: 350,
      background: 'transparent',
      toolbar: { show: false }
    },
    series: [
      { name: 'Units Sold', data: quantities }
    ],
    colors: ['#7f5539'],
    dataLabels: { enabled: false },
    plotOptions: {
      bar: {
        borderRadius: 6,
        columnWidth: '45%'
      }
    },
    xaxis: {
      categories,
      axisBorder: { show: true, color: '#7f5539' },
      axisTicks: { show: true, color: '#7f5539' },
      labels: { style: { colors: '#7f5539' } }
    },
    yaxis: {
      title: { text: 'Units Sold', style: { color: '#7f5539' } },
      labels: { style: { colors: '#7f5539' } }
    },
    tooltip: {
      y: {
        formatter: function(val, opts) {
          const orderVal = orders[opts.dataPointIndex] || 0;
          return val + ' units / ' + orderVal + ' orders';
        }
      }
    },
    grid: {
      borderColor: '#b08968',
      strokeDashArray: 4
    },
    noData: {
      text: 'No orders yet today',
      style: { color: '#6b7280' }
    }
  };

  const chart = new ApexCharts(container, options);
  chartRegistry.topProducts = chart;
  chart.render().catch(function(err) {
    console.error('Failed to render top products chart', err);
  });
}

function renderDailySalesChart(entries) {
  const container = document.querySelector('#daily-sales-chart');
  if (!container || typeof ApexCharts === 'undefined') return;

  destroyChart('dailySales');
  container.innerHTML = '';

  const hasData = Array.isArray(entries) && entries.length > 0;
  const categories = hasData ? entries.map(item => item?.date_label || '') : ['No data'];
  const revenues = hasData ? entries.map(item => Number(item?.revenue || 0)) : [0];
  const orders = hasData ? entries.map(item => Number(item?.orders || 0)) : [0];

  const options = {
    chart: {
      type: 'line',
      height: 350,
      background: 'transparent',
      toolbar: { show: false }
    },
    series: [
      { name: 'Revenue', type: 'area', data: revenues },
      { name: 'Orders', type: 'line', data: orders }
    ],
    colors: ['#9c6644', '#ff8fa3'],
    dataLabels: { enabled: false },
    stroke: {
      curve: 'smooth',
      width: [3, 3]
    },
    fill: {
      type: ['gradient', 'solid'],
      gradient: {
        shadeIntensity: 1,
        opacityFrom: 0.4,
        opacityTo: 0.1,
        stops: [0, 90, 100]
      }
    },
    xaxis: {
      categories,
      axisBorder: { show: true, color: '#7f5539' },
      axisTicks: { show: true, color: '#7f5539' },
      labels: { style: { colors: '#7f5539' } }
    },
    yaxis: [
      {
        title: { text: 'Revenue', style: { color: '#7f5539' } },
        labels: {
          style: { colors: '#7f5539' },
          formatter: function(val) {
            return PESO_SIGN + Number(val).toFixed(0);
          }
        }
      },
      {
        opposite: true,
        title: { text: 'Orders', style: { color: '#7f5539' } },
        labels: { style: { colors: '#7f5539' } }
      }
    ],
    grid: {
      borderColor: '#b08968',
      strokeDashArray: 4
    },
    tooltip: {
      shared: true,
      intersect: false,
      y: [
        {
          formatter: function(val) {
            return PESO_SIGN + Number(val).toFixed(2);
          }
        },
        {
          formatter: function(val) {
            return val + ' orders';
          }
        }
      ]
    },
    noData: {
      text: 'No sales data yet',
      style: { color: '#6b7280' }
    }
  };

  const chart = new ApexCharts(container, options);
  chartRegistry.dailySales = chart;
  chart.render().catch(function(err) {
    console.error('Failed to render daily sales chart', err);
  });
}

function destroyChart(key) {
  if (chartRegistry[key]) {
    try {
      chartRegistry[key].destroy();
    } catch (err) {
      console.warn('Failed to destroy chart', key, err);
    }
    delete chartRegistry[key];
  }
}

document.addEventListener('DOMContentLoaded', function() {
  if (window.USER_ROLE !== 'admin') {
    return;
  }

  const dashboardSection = document.getElementById('DashboardForm');
  if (dashboardSection && dashboardSection.style.display !== 'none') {
    setTimeout(function() {
      loadDashboardAnalytics();
    }, 200);
  }
});

if (typeof window !== 'undefined') {
  window.loadDashboardAnalytics = loadDashboardAnalytics;
}
