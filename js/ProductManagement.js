// Modern Product Management System
let categories = [];
let activeCategories = [];
let products = [];
window.units = window.units || [];

// Initialize product management
document.addEventListener('DOMContentLoaded', function () {
    loadCategories();
    loadProducts();
    setupProductEventListeners();
});

// Event Listeners
function setupProductEventListeners() {
    // Category management
    document.getElementById('addCategory')?.addEventListener('click', addCategory);
    document.getElementById('newCategory')?.addEventListener('keypress', function (e) {
        if (e.key === 'Enter') addCategory();
    });

    // Product filtering
    document.getElementById('categoryParentFilter')?.addEventListener('change', handleParentCategoryChange);
    document.getElementById('categoryFilter')?.addEventListener('change', filterProducts);
    document.getElementById('productSearch')?.addEventListener('input', filterProducts);
    document.getElementById('productStatusFilter')?.addEventListener('change', () => {
        loadProducts({ force: true });
        filterProducts();
    });

    // Select all checkbox
    const selectAllCheckbox = document.getElementById('selectAllProducts');
    if (selectAllCheckbox) {
        selectAllCheckbox.addEventListener('change', handleSelectAllProducts);
    }

    // Price editing event listeners
    document.addEventListener('change', handlePriceChange);
    document.addEventListener('change', handleSizeUnitChange);

    // Checkbox event listeners
    document.addEventListener('change', handleProductCheckboxChange);
}

// Load Categories
async function loadCategories(options) {
    try {
        let data;
        let activeData;
        if (window.DataService) {
            [data, activeData] = await Promise.all([
                DataService.fetchCategories(options),
                DataService.fetchActiveCategories(options)
            ]);
        } else {
            const [allResponse, activeResponse] = await Promise.all([
                fetch('db/categories_getAll.php'),
                fetch('db/categories_get.php')
            ]);
            data = await allResponse.json();
            activeData = await activeResponse.json();
        }
        categories = Array.isArray(data) ? data : [];
        activeCategories = Array.isArray(activeData) ? activeData : [];
        renderCategories();
        updateCategoryFilter();
    } catch (error) {
        console.error('Error loading categories:', error);
        showToast('Failed to load categories', 'error');
    }
}

// Load Products
async function loadProducts(options) {
    try {
        const requestOptions = Object.assign({ includeInactive: true }, options);
        let productsData;
        const statusFilter = document.getElementById('productStatusFilter');
        const statusValue = statusFilter ? statusFilter.value : '';
        requestOptions.status = statusValue;
        const statusParam = statusValue ? `&status=${encodeURIComponent(statusValue)}` : '';

        if (window.ProductService) {
            productsData = await ProductService.fetchProducts(requestOptions);
        } else {
            const response = await fetch(`db/products_getAll.php?includeInactive=1&format=payload${statusParam}`);
            productsData = await response.json();
        }

        const unitsData = await (window.DataService
            ? DataService.fetchUnits()
            : fetch('db/product_units_get.php').then(r => r.json()));

        products = Array.isArray(productsData?.products)
            ? productsData.products
            : Array.isArray(productsData)
                ? productsData
                : [];
        window.units = unitsData; // Store units globally
        renderProducts();
    } catch (error) {
        console.error('Error loading products:', error);
        showToast('Failed to load products', 'error');
    }
}

// Render Categories
function renderCategories() {
    const container = document.getElementById('categoryList');
    if (!container) return;

    if (categories.length === 0) {
        container.innerHTML = '<div class="empty-state">No categories found</div>';
        return;
    }

    container.innerHTML = categories.map(cat => `
        <div class="list-item">
            <span class="item-name">${escapeHtml(cat.categoryName)}</span>
            <div class="item-actions">
                <button class="btn-small btn-primary" onclick="editCategory(${cat.categoryID})">Edit</button>
                <button class="btn-small btn-danger" onclick="deleteCategory(${cat.categoryID})">Delete</button>
            </div>
        </div>
    `).join('');
}

// Render Products
async function renderProducts(productList = products) {
    const tbody = document.getElementById('productsTableBody');
    if (!tbody) return;

    const selectAllCheckbox = document.getElementById('selectAllProducts');
    if (selectAllCheckbox) {
        selectAllCheckbox.checked = false;
        selectAllCheckbox.indeterminate = false;
    }

    const sortedProducts = [...productList].sort((a, b) => {
        const categoryA = (a.categoryName || '').toLowerCase();
        const categoryB = (b.categoryName || '').toLowerCase();
        if (categoryA !== categoryB) {
            return categoryA.localeCompare(categoryB);
        }
        return Number(a.productID) - Number(b.productID);
    });

    if (sortedProducts.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="6" class="text-center">
                    <div class="empty-state">
                        <div style="font-size: 48px; margin-bottom: 12px;">📦</div>
                        No products found
                    </div>
                </td>
            </tr>
        `;
        updateMassDeleteButtonVisibility();
        updateBulkStatusButtonVisibility();
        return;
    }

    // Format created_at to military time
    const formatToMilitaryTime = (dateStr) => {
        if (!dateStr || dateStr === 'N/A') return 'N/A';
        const date = new Date(dateStr);
        if (isNaN(date)) return dateStr;
        return date.toISOString().slice(0, 19).replace('T', ' ');
    };

    // Build price display from product.sizes / product.variants (variant or base_price) or fetch from API
    const priceFromProduct = (p) => {
        const list = Array.isArray(p.sizes) ? p.sizes : (Array.isArray(p.variants) ? p.variants : []);
        if (list.length === 0 && p.base_price != null) {
            return `₱${Number(p.base_price).toFixed(2)}`;
        }
        if (list.length === 1) {
            return `₱${Number(list[0].price).toFixed(2)}`;
        }
        if (list.length > 1) {
            return list.map(s => `${s.size_label || s.name || s.variant_name || ''} ₱${Number(s.price).toFixed(2)}`).join(' | ');
        }
        return null;
    };

    const getPricingDisplay = async (productID, product) => {
        const fromProduct = product && priceFromProduct(product);
        if (fromProduct) return fromProduct;
        try {
            const response = await fetch(`db/product_prices_get.php?productID=${productID}`);
            const prices = await response.json();
            if (!Array.isArray(prices) || prices.length === 0) {
                return '<span class="text-muted">No pricing set</span>';
            }
            const pricesBySize = {};
            prices.forEach(p => {
                const label = p.size_label || 'Default';
                if (!pricesBySize[label]) pricesBySize[label] = [];
                pricesBySize[label].push(`₱${parseFloat(p.price).toFixed(2)}`);
            });
            const entries = Object.entries(pricesBySize);
            if (entries.length === 1 && (entries[0][0] === 'Single' || entries[0][0] === 'Default')) {
                return entries[0][1][0];
            }
            return entries.map(([size, pr]) => `<span>${size} ${pr.join(', ')}</span>`).join(' <span style="color: #ccc;">|</span> ');
        } catch (error) {
            console.error('Error fetching prices:', error);
            return '<span class="text-muted">Error loading price</span>';
        }
    };

    // Render products with placeholders for pricing
    tbody.innerHTML = sortedProducts.map(product => {
        const initialPrice = priceFromProduct(product) || '<span class="text-muted">Loading...</span>';
        return `
            <tr data-product-id="${product.productID}" class="product-row">
                <td data-label="Product Name">
                    <strong>${escapeHtml(product.productName)}</strong>
                </td>
                <td data-label="Category">${escapeHtml(product.categoryName || ' Unknown')}</td>
                <td data-label="Price" id="price-${product.productID}">
                    ${initialPrice}
                </td>
                <td data-label="Status">
                    <span class="status-badge ${product.isActive ? 'active' : 'inactive'}">
                        ${product.isActive ? 'Active' : 'Inactive'}
                    </span>
                </td>
                <td data-label="Created">${formatToMilitaryTime(product.created_at)}</td>
                <td data-label="Actions">
                    <div class="action-buttons">
                        <button class="action-btn action-btn-edit" onclick="editProduct(${product.productID})" title="Edit">
                            <ion-icon name="create-outline"></ion-icon>
                        </button>
                        <button class="action-btn action-btn-delete" onclick="deleteProduct(${product.productID})" title="Delete">
                            <ion-icon name="trash-outline"></ion-icon>
                        </button>
                    </div>
                </td>
                <td data-label="Select">
                    <input type="checkbox" class="product-checkbox" data-product-id="${product.productID}" style="text-align: center;">
                </td>
            </tr>
        `;
    }).join('');

    // Load pricing asynchronously when not already from product.sizes/variants
    sortedProducts.forEach(async (product) => {
        const priceCell = document.getElementById(`price-${product.productID}`);
        if (!priceCell) return;
        const pricingDisplay = await getPricingDisplay(product.productID, product);
        priceCell.innerHTML = pricingDisplay;
    });

    updateMassDeleteButtonVisibility();
}

// Add Category
async function addCategory() {
    const input = document.getElementById('newCategory');
    const name = input.value.trim();

    if (!name) {
        showToast('Please enter a category name', 'error');
        return;
    }

    try {
        const formData = new FormData();
        formData.append('categoryName', name);
        const response = await fetch('db/categories_add.php', {
            method: 'POST',
            body: formData
        });

        // Get response text first (can only read once)
        const responseText = await response.text();

        // Check if response is OK
        if (!response.ok) {
            console.error('Server error response:', responseText);
            showToast('Server error: ' + response.status, 'error');
            return;
        }

        // Try to parse as JSON
        let result;
        try {
            result = JSON.parse(responseText);
        } catch (jsonError) {
            // If JSON parsing fails, show the actual response
            console.error('Invalid JSON response:', responseText);
            showToast('Server returned invalid response. Please check console for details.', 'error');
            return;
        }

        if (result.status === 'success') {
            showToast('Category added successfully', 'success');
            input.value = ''; // Clear input
            closeAddCategoryModal();
            if (window.DataService) {
                DataService.invalidateCategories();
            }
            loadCategories({ force: true });
        } else {
            showToast(result.message || 'Failed to add category', 'error');
        }
    } catch (error) {
        console.error('Error adding category:', error);
        showToast('Failed to add category: ' + error.message, 'error');
    }
}

// Add Size
async function addSize() {
    const nameInput = document.getElementById('newSizeName');
    const name = nameInput.value.trim();

    if (!name) {
        showToast('Please enter a size value', 'error');
        return;
    }

    try {
        const formData = new FormData();
        formData.append('sizeName', name);
        formData.append('defaultPrice', 0); // Default price is 0

        const response = await fetch('db/sizes_add.php', {
            method: 'POST',
            body: formData
        });

        const result = await response.json();

        if (result.status === 'success') {
            showToast('Size added successfully', 'success');
            if (window.DataService) {
                DataService.invalidateSizes();
            }
            loadSizes({ force: true }); // Reload sizes in main page
        } else {
            showToast(result.message || 'Failed to add size', 'error');
        }
    } catch (error) {
        console.error('Error adding size:', error);
        showToast('Failed to add size', 'error');
    }
}

// Update Category Filter
function updateCategoryFilter() {
    const parentFilter = document.getElementById('categoryParentFilter');
    const subFilter = document.getElementById('categoryFilter');
    if (!parentFilter || !subFilter) return;

    const parentCategories = activeCategories.filter(cat => cat.parent_id === null);
    const childCategories = activeCategories.filter(cat => cat.parent_id !== null);

    parentFilter.innerHTML = '<option value="">All Parent Categories</option>' +
        parentCategories.map(cat => `<option value="${cat.categoryID}">${escapeHtml(cat.categoryName)}</option>`).join('');

    if (parentFilter.value) {
        const parentId = Number(parentFilter.value);
        const filteredChildren = childCategories.filter(cat => cat.parent_id === parentId);
        subFilter.innerHTML = '<option value="">All Subcategories</option>' +
            filteredChildren.map(cat => `<option value="${cat.categoryID}">${escapeHtml(cat.categoryName)}</option>`).join('');
    } else {
        subFilter.innerHTML = '<option value="">All Subcategories</option>' +
            childCategories.map(cat => `<option value="${cat.categoryID}">${escapeHtml(cat.categoryName)}</option>`).join('');
    }
}

function handleParentCategoryChange() {
    const parentFilter = document.getElementById('categoryParentFilter');
    const subFilter = document.getElementById('categoryFilter');
    if (!parentFilter || !subFilter) return;

    const parentId = parentFilter.value;
    const childCategories = activeCategories.filter(cat => cat.parent_id !== null);

    if (parentId) {
        const filteredChildren = childCategories.filter(cat => cat.parent_id === Number(parentId));
        subFilter.innerHTML = '<option value="">All Subcategories</option>' +
            filteredChildren.map(cat => `<option value="${cat.categoryID}">${escapeHtml(cat.categoryName)}</option>`).join('');
    } else {
        subFilter.innerHTML = '<option value="">All Subcategories</option>' +
            childCategories.map(cat => `<option value="${cat.categoryID}">${escapeHtml(cat.categoryName)}</option>`).join('');
    }

    subFilter.value = '';
    filterProducts();
}

// Filter Products
function filterProducts() {
    const parentFilter = document.getElementById('categoryParentFilter');
    const subFilter = document.getElementById('categoryFilter');
    const searchInput = document.getElementById('productSearch');
    const parentId = parentFilter ? parentFilter.value : '';
    const categoryId = subFilter ? subFilter.value : '';
    const statusFilter = document.getElementById('productStatusFilter');
    const statusValue = statusFilter ? statusFilter.value : '';
    const query = searchInput ? searchInput.value.trim().toLowerCase() : '';

    let filteredProducts = [...products];

    if (statusValue === 'active') {
        filteredProducts = filteredProducts.filter(product => !!product.isActive);
    } else if (statusValue === 'inactive') {
        filteredProducts = filteredProducts.filter(product => !product.isActive);
    }

    if (categoryId) {
        filteredProducts = filteredProducts.filter(product => product.categoryID == categoryId);
    } else if (parentId) {
        const childCategoryIds = new Set(
            activeCategories
                .filter(cat => cat.parent_id === Number(parentId))
                .map(cat => cat.categoryID)
        );
        filteredProducts = filteredProducts.filter(product => childCategoryIds.has(product.categoryID));
    }

    if (query) {
        filteredProducts = filteredProducts.filter(product => {
            const name = (product.productName || '').toLowerCase();
            const category = (product.categoryName || '').toLowerCase();
            return name.includes(query) || category.includes(query);
        });
    }

    renderProducts(filteredProducts);
}

// Modal Functions
function createCategoryModal() {
    const modal = document.createElement('div');
    modal.id = 'addCategoryModal';
    modal.className = 'modal';
    modal.style.cssText = `
        position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; display: none; align-items: center; justify-content: center;
    `;
    modal.innerHTML = `
        <div class="modal-content" style="background: white; padding: 20px; border-radius: 8px; max-width: 400px; width: 90%;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h3>Add New Category</h3>
                <span class="close" onclick="closeAddCategoryModal()" style="cursor: pointer; font-size: 24px; font-weight: bold;">&times;</span>
            </div>
            <form id="categoryForm">
                <div class="form-group" style="margin-bottom: 15px;">
                    <label for="newCategory">Category Name:</label>
                    <input type="text" id="newCategory" required style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                </div>
                <div style="display: flex; gap: 10px; justify-content: flex-end;">
                    <button type="button" onclick="closeAddCategoryModal()" class="btn-secondary" style="padding: 8px 16px; border: none; border-radius: 4px; cursor: pointer;">Cancel</button>
                    <button type="submit" class="btn-primary" style="padding: 8px 16px; background: #007bff; color: white; border: none; border-radius: 4px; cursor: pointer;">Add Category</button>
                </div>
            </form>
        </div>
    `;
    document.body.appendChild(modal);
    return modal;
}

function showAddCategoryModal() {
    let modal = document.getElementById('addCategoryModal');
    if (!modal) {
        modal = createCategoryModal();
    }
    modal.style.display = 'flex';
    modal.classList.add('show');

    // Focus on category input
    const input = document.getElementById('newCategory');
    if (input) input.focus();

    // Add submit handler if not already added
    const form = modal.querySelector('#categoryForm');
    if (form && !form.dataset.submittedAdded) {
        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            addCategory();
        });
        form.dataset.submittedAdded = 'true';
    }
}

function closeAddCategoryModal() {
    const modal = document.getElementById('addCategoryModal');
    if (modal) {
        modal.classList.remove('show');
        modal.style.display = 'none';
        // Clear input
        const input = document.getElementById('newCategory');
        if (input) input.value = '';
    }
}

function showAddSizeModal() {
    // Create modal if it doesn't exist
    if (!document.getElementById('addSizeModal')) {
        createSizeModal();
    }

    // Show modal
    const modal = document.getElementById('addSizeModal');
    if (modal) {
        modal.style.display = 'flex';
        modal.classList.add('show');
    }

    // Load sizes in modal
    loadSizesForModal();

    // Focus on size name input
    const input = document.getElementById('newSizeName');
    if (input) input.focus();
}

async function showAddProductModal() {
    if (window.USER_ROLE !== 'admin') {
        showToast('Only administrators can add products', 'warning');
        return;
    }

    try {
        const [categoriesResponse, unitsResponse, addonsResponse, flavorsResponse] = await Promise.all([
            fetch('db/categories_get.php'),
            fetch('db/product_units_get.php'),
            fetch('db/addons_getAll.php'),
            fetch('db/flavors_getAll.php')
        ]);

        let categories = [], units = [], addonsList = [], flavorsList = [];
        try {
            const catText = await categoriesResponse.text();
            const unitText = await unitsResponse.text();
            const addonsText = await addonsResponse.text();
            const flavorsText = await flavorsResponse.text();
            if (!categoriesResponse.ok || !unitsResponse.ok) {
                showToast('Failed to load data. Please try again.', 'error');
                return;
            }
            categories = JSON.parse(catText);
            units = JSON.parse(unitText);
            addonsList = addonsResponse.ok ? (JSON.parse(addonsText) || []) : [];
            flavorsList = flavorsResponse.ok ? (JSON.parse(flavorsText) || []) : [];
        } catch (parseErr) {
            console.error('Parse error loading modal data:', parseErr);
            showToast('Invalid response from server. Please refresh and try again.', 'error');
            return;
        }

        if (!Array.isArray(categories)) categories = [];
        if (!Array.isArray(units)) units = [];
        if (!Array.isArray(addonsList)) addonsList = [];
        if (!Array.isArray(flavorsList)) flavorsList = [];

        if (categories.length === 0) {
            showToast('No categories available. Please add categories first.', 'warning');
            return;
        }

        const addonsHtml = addonsList
            .filter(a => a.status !== 'inactive')
            .map(a => `
                <label style="display:flex;align-items:center;gap:8px;padding:6px 8px;border:1px solid #f1e7dd;border-radius:8px;background:#fff;">
                    <input type="checkbox" name="addon_ids" value="${a.addon_id}">
                    <span style="display:flex;flex-direction:column;">
                        <span style="font-weight:600;color:#5c3b24;">${a.addon_name}</span>
                        <small style="color:#7f5539;">+₱${Number(a.price || 0).toFixed(2)}</small>
                    </span>
                </label>
            `).join('') || '<p style="color:#9ca3af;font-style:italic;">No add-ons configured yet.</p>';

        const flavorsHtml = flavorsList.map(f => `
            <label style="display:flex;align-items:center;gap:8px;padding:6px 8px;border:1px solid #f1e7dd;border-radius:8px;background:#fff;">
                <input type="checkbox" name="flavor_ids" value="${f.flavor_id}">
                <span>${f.flavor_name}</span>
            </label>
        `).join('') || '<p style="color:#9ca3af;font-style:italic;">No flavors configured yet.</p>';

        let modal = document.getElementById('addProductModal');
        if (!modal) {
            modal = document.createElement('div');
            modal.id = 'addProductModal';
            modal.className = 'modal';
            modal.style.cssText = 'position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; display: none; align-items: center; justify-content: center;';
            modal.innerHTML = `
                <div class="modal-content" style="background:#fff;padding:24px;border-radius:16px;width:min(720px,95vw);max-height:92vh;overflow-y:auto;box-shadow:0 20px 40px rgba(44,33,20,0.25);">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;">
                        <div>
                            <h3 style="margin:0;font-size:1.5rem;color:#5c3b24;">Add Product</h3>
                            <p style="margin:4px 0 0;color:#8c6a52;">Follow the TUI spec: set basics, then variants or single price.</p>
                        </div>
                        <button type="button" onclick="closeProductModal()" style="background:#f3ebe3;border:none;width:36px;height:36px;border-radius:50%;font-size:1.4rem;cursor:pointer;">&times;</button>
                    </div>
                    <form id="productForm">
                        <section style="margin-bottom:18px;">
                            <h4 style="margin-bottom:8px;color:#59331f;">Basic Information</h4>
                            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;">
                                <div>
                                    <label for="productName" style="display:block;font-weight:600;margin-bottom:4px;">Product Name</label>
                                    <input type="text" id="productName" required style="width:100%;padding:10px;border:1px solid #e7ddd3;border-radius:8px;">
                                </div>
                                <div>
                                    <label for="productCategory" style="display:block;font-weight:600;margin-bottom:4px;">Category</label>
                                    <select id="productCategory" required style="width:100%;padding:10px;border:1px solid #e7ddd3;border-radius:8px;">
                                        <option value="">Select Category</option>
                                        ${categories.map(cat => `<option value="${cat.categoryID}">${escapeHtml(cat.categoryName)}</option>`).join('')}
                                    </select>
                                </div>
                            </div>
                            <div style="display:flex;flex-wrap:wrap;gap:24px;margin-top:12px;">
                                <div style="display:flex;align-items:center;gap:12px;">
                                    <span style="font-weight:600;">Product Type:</span>
                                    <label style="display:flex;align-items:center;gap:6px;"><input type="radio" name="productType" value="drink" checked> Drink</label>
                                    <label style="display:flex;align-items:center;gap:6px;"><input type="radio" name="productType" value="food"> Food</label>
                                </div>
                            </div>
                        </section>

                        <section id="addProductVariantsSection" style="margin-bottom:18px;padding:16px;border:1px solid #f1e7dd;border-radius:12px;background:#fffaf5;">
                            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
                                <div>
                                    <h4 style="margin:0;color:#59331f;">Product Variants</h4>
                                    <p style="margin:4px 0 0;color:#8c6a52;font-size:0.9rem;">Mirror the mockup: list every size with price & status.</p>
                                </div>
                                <button type="button" id="addVariantBtn" style="padding:8px 14px;border:1px solid #7f5539;color:#7f5539;background:#fff;border-radius:999px;cursor:pointer;">+ Add Variant</button>
                            </div>
                            <div style="overflow-x:auto;">
                                <table style="width:100%;border-collapse:collapse;">
                                    <thead>
                                        <tr style="background:#f7efe7;">
                                            <th style="text-align:left;padding:8px;font-size:0.85rem;color:#7b5b44;">Variant Name</th>
                                            <th style="text-align:left;padding:8px;font-size:0.85rem;color:#7b5b44;">Size Label</th>
                                            <th style="text-align:left;padding:8px;font-size:0.85rem;color:#7b5b44;">Price (₱)</th>
                                            <th style="text-align:left;padding:8px;font-size:0.85rem;color:#7b5b44;">Status</th>
                                            <th style="width:70px;text-align:center;padding:8px;font-size:0.85rem;color:#7b5b44;">Remove</th>
                                        </tr>
                                    </thead>
                                    <tbody id="addProductVariantsBody"></tbody>
                                </table>
                            </div>
                        </section>

                        <section style="margin-bottom:18px;padding:16px;border:1px solid #f1e7dd;border-radius:12px;background:#fff;">
                            <div style="display:flex;flex-wrap:wrap;gap:24px;align-items:center;">
                                <div style="display:flex;align-items:center;gap:12px;">
                                    <span style="font-weight:600;">Has Flavors?</span>
                                    <label style="display:flex;align-items:center;gap:6px;"><input type="radio" name="hasFlavors" value="1"> Yes</label>
                                    <label style="display:flex;align-items:center;gap:6px;"><input type="radio" name="hasFlavors" value="0" checked> No</label>
                                </div>
                                <div style="display:flex;align-items:center;gap:12px;">
                                    <span style="font-weight:600;">Has Add-ons?</span>
                                    <label style="display:flex;align-items:center;gap:6px;"><input type="radio" name="hasAddons" value="1"> Yes</label>
                                    <label style="display:flex;align-items:center;gap:6px;"><input type="radio" name="hasAddons" value="0" checked> No</label>
                                </div>
                            </div>
                            <div id="addProductFlavorsSection" style="display:none;margin-top:12px;">
                                <h4 style="margin:0 0 8px;color:#59331f;">Available Flavors</h4>
                                <div id="addProductFlavorsList" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;">
                                    ${flavorsHtml}
                                </div>
                            </div>
                            <div id="addProductAddonsSection" style="display:none;margin-top:12px;">
                                <h4 style="margin:0 0 8px;color:#59331f;">Available Add-ons</h4>
                                <div id="addProductAddonsList" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px;">
                                    ${addonsHtml}
                                </div>
                            </div>
                        </section>

                        <div style="display:flex;justify-content:flex-end;gap:12px;">
                            <button type="button" onclick="closeProductModal()" class="btn-secondary" style="padding:10px 18px;border:1px solid #d9c6b6;border-radius:10px;background:#fff;color:#7b5b44;cursor:pointer;">Cancel</button>
                            <button type="submit" class="btn-primary" style="padding:10px 18px;border:none;border-radius:10px;background:#7f5539;color:#fff;cursor:pointer;">Save Product</button>
                        </div>
                    </form>
                </div>
            `;
            document.body.appendChild(modal);

            const form = modal.querySelector('#productForm');
            if (form) form.addEventListener('submit', handleProductSubmitPM);

            modal.querySelectorAll('input[name="hasFlavors"]').forEach(radio => {
                radio.addEventListener('change', function () {
                    const section = modal.querySelector('#addProductFlavorsSection');
                    if (section) section.style.display = this.value === '1' ? 'block' : 'none';
                });
            });
            modal.querySelectorAll('input[name="hasAddons"]').forEach(radio => {
                radio.addEventListener('change', function () {
                    const section = modal.querySelector('#addProductAddonsSection');
                    if (section) section.style.display = this.value === '1' ? 'block' : 'none';
                });
            });
            const addVariantBtn = modal.querySelector('#addVariantBtn');
            if (addVariantBtn) {
                addVariantBtn.addEventListener('click', function () {
                    const tbody = modal.querySelector('#addProductVariantsBody');
                    if (!tbody) return;
                    const row = document.createElement('tr');
                    row.innerHTML = `
                        <td style="padding:6px;"><input type="text" class="variant-name" placeholder="e.g. 16oz" style="width:100%;padding:8px;border:1px solid #e7ddd3;border-radius:8px;"></td>
                        <td style="padding:6px;"><input type="text" class="variant-size" placeholder="Display label" style="width:100%;padding:8px;border:1px solid #e7ddd3;border-radius:8px;"></td>
                        <td style="padding:6px;">
                            <div style="display:flex;align-items:center;border:1px solid #e7ddd3;border-radius:8px;padding:0 8px;">
                                <span style="color:#7b5b44;font-weight:600;margin-right:4px;">₱</span>
                                <input type="number" class="variant-price" min="0" step="0.01" placeholder="0.00" style="flex:1;border:none;outline:none;padding:8px 0;">
                            </div>
                        </td>
                        <td style="padding:6px;">
                            <select class="variant-status" style="width:100%;padding:8px;border:1px solid #e7ddd3;border-radius:8px;">
                                <option value="active" selected>Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </td>
                        <td style="text-align:center;padding:6px;">
                            <button type="button" class="remove-variant" style="background:#fbeae3;border:none;border-radius:8px;color:#b4543a;padding:6px 10px;cursor:pointer;">Remove</button>
                        </td>
                    `;
                    row.querySelector('.remove-variant').addEventListener('click', () => row.remove());
                    tbody.appendChild(row);
                });
            }
        } else {
            const categorySelect = modal.querySelector('#productCategory');
            const categoryList = activeCategories.length ? activeCategories : categories;
            if (categorySelect) {
                categorySelect.innerHTML = '<option value="">Select Category</option>' +
                    categoryList.map(cat => `<option value="${cat.categoryID}">${escapeHtml(cat.categoryName)}</option>`).join('');
            }
            const addonsListEl = modal.querySelector('#addProductAddonsList');
            if (addonsListEl) addonsListEl.innerHTML = addonsHtml;
            const flavorsListEl = modal.querySelector('#addProductFlavorsList');
            if (flavorsListEl) flavorsListEl.innerHTML = flavorsHtml;
            const addOnSection = modal.querySelector('#addProductAddonsSection');
            if (addOnSection) addOnSection.style.display = 'none';
            const flavorSection = modal.querySelector('#addProductFlavorsSection');
            if (flavorSection) flavorSection.style.display = 'none';
            const addOnRadios = modal.querySelectorAll('input[name="hasAddons"]');
            addOnRadios.forEach(radio => { radio.checked = radio.value === '0'; });
            const flavorRadios = modal.querySelectorAll('input[name="hasFlavors"]');
            flavorRadios.forEach(radio => { radio.checked = radio.value === '0'; });
            const productNameEl = modal.querySelector('#productName');
            if (productNameEl) productNameEl.value = '';
            const tbody = modal.querySelector('#addProductVariantsBody');
            if (tbody) tbody.innerHTML = '';
        }

        modal.style.display = 'flex';
        modal.classList.add('show');
    } catch (err) {
        console.error('Error loading data for modal:', err);
        showToast('Failed to load data for add product modal.', 'error');
    }
}

// Separate submit handler function for ProductManagement.js
async function handleProductSubmitPM(e) {
    e.preventDefault();
    const form = e.target;
    const modal = document.getElementById('addProductModal');
    const nameEl = modal && modal.querySelector('#productName');
    const categoryEl = modal && modal.querySelector('#productCategory');
    const name = (nameEl && nameEl.value) ? nameEl.value.trim() : '';
    const categoryID = (categoryEl && categoryEl.value) ? categoryEl.value : '';
    if (!name || !categoryID) {
        showToast('Please enter product name and select category', 'error');
        return;
    }

    const hasFlavors = (form.querySelector('input[name="hasFlavors"]:checked') || {}).value === '1';
    const hasAddons = (form.querySelector('input[name="hasAddons"]:checked') || {}).value === '1';
    let variants = [];
    const tbody = modal && modal.querySelector('#addProductVariantsBody');
    if (tbody && tbody.rows.length) {
        variants = Array.from(tbody.rows).map(tr => ({
            variant_name: (tr.querySelector('.variant-name') || {}).value || '',
            size_label: (tr.querySelector('.variant-size') || {}).value || '',
            price: parseFloat((tr.querySelector('.variant-price') || {}).value) || 0,
            status: ((tr.querySelector('.variant-status') || {}).value || 'active') === 'inactive' ? 'inactive' : 'active'
        })).filter(v => v.variant_name || v.size_label);
    }
    if (variants.length === 0) {
        showToast('Add at least one variant', 'error');
        return;
    }
    const addonIds = hasAddons && (form.querySelectorAll('input[name="addon_ids"]:checked') || []).length
        ? Array.from(form.querySelectorAll('input[name="addon_ids"]:checked')).map(cb => cb.value)
        : [];
    const flavorIds = hasFlavors && (form.querySelectorAll('input[name="flavor_ids"]:checked') || []).length
        ? Array.from(form.querySelectorAll('input[name="flavor_ids"]:checked')).map(cb => cb.value)
        : [];

    const submitButton = form.querySelector('button[type="submit"]');
    if (submitButton) {
        submitButton.disabled = true;
        submitButton.textContent = 'Saving...';
    }

    const success = await addAdminProduct({
        productName: name,
        categoryID: parseInt(categoryID, 10),
        has_variants: 1,
        has_flavors: hasFlavors ? 1 : 0,
        has_addons: hasAddons ? 1 : 0,
        base_price: 0,
        variants,
        addon_ids: addonIds,
        flavor_ids: flavorIds
    });

    if (submitButton) {
        submitButton.disabled = false;
        submitButton.textContent = 'Save Product';
    }
    if (success) closeProductModal();
}

async function addAdminProduct(payload) {
    const formData = new FormData();
    formData.append('productName', payload.productName);
    formData.append('categoryID', payload.categoryID);
    formData.append('has_variants', payload.has_variants || 0);
    formData.append('has_flavors', payload.has_flavors || 0);
    formData.append('has_addons', payload.has_addons || 0);
    formData.append('base_price', payload.base_price != null ? payload.base_price : 0);
    formData.append('isActive', '1');
    if (Array.isArray(payload.variants) && payload.variants.length) {
        formData.append('variants', JSON.stringify(payload.variants));
    }
    if (Array.isArray(payload.addon_ids) && payload.addon_ids.length) {
        formData.append('addon_ids', JSON.stringify(payload.addon_ids));
    }
    if (Array.isArray(payload.flavor_ids) && payload.flavor_ids.length) {
        formData.append('flavor_ids', JSON.stringify(payload.flavor_ids));
    }

    try {
        const response = await fetch('db/products_add.php', { method: 'POST', body: formData });
        const text = await response.text();
        if (!response.ok) {
            console.error('Add product failed:', text);
            showToast('Server error while adding product', 'error');
            return false;
        }
        let result;
        try {
            result = JSON.parse(text);
        } catch (err) {
            console.error('Invalid JSON from products_add:', text);
            showToast('Invalid response from server', 'error');
            return false;
        }
        if (result.status === 'success') {
            showToast('Product added successfully', 'success');
            await loadProducts({ force: true });
            return true;
        }
        showToast(result.message || 'Failed to add product', 'error');
        return false;
    } catch (error) {
        console.error('Error adding product:', error);
        showToast('Failed to add product: ' + error.message, 'error');
        return false;
    }
}

function createProductModal() {
    const modal = document.createElement('div');
    modal.id = 'addProductModal';
    modal.className = 'modal';
    modal.style.cssText = `
        position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; display: none; align-items: center; justify-content: center;
    `;
    modal.innerHTML = `
        <div class="modal-content" style="background: white; padding: 20px; border-radius: 8px; max-width: 400px; width: 90%;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h3>Add New Product</h3>
                <span class="close" onclick="closeProductModal()" style="cursor: pointer; font-size: 24px; font-weight: bold;">&times;</span>
            </div>
            <form id="productForm">
                <div class="form-group" style="margin-bottom: 15px;">
                    <label for="productName">Product Name:</label>
                    <input type="text" id="productName" required style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                </div>
                <div class="form-group" style="margin-bottom: 15px;">
                    <label for="productCategory">Category:</label>
                    <select id="productCategory" required style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                        <option value="">Select Category</option>
                    </select>
                </div>
                <div class="form-group" style="margin-bottom: 15px;">
                    <label for="productImage">Image URL (optional):</label>
                    <input type="url" id="productImage" placeholder="https://example.com/image.jpg" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                </div>
                <div style="display: flex; gap: 10px; justify-content: flex-end;">
                    <button type="button" onclick="closeProductModal()" class="btn-secondary" style="padding: 8px 16px; border: none; border-radius: 4px; cursor: pointer;">Cancel</button>
                    <button type="submit" class="btn-primary" style="padding: 8px 16px; background: #007bff; color: white; border: none; border-radius: 4px; cursor: pointer;">Add Product</button>
                </div>
            </form>
        </div>
    `;
    document.body.appendChild(modal);
    return modal;
}

function closeProductModal() {
    const modal = document.getElementById('addProductModal');
    if (modal) {
        modal.classList.remove('show');
        modal.style.display = 'none';
    }
}

// Placeholder functions for future implementation
function editCategory(id) {
    showToast('Edit category functionality coming soon', 'info');
}

async function deleteCategory(id) {
    const confirmed = await confirmAction('Are you sure you want to delete this category?');
    if (confirmed) {
        showToast('Delete category functionality coming soon', 'info');
    }
}

function editSize(id) {
    showToast('Edit size functionality coming soon', 'info');
}

async function deleteSize(id) {
    const confirmed = await confirmAction('Are you sure you want to delete this size?');
    if (confirmed) {
        showToast('Delete size functionality coming soon', 'info');
    }
}

async function editProduct(productID) {
    if (window.USER_ROLE !== 'admin') {
        showToast('Only administrators can edit products', 'warning');
        return;
    }

    try {
        const formData = new FormData();
        formData.append('productID', productID);
        const response = await fetch('db/products_getOne.php', { method: 'POST', body: formData });
        const text = await response.text();
        if (!response.ok) {
            showToast('Failed to load product details', 'error');
            return;
        }
        let product;
        try {
            product = JSON.parse(text);
        } catch (parseErr) {
            console.error('Invalid JSON from products_getOne:', text.substring(0, 200));
            showToast('Invalid response from server', 'error');
            return;
        }
        if (!product || !product.productID) {
            showToast('Product not found', 'error');
            return;
        }
        await showEditProductModal(product);
    } catch (error) {
        console.error('Error loading product details:', error);
        showToast('Failed to load product details', 'error');
    }
}

async function showEditProductModal(product) {
    let modal = document.getElementById('editProductModal');
    if (modal) {
        modal.remove();
    }

    const ensureArray = (value) => Array.isArray(value) ? value : [];
    const selectedAddonIds = new Set(ensureArray(product.addons).map(a => String(a.addon_id || a.id)));
    const selectedFlavorIds = new Set(ensureArray(product.flavors).map(f => String(f.flavor_id || f.id)));
    const hasVariants = Number(product.has_variants) === 1;
    const hasFlavors = Number(product.has_flavors) === 1;
    const hasAddons = Number(product.has_addons) === 1;

    let addonsList = [];
    let flavorsList = [];
    try {
        const [addonsResponse, flavorsResponse] = await Promise.all([
            fetch('db/addons_getAll.php'),
            fetch('db/flavors_getAll.php')
        ]);
        if (addonsResponse.ok) {
            const addonsPayload = await addonsResponse.json();
            addonsList = ensureArray(addonsPayload).filter(a => a.status !== 'inactive');
        }
        if (flavorsResponse.ok) {
            const flavorsPayload = await flavorsResponse.json();
            flavorsList = ensureArray(flavorsPayload);
        }
    } catch (error) {
        console.error('Failed loading add-ons/flavors:', error);
    }

    const variantList = ensureArray(product.variants);
    const buildVariantRow = (variant = {}) => {
        const name = variant.variant_name || variant.size_label || '';
        const label = variant.size_label || variant.variant_name || '';
        const price = typeof variant.price === 'number' ? variant.price : '';
        const status = (variant.status || 'active') === 'inactive' ? 'inactive' : 'active';
        const variantId = variant.variant_id || variant.variantID || null;
        const displayId = variantId ? variantId : '—';
        const row = document.createElement('tr');
        if (variant.variant_id) {
            row.dataset.variantId = variant.variant_id;
        }
        row.innerHTML = `
            <td class="variant-id-cell" style="text-align:center;font-weight:600;color:#6b4b38;">${displayId}</td>
            <td><input type="text" class="variant-name-input form-input" value="${name}" placeholder="e.g., 16oz"></td>
            <td><input type="text" class="variant-size-input form-input" value="${label}" placeholder="Display label"></td>
            <td><input type="number" class="variant-price-input form-input" min="0" step="0.01" value="${price}"></td>
            <td>
                <select class="variant-status-select form-input">
                    <option value="active" ${status === 'active' ? 'selected' : ''}>Active</option>
                    <option value="inactive" ${status === 'inactive' ? 'selected' : ''}>Inactive</option>
                </select>
            </td>
            <td style="text-align:center;">
                <button type="button" class="btn-secondary variant-remove-btn" style="padding:4px 10px;">Remove</button>
            </td>
        `;
        return row;
    };

    modal = document.createElement('div');
    modal.id = 'editProductModal';
    modal.className = 'modal';
    modal.style.cssText = `
        position: fixed; top: 0; left: 0; width: 100%; height: 100%;
        background: rgba(0,0,0,0.5); z-index: 1000; display: flex;
        align-items: center; justify-content: center; padding: 20px;
    `;

    const categoryList = Array.isArray(activeCategories) && activeCategories.length
        ? activeCategories.slice()
        : ensureArray(categories);
    if (product && product.categoryID && !categoryList.some(cat => cat.categoryID === product.categoryID)) {
        const fallbackCategory = ensureArray(categories).find(cat => cat.categoryID === product.categoryID);
        if (fallbackCategory) categoryList.push(fallbackCategory);
    }
    const categoryOptions = categoryList.map(cat =>
        `<option value="${cat.categoryID}" ${cat.categoryID === product.categoryID ? 'selected' : ''}>${escapeHtml(cat.categoryName)}</option>`
    ).join('');

    const addonCheckboxes = addonsList.length
        ? addonsList.map(addon => `
            <label style="display:flex;align-items:center;gap:6px;font-size:0.95rem;margin-bottom:6px;">
                <input type="checkbox" name="edit_addon_ids" value="${addon.addon_id}"
                    ${selectedAddonIds.has(String(addon.addon_id)) ? 'checked' : ''}>
                <span>${addon.addon_name} <small style="color:#6b7280;">(+₱${Number(addon.price || 0).toFixed(2)})</small></span>
            </label>
        `).join('')
        : '<p style="color:#9ca3af;font-style:italic;">No add-ons found.</p>';

    const flavorCheckboxes = flavorsList.length
        ? flavorsList.map(flavor => `
            <label style="display:flex;align-items:center;gap:6px;font-size:0.95rem;margin-bottom:6px;">
                <input type="checkbox" name="edit_flavor_ids" value="${flavor.flavor_id}"
                    ${selectedFlavorIds.has(String(flavor.flavor_id)) ? 'checked' : ''}>
                <span>${flavor.flavor_name}</span>
            </label>
        `).join('')
        : '<p style="color:#9ca3af;font-style:italic;">No flavors found.</p>';

    modal.innerHTML = `
        <div class="modal-content" style="background:white;padding:24px;border-radius:12px;width:100%;max-width:720px;max-height:92vh;overflow-y:auto;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
                <div>
                    <h3 style="margin:0;font-size:1.3rem;">Edit Product</h3>
                    <p style="margin:4px 0 0;color:#6b7280;font-size:0.9rem;">${escapeHtml(product.productName || '')}</p>
                </div>
                <button type="button" class="modal-close" onclick="closeEditProductModal()" style="background:none;border:none;font-size:1.8rem;line-height:1;cursor:pointer;">&times;</button>
            </div>
            <form id="editProductForm" data-product-id="${product.productID}" data-has-variants="${hasVariants ? '1' : '0'}">
                <section style="margin-bottom:18px;">
                    <h4 style="margin-bottom:8px;color:#374151;">Basic Information</h4>
                    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;">
                        <div>
                            <label class="form-label" for="editProductName">Product Name</label>
                            <input id="editProductName" type="text" class="form-input" value="${product.productName || ''}" required>
                        </div>
                        <div>
                            <label class="form-label" for="editProductCategory">Category</label>
                            <select id="editProductCategory" class="form-input" required>
                                <option value="">Select Category</option>
                                ${categoryOptions}
                            </select>
                        </div>
                        <div>
                            <label class="form-label" for="editProductStatus">Status</label>
                            <select id="editProductStatus" class="form-input">
                                <option value="1" ${product.isActive ? 'selected' : ''}>Active</option>
                                <option value="0" ${!product.isActive ? 'selected' : ''}>Inactive</option>
                            </select>
                        </div>
                    </div>
                </section>

                <section id="editVariantSection" style="margin-bottom:18px;">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
                        <h4 style="margin:0;color:#374151;">Product Variants</h4>
                        <button type="button" id="editAddVariantBtn" class="btn-secondary" style="padding:6px 12px;">+ Add Variant</button>
                    </div>
                    <div class="table-container" style="overflow:auto;">
                        <table class="inventory-table" style="min-width:100%;">
                            <thead>
                                <tr>
                                    <th style="width:70px;">ID</th>
                                    <th>Variant Name</th>
                                    <th>Size Label</th>
                                    <th style="width:120px;">Price (₱)</th>
                                    <th style="width:120px;">Status</th>
                                    <th style="width:80px;text-align:center;">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="editProductVariantsBody"></tbody>
                        </table>
                    </div>
                </section>

                <section style="margin-bottom:18px;">
                    <div style="display:flex;gap:16px;flex-wrap:wrap;">
                        <div style="display:flex;align-items:center;gap:12px;">
                            <span style="font-weight:600;">Has Flavors?</span>
                            <label style="display:flex;align-items:center;gap:6px;">
                                <input type="radio" name="editHasFlavors" value="1" ${hasFlavors ? 'checked' : ''}>
                                <span>Yes</span>
                            </label>
                            <label style="display:flex;align-items:center;gap:6px;">
                                <input type="radio" name="editHasFlavors" value="0" ${!hasFlavors ? 'checked' : ''}>
                                <span>No</span>
                            </label>
                        </div>
                        <div style="display:flex;align-items:center;gap:12px;">
                            <span style="font-weight:600;">Has Add-ons?</span>
                            <label style="display:flex;align-items:center;gap:6px;">
                                <input type="radio" name="editHasAddons" value="1" ${hasAddons ? 'checked' : ''}>
                                <span>Yes</span>
                            </label>
                            <label style="display:flex;align-items:center;gap:6px;">
                                <input type="radio" name="editHasAddons" value="0" ${!hasAddons ? 'checked' : ''}>
                                <span>No</span>
                            </label>
                        </div>
                    </div>
                    <div id="editFlavorSection" style="margin-top:12px;${hasFlavors ? '' : 'display:none;'}">
                        <h4 style="margin-bottom:6px;color:#374151;">Available Flavors</h4>
                        <div class="checkbox-list" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:8px;">
                            ${flavorCheckboxes}
                        </div>
                    </div>
                    <div id="editAddonSection" style="margin-top:12px;${hasAddons ? '' : 'display:none;'}">
                        <h4 style="margin-bottom:6px;color:#374151;">Available Add-ons</h4>
                        <div class="checkbox-list" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:8px;">
                            ${addonCheckboxes}
                        </div>
                    </div>
                </section>

                <div style="display:flex;justify-content:flex-end;gap:10px;">
                    <button type="button" class="btn-secondary" onclick="closeEditProductModal()">Cancel</button>
                    <button type="submit" class="btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    `;

    document.body.appendChild(modal);
    requestAnimationFrame(() => modal.classList.add('show'));

    const form = modal.querySelector('#editProductForm');
    const variantsBody = form.querySelector('#editProductVariantsBody');
    const addVariantBtn = form.querySelector('#editAddVariantBtn');
    const flavorRadios = form.querySelectorAll('input[name="editHasFlavors"]');
    const flavorSection = form.querySelector('#editFlavorSection');
    const addonRadios = form.querySelectorAll('input[name="editHasAddons"]');
    const addonSection = form.querySelector('#editAddonSection');

    if (variantsBody) {
        if (variantList.length) {
            variantList.forEach(variant => {
                const row = buildVariantRow(variant);
                attachVariantRowHandlers(row);
                variantsBody.appendChild(row);
            });
        } else if (hasVariants) {
            const row = buildVariantRow();
            attachVariantRowHandlers(row);
            variantsBody.appendChild(row);
        }
    }

    function attachVariantRowHandlers(row) {
        const removeBtn = row.querySelector('.variant-remove-btn');
        if (removeBtn) {
            removeBtn.addEventListener('click', () => row.remove());
        }
    }

    if (addVariantBtn) {
        addVariantBtn.addEventListener('click', () => {
            const row = buildVariantRow();
            attachVariantRowHandlers(row);
            variantsBody.appendChild(row);
        });
    }

    flavorRadios.forEach(radio => {
        radio.addEventListener('change', (event) => {
            if (flavorSection) {
                flavorSection.style.display = event.target.value === '1' ? 'block' : 'none';
            }
        });
    });

    addonRadios.forEach(radio => {
        radio.addEventListener('change', (event) => {
            if (addonSection) {
                addonSection.style.display = event.target.value === '1' ? 'block' : 'none';
            }
        });
    });

    form.addEventListener('submit', handleEditProductSubmit);
}

async function handleEditProductSubmit(event) {
    event.preventDefault();
    const form = event.target;
    const productID = form.dataset.productId;
    const modal = document.getElementById('editProductModal');
    const nameEl = modal && modal.querySelector('#editProductName');
    const categoryEl = modal && modal.querySelector('#editProductCategory');
    const statusSelect = modal && modal.querySelector('#editProductStatus');
    const name = (nameEl && nameEl.value) ? nameEl.value.trim() : '';
    const categoryID = (categoryEl && categoryEl.value) ? categoryEl.value : '';
    const isActive = statusSelect ? statusSelect.value === '1' : true;

    if (!name || !categoryID) {
        showToast('Please provide product name and category', 'error');
        return;
    }

    const formData = new FormData();
    formData.append('productID', productID);
    formData.append('productName', name);
    formData.append('categoryID', categoryID);
    formData.append('isActive', isActive ? '1' : '0');

    const variantTbody = modal && modal.querySelector('#editProductVariantsBody');
    const variantsPayload = [];
    if (!variantTbody || !variantTbody.rows.length) {
        showToast('Add at least one variant', 'error');
        return;
    }
    Array.from(variantTbody.rows).forEach(tr => {
        const name = (tr.querySelector('.variant-name-input') || {}).value?.trim() || '';
        const label = (tr.querySelector('.variant-size-input') || {}).value?.trim() || '';
        const priceValue = parseFloat((tr.querySelector('.variant-price-input') || {}).value);
        const status = (tr.querySelector('.variant-status-select') || {}).value === 'inactive' ? 'inactive' : 'active';
        if (!name && !label) {
            return;
        }
        variantsPayload.push({
            variant_id: tr.dataset.variantId || null,
            variant_name: name || label,
            size_label: label || name,
            price: isNaN(priceValue) ? 0 : priceValue,
            status
        });
    });
    if (!variantsPayload.length) {
        showToast('Variants require a name/label', 'error');
        return;
    }
    formData.append('variants', JSON.stringify(variantsPayload));
    formData.append('has_variants', '1');

    const editHasFlavors = (form.querySelector('input[name="editHasFlavors"]:checked') || {}).value === '1';
    const editHasAddons = (form.querySelector('input[name="editHasAddons"]:checked') || {}).value === '1';
    const addonCheckboxes = editHasAddons ? form.querySelectorAll('input[name="edit_addon_ids"]:checked') : [];
    const flavorCheckboxes = editHasFlavors ? form.querySelectorAll('input[name="edit_flavor_ids"]:checked') : [];
    formData.append('addon_ids', JSON.stringify(Array.from(addonCheckboxes).map(cb => cb.value)));
    formData.append('flavor_ids', JSON.stringify(Array.from(flavorCheckboxes).map(cb => cb.value)));
    formData.append('has_addons', editHasAddons ? '1' : '0');
    formData.append('has_flavors', editHasFlavors ? '1' : '0');

    const submitButton = form.querySelector('button[type="submit"]');
    if (submitButton) {
        submitButton.disabled = true;
        submitButton.textContent = 'Saving...';
    }
    try {
        const response = await fetch('db/products_update.php', { method: 'POST', body: formData });
        const text = await response.text();
        let result;
        try { result = JSON.parse(text); } catch (e) { result = { status: 'error', message: 'Invalid response' }; }
        if (result.status === 'success') {
            showToast('Product updated successfully', 'success');
            closeEditProductModal();
            await loadProducts({ force: true });
        } else {
            showToast(result.message || 'Failed to update product', 'error');
        }
    } catch (error) {
        console.error('Error updating product:', error);
        showToast('Failed to update product', 'error');
    } finally {
        if (submitButton) {
            submitButton.disabled = false;
            submitButton.textContent = 'Save Changes';
        }
    }
}

function closeEditProductModal() {
    const modal = document.getElementById('editProductModal');
    if (modal) {
        modal.remove();
    }
}

async function deleteProduct(id) {
    if (!id) return;
    const confirmed = await confirmAction('Are you sure you want to delete this product? This action cannot be undone.');
    if (!confirmed) {
        return;
    }

    try {
        const formData = new FormData();
        formData.append('productID', id);
        const response = await fetch('db/products_delete.php', {
            method: 'POST',
            body: formData
        });
        const result = await response.json();

        if (result.status === 'success') {
            showToast('Product deleted successfully', 'success');
            // Remove from local state for immediate feedback
            products = products.filter(p => String(p.productID) !== String(id));
            renderProducts();
            // Refresh from server to stay in sync with other clients
            await loadProducts({ force: true });
        } else {
            showToast(result.message || 'Failed to delete product', 'error');
        }
    } catch (error) {
        console.error('Error deleting product:', error);
        showToast('Failed to delete product', 'error');
    }
}

// Handle size/unit change to fetch existing price
async function handleSizeUnitChange(e) {
    if (!e.target.classList.contains('size-dropdown') && !e.target.classList.contains('unit-dropdown')) return;

    const productId = e.target.dataset.productId;
    const row = e.target.closest('tr');
    const sizeSelect = row.querySelector('.size-dropdown');
    const unitSelect = row.querySelector('.unit-dropdown');
    const priceInput = row.querySelector('.price-input');

    const sizeId = sizeSelect.value;
    const unitId = unitSelect.value;

    // Save selections to localStorage
    localStorage.setItem(`product_${productId}_size`, sizeId);
    localStorage.setItem(`product_${productId}_unit`, unitId);

    if (!sizeId || !unitId) {
        priceInput.value = '';
        return;
    }

    try {
        const response = await fetch(`db/product_prices_get.php?productID=${productId}&sizeID=${sizeId}&unit_id=${unitId}`);
        const data = await response.json();
        priceInput.value = data.price !== null ? data.price : '';
    } catch (error) {
        console.error('Error fetching price:', error);
        showToast('Failed to fetch price', 'error');
    }
}

// Handle price input change to save
async function handlePriceChange(e) {
    if (!e.target.classList.contains('price-input')) return;

    const productId = e.target.dataset.productId;
    const row = e.target.closest('tr');
    const sizeSelect = row.querySelector('.size-dropdown');
    const unitSelect = row.querySelector('.unit-dropdown');
    const price = parseFloat(e.target.value) || 0;

    const sizeId = sizeSelect.value;
    const unitId = unitSelect.value;
    if (!sizeId || !unitId) {
        showToast('Please select size and unit first', 'warning');
        return;
    }

    try {
        const formData = new FormData();
        formData.append('productID', productId);
        formData.append(`price_${sizeId}_${unitId}`, price);
        const response = await fetch('db/product_prices_set.php', {
            method: 'POST',
            body: formData
        });

        const result = await response.json();
        if (result.status === 'success') {
            showToast('Price saved successfully', 'success');
            // Refresh costing table if it exists on the page
            await refreshCostingTableIfExists();
        } else {
            showToast(result.message || 'Failed to save price', 'error');
        }
    } catch (error) {
        console.error('Error saving price:', error);
        showToast('Failed to save price', 'error');
    }
}

// Helper function to refresh costing table if it exists on the current page
async function refreshCostingTableIfExists() {
    // Small delay to ensure database update is committed
    await new Promise(resolve => setTimeout(resolve, 100));

    // Check if we're on the inventory page with costing table
    const costingTable = document.getElementById('inventory-costing-list');
    if (!costingTable) {
        // Costing table doesn't exist on this page, nothing to refresh
        return;
    }

    // Try to use reloadCostingData from Inventory.js if available
    if (typeof reloadCostingData === 'function') {
        await reloadCostingData();
        return;
    }

    // Fallback: fetch and update costing data directly
    try {
        const cacheBuster = new Date().getTime();
        const response = await fetch(`db/inventory_costing.php?t=${cacheBuster}`, {
            cache: 'no-store',
            headers: {
                'Cache-Control': 'no-cache, no-store, must-revalidate',
                'Pragma': 'no-cache'
            }
        });
        if (response.ok) {
            const costingPayload = await response.json();
            // Update global productCostingData if it exists
            if (typeof window !== 'undefined' && window.productCostingData !== undefined) {
                window.productCostingData = Array.isArray(costingPayload) ? costingPayload : [];
            }
            // Call renderCostingTable if available
            if (typeof renderCostingTable === 'function') {
                renderCostingTable();
            }
        }
    } catch (error) {
        console.error('Error refreshing costing data:', error);
    }
}

// Handle select all checkbox
function handleSelectAllProducts(e) {
    if (e.target.id !== 'selectAllProducts') return;
    const shouldCheck = e.target.checked;
    e.target.indeterminate = false;
    document.querySelectorAll('.product-checkbox').forEach(cb => {
        cb.checked = shouldCheck;
    });
    updateMassDeleteButtonVisibility();
}

// Handle product checkbox changes
function handleProductCheckboxChange(e) {
    if (!e.target.classList.contains('product-checkbox')) return;
    updateMassDeleteButtonVisibility();
}

// Create mass delete button
function createMassDeleteButton() {
    const anchorBtn = document.getElementById('addProductBtn');
    if (!anchorBtn || document.getElementById('massDeleteBtn')) return;

    const massDeleteBtn = document.createElement('button');
    massDeleteBtn.id = 'massDeleteBtn';
    massDeleteBtn.className = 'btn-danger';
    massDeleteBtn.style.cssText = `
        margin-left: 10px;
        padding: 8px 16px;
        background: #e74c3c;
        color: white;
        border: none;
        border-radius: 4px;
        cursor: pointer;
        display: inline-block;
    `;
    massDeleteBtn.type = 'button';
    massDeleteBtn.innerHTML = '<ion-icon name="trash-outline"></ion-icon> Delete Selected';
    massDeleteBtn.onclick = massDeleteProducts;

    anchorBtn.parentNode.insertBefore(massDeleteBtn, anchorBtn.nextSibling);
}

// Update select-all state and mass delete visibility
function updateMassDeleteButtonVisibility() {
    const checkboxes = document.querySelectorAll('.product-checkbox');
    const checkedBoxes = document.querySelectorAll('.product-checkbox:checked');
    const massDeleteBtn = document.getElementById('massDeleteBtn');
    const selectAllCheckbox = document.getElementById('selectAllProducts');

    if (selectAllCheckbox) {
        if (checkboxes.length === 0) {
            selectAllCheckbox.checked = false;
            selectAllCheckbox.indeterminate = false;
        } else {
            const allChecked = checkedBoxes.length === checkboxes.length && checkedBoxes.length > 0;
            selectAllCheckbox.checked = allChecked;
            selectAllCheckbox.indeterminate = checkedBoxes.length > 0 && checkedBoxes.length < checkboxes.length;
        }
    }

    if (checkedBoxes.length > 0) {
        if (!massDeleteBtn) {
            createMassDeleteButton();
        } else {
            massDeleteBtn.style.display = 'inline-block';
        }
    } else if (massDeleteBtn) {
        massDeleteBtn.style.display = 'none';
    }

    updateBulkStatusButtonVisibility();
}

function createBulkStatusButton(id, label, isActive) {
    const anchorBtn = document.getElementById('addProductBtn');
    if (!anchorBtn || document.getElementById(id)) return;

    const button = document.createElement('button');
    button.id = id;
    button.className = isActive ? 'btn-secondary' : 'btn-danger';
    button.style.cssText = `
        margin-left: 10px;
        padding: 8px 16px;
        border: none;
        border-radius: 4px;
        cursor: pointer;
        display: inline-block;
    `;
    button.type = 'button';
    button.innerHTML = label;
    button.addEventListener('click', () => bulkUpdateProductStatus(isActive));

    anchorBtn.parentNode.insertBefore(button, anchorBtn.nextSibling);
}

function updateBulkStatusButtonVisibility() {
    const checkedBoxes = document.querySelectorAll('.product-checkbox:checked');
    const show = checkedBoxes.length > 0;

    const activateBtn = document.getElementById('bulkActivateBtn');
    const deactivateBtn = document.getElementById('bulkDeactivateBtn');

    if (show) {
        if (!activateBtn) {
            createBulkStatusButton('bulkActivateBtn', '<ion-icon name="checkmark-circle-outline"></ion-icon> Activate Selected', true);
        } else {
            activateBtn.style.display = 'inline-block';
        }
        if (!deactivateBtn) {
            createBulkStatusButton('bulkDeactivateBtn', '<ion-icon name="close-circle-outline"></ion-icon> Deactivate Selected', false);
        } else {
            deactivateBtn.style.display = 'inline-block';
        }
    } else {
        if (activateBtn) activateBtn.style.display = 'none';
        if (deactivateBtn) deactivateBtn.style.display = 'none';
    }
}

async function bulkUpdateProductStatus(isActive) {
    const checkedBoxes = document.querySelectorAll('.product-checkbox:checked');
    if (checkedBoxes.length === 0) {
        showToast('No products selected', 'warning');
        return;
    }

    const productIds = Array.from(checkedBoxes).map(cb => cb.dataset.productId);
    const productNames = productIds.map(id => {
        const product = products.find(p => p.productID == id);
        return product ? product.productName : 'Unknown';
    });

    const actionLabel = isActive ? 'activate' : 'deactivate';
    const confirmMessage = `Are you sure you want to ${actionLabel} ${productIds.length} selected product(s)?\n\n${productNames.join('\n')}`;
    const confirmed = await confirmAction(confirmMessage);
    if (!confirmed) return;

    try {
        const formData = new FormData();
        productIds.forEach(id => formData.append('product_ids[]', id));
        formData.append('isActive', isActive ? '1' : '0');

        const response = await fetch('db/products_bulk_update_status.php', {
            method: 'POST',
            body: formData
        });
        const data = await response.json();

        if (data.status !== 'success') {
            throw new Error(data.message || 'Failed to update products');
        }

        showToast(`Successfully ${actionLabel}d ${productIds.length} product(s).`, 'success');
        await loadProducts({ force: true });
    } catch (error) {
        console.error('Bulk status update error:', error);
        showToast(error.message || 'Failed to update product status.', 'error');
    } finally {
        document.querySelectorAll('.product-checkbox').forEach(cb => {
            cb.checked = false;
        });
        updateMassDeleteButtonVisibility();
    }
}

// Mass delete selected products
async function massDeleteProducts() {
    const checkedBoxes = document.querySelectorAll('.product-checkbox:checked');
    if (checkedBoxes.length === 0) {
        showToast('No products selected', 'warning');
        return;
    }

    const productIds = Array.from(checkedBoxes).map(cb => cb.dataset.productId);
    const productNames = productIds.map(id => {
        const product = products.find(p => p.productID == id);
        return product ? product.productName : 'Unknown';
    });

    const confirmMessage = `Are you sure you want to delete ${productIds.length} selected product(s)?\n\n${productNames.join('\n')}`;
    const confirmed = await confirmAction(confirmMessage);
    if (!confirmed) return;

    let successCount = 0;
    let errorCount = 0;

    for (const productId of productIds) {
        try {
            const formData = new FormData();
            formData.append('productID', productId);

            const response = await fetch('db/products_delete.php', {
                method: 'POST',
                body: formData
            });
            const data = await response.json();

            if (data.status === 'success') {
                successCount++;
            } else {
                errorCount++;
                console.error('Error deleting product', productId, data.message);
            }
        } catch (error) {
            errorCount++;
            console.error('Error deleting product', productId, error);
        }
    }

    // Reload products from server so UI reflects deletions without manual refresh
    await loadProducts({ force: true });

    // Hide mass delete button
    const massDeleteBtn = document.getElementById('massDeleteBtn');
    if (massDeleteBtn) {
        massDeleteBtn.style.display = 'none';
    }
    document.querySelectorAll('.product-checkbox').forEach(cb => {
        cb.checked = false;
    });
    updateMassDeleteButtonVisibility();
    updateBulkStatusButtonVisibility();

    // Show result message
    if (errorCount === 0) {
        showToast(`Successfully deleted ${successCount} product(s)`, 'success');
    } else if (successCount === 0) {
        showToast('Failed to delete selected products', 'error');
    } else {
        showToast(`Deleted ${successCount} product(s), ${errorCount} failed`, 'warning');
    }
}

// Create Size Modal
function createSizeModal() {
    const modal = document.createElement('div');
    modal.id = 'addSizeModal';
    modal.className = 'modal';
    modal.style.cssText = `
        position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; display: none; align-items: center; justify-content: center;
    `;
    modal.innerHTML = `
        <div class="modal-content" style="background: white; padding: 20px; border-radius: 8px; max-width: 500px; width: 90%; max-height: 80vh; overflow-y: auto;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h3>Add Size</h3>
                <span class="close" onclick="closeAddSizeModal()" style="cursor: pointer; font-size: 24px; font-weight: bold;">&times;</span>
            </div>

            <!-- Add Size Form -->
            <form id="sizeForm" style="margin-bottom: 20px;">
                <div class="form-group" style="margin-bottom: 15px;">
                    <label for="newSizeName">Size Value:</label>
                    <input type="text" id="newSizeName" placeholder="Enter size" required style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                </div>
                <div style="display: flex; gap: 10px; justify-content: flex-end;">
                    <button type="submit" class="btn-primary" style="padding: 8px 16px; background: #007bff; color: white; border: none; border-radius: 4px; cursor: pointer;">Add Size</button>
                </div>
            </form>

            <!-- Sizes List -->
            <div style="border-top: 1px solid #eee; padding-top: 20px;">
                <h4>Added Sizes:</h4>
                <div id="modalSizeList" style="max-height: 200px; overflow-y: auto;">
                    <!-- Sizes will be loaded here -->
                </div>
            </div>
        </div>
    `;
    document.body.appendChild(modal);

    // Add submit handler
    const form = modal.querySelector('#sizeForm');
    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        await addSize();
        // Clear input after adding
        document.getElementById('newSizeName').value = '';
        // Reload sizes in modal
        loadSizesForModal();
    });

    return modal;
}

function closeAddSizeModal() {
    const modal = document.getElementById('addSizeModal');
    if (modal) {
        modal.classList.remove('show');
        modal.style.display = 'none';
        // Clear input
        const nameInput = document.getElementById('newSizeName');
        if (nameInput) nameInput.value = '';
    }
}

// Load sizes for modal display
async function loadSizesForModal(options) {
    try {
        let modalSizes;
        if (window.DataService) {
            modalSizes = await DataService.fetchActiveSizes(options);
        } else {
            const response = await fetch('db/sizes_get.php');
            modalSizes = await response.json();
        }

        const container = document.getElementById('modalSizeList');
        if (!container) return;

        if (!modalSizes.length) {
            container.innerHTML = '<div style="color: #666; font-style: italic;">No sizes added yet</div>';
            return;
        }

        container.innerHTML = modalSizes.map(size => `
            <div style="padding: 8px 0; border-bottom: 1px solid #f0f0f0;">
                <span>${size.sizeName.replace('oz', '').trim()}</span>
            </div>
        `).join('');
    } catch (error) {
        console.error('Error loading sizes for modal:', error);
        const container = document.getElementById('modalSizeList');
        if (container) {
            container.innerHTML = '<div style="color: #e74c3c;">Failed to load sizes</div>';
        }
    }
}

// Expose functions globally
window.showAddCategoryModal = showAddCategoryModal;
window.closeAddCategoryModal = closeAddCategoryModal;
window.showAddSizeModal = showAddSizeModal;
window.closeAddSizeModal = closeAddSizeModal;
window.showAddProductModal = showAddProductModal;
window.closeProductModal = closeProductModal;
window.massDeleteProducts = massDeleteProducts;
