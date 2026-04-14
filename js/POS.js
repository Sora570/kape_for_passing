// POS (Point of Sale) Cashier JavaScript
// Cashier-specific functionality for the POS interface

let posCart = [];
let allProducts = [];

// Initialize POS when page loads
function initPOS() {
    loadProducts();
    loadTodaysStats();
    setupPOSEventListeners();
    updateCart();
}

// Load products from server
async function loadProducts() {
    try {
        const cacheBuster = Date.now();
        const [productsResponse, categoriesResponse] = await Promise.all([
            fetch(`db/products_get.php?t=${cacheBuster}`, { cache: 'no-store' }),
            window.DataService ? DataService.fetchActiveCategories() : fetch('db/categories_get.php', { cache: 'no-store' }).then(r => r.json())
        ]);
        if (!productsResponse.ok) throw new Error('Failed to fetch products');

        const productsPayload = await productsResponse.json();
        const productsList = Array.isArray(productsPayload?.products) ? productsPayload.products : Array.isArray(productsPayload) ? productsPayload : [];
        const activeCategoryIds = new Set((Array.isArray(categoriesResponse) ? categoriesResponse : []).map(cat => Number(cat.categoryID)));

        allProducts = productsList
            .filter(product => activeCategoryIds.has(Number(product.categoryID)))
            .filter(product => {
                const activeValue = product.isActive ?? product.is_active ?? 1;
                return activeValue !== false && Number(activeValue) !== 0;
            });
        renderProductGrid();
    } catch (error) {
        console.error('Error loading products:', error);
        allProducts = [];
        renderProductGrid();
    }
}

// Load today's statistics and orders list
async function loadTodaysStats() {
    const todayItemsEl = document.getElementById('todayItemsCount');
    const todaySalesEl = document.getElementById('todaySalesTotal');
    const posOrdersList = document.getElementById('posOrdersList');
    
    try {
        // Fetch orders data from server
        const response = await fetch('db/orders_get.php');
        if (!response.ok) throw new Error('Failed to fetch orders');
        
        const data = await response.json();
        const combinedOrders = [
            ...(data.pending || []),
            ...(data.pending_void || []),
            ...(data.completed || []),
            ...(data.cancelled || [])
        ];
        const orderMap = new Map();
        combinedOrders.forEach((order) => {
            const id = order.orderID ?? order.sale_id;
            if (id !== undefined && id !== null) {
                orderMap.set(id, order);
            }
        });
        const allOrders = Array.from(orderMap.values());
        
        // Update today stats
        const todayOrders = allOrders.filter(order => {
            const today = new Date().toDateString();
            const orderDate = new Date(order.createdAt || order.created_at).toDateString();
            return today === orderDate;
        });
        
        const todayTotal = todayOrders.reduce((sum, order) => sum + parseFloat(order.totalAmount || order.total || 0), 0);
        
        if (todayItemsEl) todayItemsEl.textContent = todayOrders.length;
        if (todaySalesEl) todaySalesEl.textContent = `₱${todayTotal.toFixed(2)}`;
        
        // Display orders list
        if (posOrdersList) {
            renderOrdersList(allOrders, posOrdersList);
        }
        
    } catch (error) {
        console.error('Error loading orders:', error);
        
        // Fallback display
        if (todayItemsEl) todayItemsEl.textContent = '0';
        if (todaySalesEl) todaySalesEl.textContent = '₱0.00';
        
        if (posOrdersList) {
            posOrdersList.innerHTML = `
                <div style="text-align:center; padding: 2rem; color: #6b7280;">
                    <h3 style="margin:0; color: #9ca3af;">Recent Orders</h3>
                    <p style="margin:0.5rem 0; font-size: 0.875rem;">Unable to load orders</p>
                </div>
            `;
        }
    }
}

// Render orders list for cashier
function renderOrdersList(orders, container) {
    if (!container) return;
    
    if (orders.length === 0) {
        container.innerHTML = `
            <div style="text-align:center; padding: 2rem; color: #6b7280;">
                <h3 style="margin:0; color: #9ca3af;">No Recent Orders</h3>
                <p style="margin:0.5rem 0; font-size: 0.875rem;">Orders will appear here</p>
            </div>
        `;
        return;
    }
    
    container.innerHTML = orders.map(order => {
        const status = (order.status || 'pending').toLowerCase();
        const isCompleted = status === 'completed';
        const isCancelled = status === 'cancelled';
        const isPendingVoid = status === 'pending_void';
        const badgeBg = isCompleted ? '#d1fae5' : isCancelled ? '#fee2e2' : isPendingVoid ? '#ede9fe' : '#fef3c7';
        const badgeColor = isCompleted ? '#065f46' : isCancelled ? '#991b1b' : isPendingVoid ? '#6d28d9' : '#92400e';
        const statusLabel = isPendingVoid ? 'Pending Void' : status;
        const voidDetails = order.void_reason ? `\n                        <p style="margin: 0.25rem 0; color: #b91c1c; font-size: 0.8125rem;">\n                            Voided: ${order.void_reason}\n                        </p>` : '';
        return `
        <div class="order-item-card" style="border: 1px solid #e5e7eb; margin: 0.5rem; padding: 1rem; border-radius: 8px; background: white;">
            <div style="display: flex; justify-content: space-between; align-items: start;">
                <div>
                    <h4 style="margin: 0; color: #1f2937;">Order #${order.orderID}</h4>

                    <p style="margin: 0.25rem 0; color: #6b7280; font-size: 0.875rem;">
                        Items: ${order.items || 'Unknown'}
                    </p>
                    <p style="margin: 0.25rem 0; font-weight: 600; color: #059669;">
                        Total: ₱${parseFloat(order.totalAmount || order.total || 0).toFixed(2)}
                    </p>
                    <span class="order-status badge-${status}" 
                          style="
                            display: inline-block; 
                            padding: 0.25rem 0.5rem; 
                            font-size: 0.75rem; 
                            border-radius: 4px;
                            background: ${badgeBg};
                            color: ${badgeColor};
                          ">
                        ${statusLabel}
                    </span>
                    ${voidDetails}
                </div>
                <div style="display: flex; gap: 0.5rem; flex-direction: column;">
                    ${!isCompleted && !isCancelled && !isPendingVoid ? `
                        <button onclick="markOrderComplete(${order.orderID})" 
                                style="
                                    padding: 0.5rem 1rem; 
                                    background: #10b981; 
                                    color: white; 
                                    border: none; 
                                    border-radius: 4px; 
                                    font-size: 0.875rem; 
                                    cursor: pointer;
                                ">
                            Complete Order
                        </button>
                    ` : isCancelled ? `
                        <span style="color: #b91c1c; font-size: 0.875rem;">✕ Cancelled</span>
                    ` : isPendingVoid ? `
                        <span style="color: #6d28d9; font-size: 0.875rem;">Pending Void</span>
                    ` : `
                        <span style="color: #059669; font-size: 0.875rem;">✓ Completed</span>
                    `}
                    ${!isCancelled && !isPendingVoid ? `
                        <button onclick="voidOrder(${order.orderID})" 
                                style="
                                    padding: 0.5rem 1rem; 
                                    background: #ef4444; 
                                    color: white; 
                                    border: none; 
                                    border-radius: 4px; 
                                    font-size: 0.875rem; 
                                    cursor: pointer;
                                ">
                            Void Order
                        </button>
                    ` : ''}
                </div>
            </div>
        </div>
    `;
    }).join('');
}

// Mark order as complete
async function markOrderComplete(orderID) {
    try {
        const response = await fetch('db/orders_update.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `orderID=${orderID}&status=completed`
        });
        
        if (response.ok) {
            showToast && showToast('Order marked as complete!', 'success');
            // Refresh the orders list
            loadTodaysStats();
        } else {
            showToast && showToast('Failed to update order', 'error');
        }
    } catch (error) {
        console.error('Error updating order:', error);
        showToast && showToast('Error updating order', 'error');
    }
}

// Void an order with required reason
async function voidOrder(orderID) {
    const reason = prompt('Enter void reason (required):');
    if (!reason || !reason.trim()) {
        showToast && showToast('Void reason is required.', 'error');
        return;
    }

    try {
        const response = await fetch('db/orders_void_request.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `orderID=${encodeURIComponent(orderID)}&void_reason=${encodeURIComponent(reason.trim())}`
        });
        const payload = await response.json();
        if (response.ok && payload.status === 'success') {
            showToast && showToast('Void request sent for approval.', 'success');
            loadTodaysStats();
        } else {
            showToast && showToast(payload.message || 'Failed to request void', 'error');
        }
    } catch (error) {
        console.error('Error voiding order:', error);
        showToast && showToast('Error voiding order', 'error');
    }
}

// Render products grid
function renderProductGrid(){
    const grid = document.getElementById('posProductsGrid');
    if (!grid) return;

    grid.innerHTML = '';

    if (!allProducts.length) {
        grid.innerHTML = '<div class="muted" style="grid-column: 1 / -1; text-align: center; padding: 2rem;">No products available</div>';
        return;
    }

    allProducts.forEach(product => {
        const productCard = createProductCard(product);
        grid.appendChild(productCard);
    });
}

// Create product card element 
function createProductCard(product) {
    const card = document.createElement('div');
    card.className = 'pos-product-card';
    
    const categoryLabel = product.categoryName || product.category || '';
    const imageSymbol = categoryLabel === 'Foods' ? '🍽️' : categoryLabel === 'Drinks' ? '☕' : '🛒';
    const productName = product.productName || product.name || '';
    const safeCategoryLabel = escapeHtml(categoryLabel);
    const safeProductName = escapeHtml(productName);
    const productId = product.productID || product.id;
    const price = getLowestPrice(product);
    
    card.innerHTML = `
        <div class="pos-product-image">${imageSymbol}</div>
        <div class="pos-product-name">${safeProductName}</div>
        <div class="pos-product-category">${safeCategoryLabel}</div>
        <div class="pos-product-price">₱${price}</div>
        <button class="pos-add-btn" onclick="addToCart('${productId}', '${productName.replace(/'/g, "\\'")}', ${price})">+</button>
    `;
    
    return card;
}

// Get lowest price from size variants
function getLowestPrice(product) {
    if (product.base_price != null) {
        const base = parseFloat(product.base_price);
        if (!Number.isNaN(base)) return base.toFixed(2);
    }

    const sizeList = Array.isArray(product.sizes)
        ? product.sizes
        : Array.isArray(product.size)
            ? product.size
            : [];
    if (!sizeList.length) return '0.00';
    const prices = sizeList
        .map(entry => entry?.price)
        .map(price => parseFloat(price))
        .filter(price => !Number.isNaN(price));
    if (!prices.length) return '0.00';
    return Math.min(...prices).toFixed(2);
}

// Add product to cart
function addToCart(productId, productName, price) {
    const existingItem = posCart.find(item => item.id === productId);
    
    if (existingItem) {
        existingItem.quantity += 1;
    } else {
        posCart.push({
            id: productId,
            name: productName,
            price: price,
            quantity: 1
        });
    }
    
    updateCart();
}

// Remove item from cart
function removeFromCart(productId) {
    posCart = posCart.filter(item => item.id !== productId);
    updateCart();
}

// Adjust quantity
function adjustQuantity(productId, change) {
    const item = posCart.find(item => item.id === productId);
    if (!item) return;
    
    item.quantity += change;
    if (item.quantity <= 0) {
        removeFromCart(productId);
    } else {
        updateCart();
    }
}

// Update cart display
function updateCart() {
    const itemsList = document.getElementById('orderItemsList');
    if (!itemsList) return;

    // Clear cart
    if (posCart.length === 0) {
        itemsList.innerHTML = '<div class="empty-cart">No items in cart</div>';
    } else {
        itemsList.innerHTML = '';
        posCart.forEach(item => {
            const itemElement = document.createElement('div');
            itemElement.className = 'order-item';
            itemElement.innerHTML = `
                <div class="order-item-info">
                    <div class="order-item-name">${item.name}</div>
                    <div class="order-item-price">₱${item.price}</div>
                </div>
                <div class="order-item-quantity">
                    <button class="quantity-btn" onclick="adjustQuantity('${item.id}', -1)">-</button>
                    <span class="adjust-quantity">${item.quantity}</span>
                    <button class="quantity-btn" onclick="adjustQuantity('${item.id}', 1)">+</button>
                    <button class="remove-item" onclick="removeFromCart('${item.id}')">Remove</button>
                </div>
            `;
            itemsList.appendChild(itemElement);
        });
    }
    
    updateTotals();
}

// Update order totals
function updateTotals() {
    const subtotalEl = document.getElementById('subtotal');
    const discountEl = document.getElementById('discountAmount');
    const totalEl = document.getElementById('totalAmount');
    const checkoutBtn = document.getElementById('completeOrderBtn');
    
    const subtotal = calculateSubtotal();
    const discount = calculateDiscount();
    const total = subtotal - discount;
    
    if (subtotalEl) subtotalEl.textContent = `₱${subtotal.toFixed(2)}`;
    if (discountEl) discountEl.textContent = `₱${discount.toFixed(2)}`;
    if (totalEl) totalEl.textContent = `₱${total.toFixed(2)}`;
    if (checkoutBtn) {
        checkoutBtn.disabled = posCart.length === 0 || total <= 0;
    }
    
    // Update change calculation if amount tendered is entered
    updateChange();
}

// Calculate subtotal
function calculateSubtotal() {
    return posCart.reduce((sum, item) => sum + (item.price * item.quantity), 0);
}

// Calculate discount
function calculateDiscount() {
    const seniorCheckbox = document.getElementById('seniorCitizenDiscount');
    const pwdCheckbox = document.getElementById('pwdDiscount');
    const subtotal = calculateSubtotal();
    
    let discount = 0;
    if (seniorCheckbox && seniorCheckbox.checked) discount += subtotal * 0.2;
    if (pwdCheckbox && pwdCheckbox.checked) discount += subtotal * 0.2;
    
    // Only apply one discount maximum
    return Math.min(discount, subtotal * 0.2);
}

// Update change calculation for cash payments
function updateChange() {
    const amountTendered = parseFloat(document.getElementById('amountTendered')?.value || 0);
    const subtotal = calculateSubtotal();
    const discount = calculateDiscount();
    const total = subtotal - discount;
    const changeEl = document.getElementById('changeAmount');
    
    if (amountTendered > total && changeEl) {
        const change = amountTendered - total;
        changeEl.style.display = 'block';
        changeEl.innerHTML = `<span>Change: ₱${change.toFixed(2)}</span>`;
    } else if (changeEl) {
        changeEl.style.display = 'none';
    }
}

// Clear entire cart
function clearCart() {
    posCart = [];
    updateCart();
    
    // Clear form inputs
    const seniorCheckbox = document.getElementById('seniorCitizenDiscount');
    const pwdCheckbox = document.getElementById('pwdDiscount');
    const amountTendered = document.getElementById('amountTendered');
    
    if (seniorCheckbox) seniorCheckbox.checked = false;
    if (pwdCheckbox) pwdCheckbox.checked = false;
    if (amountTendered) amountTendered.value = '';
}

// Complete the order (checkout)
function completeOrder() {
    if (posCart.length === 0) {
        showToast && showToast('No items in cart', 'warning');
        return;
    }
    
    const total = calculateSubtotal() - calculateDiscount();
    const paymentMethod = document.querySelector('input[name="paymentMethod"]:checked')?.value;
    const amountTendered = parseFloat(document.getElementById('amountTendered')?.value || 0);
    
    // Validate cash payment if selected
    if (paymentMethod === 'cash' && amountTendered < total) {
        showToast && showToast('Amount tendered is less than total amount', 'warning');
        return;
    }
    
    // Create order object
    const order = {
        items: posCart,
        subtotal: calculateSubtotal(),
        discount: calculateDiscount(),
        total: total,
        paymentMethod: paymentMethod,
        amountTendered: paymentMethod === 'cash' ? amountTendered : total,
        change: parseInt(((amountTendered || total) - total) * 100) / 100
    };
    
    // Here you would normally submit to server
    console.log('Order completed:', order);
    
    // Show confirmation
    showToast && showToast(`Order completed for ₱${total.toFixed(2)}`, 'success');
    
    // Clear cart after successful order
    clearCart();
    
    // Optional: Increase today's sales total
    updateTodayStats();
}

// Update today's statistics
function updateTodayStats() {
    // This would normally fetch from the server, but for demo,
    const todayItemsEl = document.getElementById('todayItemsCount');
    const todaySalesEl = document.getElementById('todaySalesTotal');
    
    if (todayItemsEl) {
        todayItemsEl.textContent = (parseInt(todayItemsEl.textContent) || 0) + getTotalItemCount();
    }
    
    if (todaySalesEl) {
        const subtotal = calculateSubtotal();
        const discount = calculateDiscount();
        const total = subtotal - discount;
        const currentTotal = parseFloat(todaySalesEl.textContent.replace('₱', '') || '0');
        if (todaySalesEl) todaySalesEl.textContent = `₱${(currentTotal + total).toFixed(2)}`;
    }
}

// Get total item quantity in cart
function getTotalItemCount() {
    return posCart.reduce((count, item) => count + item.quantity, 0);
}

// Set up event listeners
function setupPOSEventListeners() {
    // Clear cart button
    const clearBtn = document.getElementById('clearCartBtn');
    if (clearBtn) {
        clearBtn.addEventListener('click', clearCart);
    }
    
    // Complete order button
    const checkoutBtn = document.getElementById('completeOrderBtn');
    if (checkoutBtn) {
        checkoutBtn.addEventListener('click', completeOrder);
    }
    
    // Discount checkboxes
    const discountCheckboxes = document.querySelectorAll('#seniorCitizenDiscount, #pwdDiscount');
    discountCheckboxes.forEach(checkbox => {
        checkbox.addEventListener('change', updateTotals);
    });
    
    // Amount tendered input
    const amountInput = document.getElementById('amountTendered');
    if (amountInput) {
        amountInput.addEventListener('input', updateChange);
    }
    
    // Payment method change
    const paymentMethods = document.querySelectorAll('input[name="paymentMethod"]');
    paymentMethods.forEach(radio => {
        radio.addEventListener('change', updateChange);
    });
}

// Global functions to be called from HTML
window.addToCart = addToCart;
window.removeFromCart = removeFromCart;
window.adjustQuantity = adjustQuantity;
window.clearCart = clearCart;
window.completeOrder = completeOrder;
window.loadTodaysStats = loadTodaysStats;
window.markOrderComplete = markOrderComplete;
window.voidOrder = voidOrder;

// Auto-initialize when page loads for cashier role
if (document.body.classList.contains('cashier-mode')) {
    document.addEventListener('DOMContentLoaded', initPOS);
}


