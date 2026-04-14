// Real-time Product Synchronization
// This ensures product changes in admin view reflect immediately in cashier view

class ProductSync {
    constructor() {
        this.lastSyncTime = null;
        this.syncInterval = 5000; // Sync every 5 seconds
        this.isCashier = this.detectCashierMode();
        this.init();
    }

    detectCashierMode() {
        return window.location.pathname.includes('cashier') || 
               document.body.classList.contains('cashier-mode') ||
               sessionStorage.getItem('role') === 'cashier';
    }

    init() {
        if (this.isCashier) {
            this.startCashierSync();
        } else {
            this.startAdminSync();
        }
    }

    startCashierSync() {
        // Initial load
        this.syncProducts();
        
        // Set up periodic sync
        setInterval(() => {
            this.syncProducts();
        }, this.syncInterval);

        // Listen for storage events (cross-tab communication)
        window.addEventListener('storage', (e) => {
            if (e.key === 'products_updated') {
                this.syncProducts();
            }
        });

        // Listen for custom events
        window.addEventListener('productsUpdated', (event) => {
            // Handle different types of updates
            if (event.detail && event.detail.action === 'image_uploaded') {
                // Show notification for image updates
                if (typeof showToast === 'function') {
                    showToast('Product image updated!', 'success');
                } else {
                    // Fallback notification
                    console.log('Product image has been updated in another tab!');
                }
            }
            
            this.syncProducts();
        });
    }

    startAdminSync() {
        // Override product management functions to trigger sync
        this.overrideProductFunctions();
    }

    overrideProductFunctions() {
        // Override the addCategory function
        const originalAddCategory = window.addCategory;
        if (originalAddCategory) {
            window.addCategory = (categoryName) => {
                originalAddCategory(categoryName);
                this.notifyProductUpdate('category_added', { categoryName });
            };
        }

        // Override the addSize function
        const originalAddSize = window.addSize;
        if (originalAddSize) {
            window.addSize = (sizeName, price) => {
                originalAddSize(sizeName, price);
                this.notifyProductUpdate('size_added', { sizeName, price });
            };
        }

        // Override the addProduct function
        const originalAddProduct = window.addProduct;
        if (originalAddProduct) {
            window.addProduct = (productName, categoryID) => {
                originalAddProduct(productName, categoryID);
                this.notifyProductUpdate('product_added', { productName, categoryID });
            };
        }
    }

    async syncProducts() {
        try {
            // Load products
            if (typeof loadProducts === 'function') {
                await loadProducts();
            }
            
            // Load categories
            if (typeof loadCategories === 'function') {
                await loadCategories();
            }
            
            // Load sizes
            if (typeof loadSizes === 'function') {
                await loadSizes();
            }

        } catch (error) {
            console.error('Error syncing products:', error);
        }
    }

    notifyProductUpdate(action, data) {
        // Store in localStorage for cross-tab communication
        localStorage.setItem('products_updated', JSON.stringify({
            action,
            data,
            timestamp: Date.now()
        }));
        
        // Trigger custom event
        window.dispatchEvent(new CustomEvent('productsUpdated', {
            detail: { action, data }
        }));
        
        // Clear the storage after a short delay
        setTimeout(() => {
            localStorage.removeItem('products_updated');
        }, 1000);
    }
}

// Initialize product sync when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    new ProductSync();
});

// Export for global access
window.ProductSync = ProductSync;
