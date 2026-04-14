// Real-Time Synchronization System
class RealTimeSync {
    constructor() {
        this.syncInterval = 30000; // 30 seconds
        this.isActive = false;
        this.lastSync = null;
        this.syncCallbacks = [];
    }

    // Initialize real-time sync
    init() {
        this.startSync();
        this.setupEventListeners();
        this.setupProductSync();
        this.setupTransactionSync();
    }

    // Start synchronization
    startSync() {
        if (this.isActive) return;

        this.isActive = true;
        this.syncInterval = setInterval(() => {
            this.performSync();
        }, this.syncInterval);
    }

    // Stop synchronization
    stopSync() {
        if (!this.isActive) return;

        this.isActive = false;
        clearInterval(this.syncInterval);
    }

    // Perform synchronization
    async performSync() {
        try {
            // Sync products
            await this.syncProducts({ force: true });

            // Sync transactions
            await this.syncTransactions();

            // Sync dashboard data
            await this.syncDashboard();

            this.lastSync = new Date();
            this.notifySyncComplete();

        } catch (error) {
            console.error('Sync error:', error);
        }
    }

    // Sync products
    async syncProducts(options) {
        try {
            const requestOptions = Object.assign({ includeInactive: true }, options);
            let data;
            if (window.ProductService) {
                data = await ProductService.fetchProducts(requestOptions);
            } else {
                const response = await fetch('db/products_getAll.php?includeInactive=1&format=payload');
                if (!response.ok) {
                    throw new Error('Failed to fetch products');
                }
                data = await response.json();
            }

            const products = Array.isArray(data?.products) ? data.products : Array.isArray(data) ? data : [];

            document.dispatchEvent(new CustomEvent('productsUpdated', {
                detail: { products }
            }));

            if (window.location.pathname.includes('cashier')) {
                this.updateCashierProducts(products);
            }
        } catch (error) {
            console.error('Product sync error:', error);
        }
    }

    // Sync transactions
    async syncTransactions() {
        try {
            const response = await fetch('db/transactions_get.php?limit=50');
            if (response.ok) {
                const data = await response.json();

                // Dispatch transaction update event
                document.dispatchEvent(new CustomEvent('transactionsUpdated', {
                    detail: { transactions: data.transactions }
                }));
            }
        } catch (error) {
            console.error('Transaction sync error:', error);
        }
    }

    // Sync dashboard
    async syncDashboard() {
        try {
            const response = await fetch('db/dashboard_analytics.php');
            if (response.ok) {
                // #region agent log
                const text = await response.text();
                let data;
                try {
                    data = JSON.parse(text);
                } catch (parseErr) {
                    fetch('http://127.0.0.1:7242/ingest/52f73af2-35b8-4a39-a25a-38e6ac41f884', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ location: 'RealTimeSync.js:syncDashboard', message: 'JSON parse failed', data: { url: 'dashboard_analytics.php', snippet: (text || '').substring(0, 200) }, timestamp: Date.now(), hypothesisId: 'D' }) }).catch(() => { });
                    throw parseErr;
                }
                // #endregion
                // Dispatch dashboard update event
                document.dispatchEvent(new CustomEvent('dashboardUpdated', {
                    detail: { analytics: data }
                }));
            }
        } catch (error) {
            console.error('Dashboard sync error:', error);
        }
    }

    // Update cashier products
    updateCashierProducts(products) {
        // Update cashier product display
        if (typeof window.updateCashierProducts === 'function') {
            window.updateCashierProducts(products);
        }

        // Update product filters
        if (typeof window.updateProductFilters === 'function') {
            window.updateProductFilters(products);
        }
    }

    // Setup event listeners
    setupEventListeners() {
        // Listen for product changes
        document.addEventListener('productAdded', () => {
            this.performSync();
        });

        document.addEventListener('productUpdated', () => {
            this.performSync();
        });

        document.addEventListener('productDeleted', () => {
            this.performSync();
        });

        // Listen for transaction changes
        document.addEventListener('transactionCreated', () => {
            this.performSync();
        });

        // Listen for category changes
        document.addEventListener('categoryAdded', () => {
            this.performSync();
        });

        document.addEventListener('categoryUpdated', () => {
            this.performSync();
        });
    }

    // Setup product synchronization
    setupProductSync() {
        // Override product management functions to trigger sync
        const originalAddProduct = window.addProduct;
        if (originalAddProduct) {
            window.addProduct = async (...args) => {
                const result = await originalAddProduct(...args);
                document.dispatchEvent(new CustomEvent('productAdded'));
                return result;
            };
        }

        const originalUpdateProduct = window.updateProduct;
        if (originalUpdateProduct) {
            window.updateProduct = async (...args) => {
                const result = await originalUpdateProduct(...args);
                document.dispatchEvent(new CustomEvent('productUpdated'));
                return result;
            };
        }
    }

    // Setup transaction synchronization
    setupTransactionSync() {
        // Override transaction functions to trigger sync
        const originalCreateTransaction = window.createTransaction;
        if (originalCreateTransaction) {
            window.createTransaction = async (...args) => {
                const result = await originalCreateTransaction(...args);
                document.dispatchEvent(new CustomEvent('transactionCreated'));
                return result;
            };
        }
    }

    // Add sync callback
    addSyncCallback(callback) {
        this.syncCallbacks.push(callback);
    }

    // Notify sync complete
    notifySyncComplete() {
        this.syncCallbacks.forEach(callback => {
            try {
                callback();
            } catch (error) {
                console.error('Sync callback error:', error);
            }
        });
    }

    // Force immediate sync
    async forceSync() {
        await this.performSync();
    }

    // Get sync status
    getStatus() {
        return {
            isActive: this.isActive,
            lastSync: this.lastSync,
            syncInterval: this.syncInterval
        };
    }
}

// Create global instance
window.realTimeSync = new RealTimeSync();

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    window.realTimeSync.init();
});

// Expose functions globally
window.forceSync = () => window.realTimeSync.forceSync();
window.getSyncStatus = () => window.realTimeSync.getStatus();
window.startSync = () => window.realTimeSync.startSync();
window.stopSync = () => window.realTimeSync.stopSync();



