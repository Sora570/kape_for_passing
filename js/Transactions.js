(function () {
    const state = {
        transactions: [],
        summary: {
            totalRevenue: 0,
            count: 0
        },
        filters: {
            status: '',
            date: '',
            search: '',
            payment: ''
        }
    };

    const selectors = {
        tableBody: 'transactionsTableBody',
        statusFilter: 'transactionStatusFilter',
        paymentFilter: 'transactionPaymentFilter',
        dateFilter: 'transactionDateFilter',
        searchInput: 'transactionSearch',
        todaysRevenue: 'todaysRevenue',
        transactionCount: 'transactionCount',
        averageTransaction: 'averageTransaction',
        summaryDate: 'transactionsSummaryDate'
    };

    document.addEventListener('DOMContentLoaded', () => {
        const tableBody = document.getElementById(selectors.tableBody);
        if (!tableBody) return;
        TransactionsManager.init();
    });

    const TransactionsManager = {
        init() {
            this.cacheDom();
            this.bindEvents();

            // Default to today's date when opening the Transactions tab and prevent future dates
            if (this.dateFilter) {
                const today = new Date().toISOString().slice(0, 10);
                // Prevent selecting future dates in the date picker
                this.dateFilter.max = today;

                if (!this.dateFilter.value) {
                    this.dateFilter.value = today;
                } else if (this.dateFilter.value > today) {
                    // Clamp any pre-filled future date to today
                    this.dateFilter.value = today;
                }

                state.filters.date = this.dateFilter.value;
            }

            this.loadTransactions();
        },

        cacheDom() {
            this.tableBody = document.getElementById(selectors.tableBody);
            this.statusFilter = document.getElementById(selectors.statusFilter);
            this.paymentFilter = document.getElementById(selectors.paymentFilter);
            this.dateFilter = document.getElementById(selectors.dateFilter);
            this.searchInput = document.getElementById(selectors.searchInput);
            this.todaysRevenueEl = document.getElementById(selectors.todaysRevenue);
            this.transactionCountEl = document.getElementById(selectors.transactionCount);
            this.averageTransactionEl = document.getElementById(selectors.averageTransaction);
            this.summaryDateEl = document.getElementById(selectors.summaryDate);
        },

        bindEvents() {
            if (this.statusFilter) {
                this.statusFilter.addEventListener('change', () => {
                    state.filters.status = this.statusFilter.value;
                    this.loadTransactions();
                });
            }

            if (this.paymentFilter) {
                this.paymentFilter.addEventListener('change', () => {
                    state.filters.payment = this.paymentFilter.value;
                    this.render();
                });
            }

            if (this.dateFilter) {
                const today = new Date().toISOString().slice(0, 10);
                this.dateFilter.addEventListener('change', () => {
                    // Prevent picking a future date - clamp and notify
                    if (this.dateFilter.value && this.dateFilter.value > today) {
                        this.dateFilter.value = today;
                        if (typeof showToast === 'function') showToast('Selected date cannot be in the future', 'error');
                    }
                    state.filters.date = this.dateFilter.value;
                    this.loadTransactions();
                });

                // Also handle manual input/blur where some browsers allow typing
                this.dateFilter.addEventListener('blur', () => {
                    if (this.dateFilter.value && this.dateFilter.value > today) {
                        this.dateFilter.value = today;
                        if (typeof showToast === 'function') showToast('Selected date cannot be in the future', 'error');
                        state.filters.date = this.dateFilter.value;
                        this.loadTransactions();
                    }
                });
            }

            if (this.searchInput) {
                this.searchInput.addEventListener('input', () => {
                    // Allow only digits for transaction ID searches
                    const raw = this.searchInput.value || '';
                    const digits = raw.replace(/\D/g, '');
                    if (digits !== raw) this.searchInput.value = digits;
                    state.filters.search = digits;
                    this.render();
                });
            }
        },

        async loadTransactions() {
            if (!this.tableBody) return;

            this.renderLoading();

            const params = new URLSearchParams();
            params.set('limit', '200');

            if (state.filters.status && state.filters.status !== 'all') {
                params.set('type', state.filters.status);
            }

            if (state.filters.date) {
                params.set('date', state.filters.date);
            }

            try {
                const baseUrl = `db/transactions_get.php?${params.toString()}`;
                const url = (typeof window.withBranchFilter === 'function') ? window.withBranchFilter(baseUrl) : baseUrl;
                const response = await fetch(url);
                if (!response.ok) {
                    throw new Error(`Request failed with status ${response.status}`);
                }

                const data = await response.json();
                if (data.status !== 'ok') {
                    throw new Error(data.message || 'Failed to load transactions');
                }

                state.transactions = Array.isArray(data.transactions) ? data.transactions : [];
                state.summary.totalRevenue = Number(data.total_revenue || 0);
                state.summary.count = Number(data.count || 0);

                this.render();
                this.updateSummary();
            } catch (error) {
                console.error('Error loading transactions:', error);
                this.renderError(error.message || 'Unable to load transactions');
                if (typeof showToast === 'function') {
                    showToast('Failed to load transactions', 'error');
                }
            }
        },

        render() {
            if (!this.tableBody) return;

            const filtered = this.getFilteredTransactions();
            if (filtered.length === 0) {
                this.tableBody.innerHTML = `
                    <tr>
                        <td colspan="9" style="text-align:center; padding: 24px; color: #6b7280;">
                            No transactions found
                        </td>
                    </tr>
                `;
                return;
            }

            const rowsHtml = filtered.map(tx => {
                const itemsHtml = renderItemsHtml(tx);
                return `
                    <tr data-transaction-id="${tx.orderID}">
                        <td data-label="Transaction ID">${formatTransactionId(tx.orderID)}</td>
                        <td data-label="Reference Number">${formatReference(tx.referenceNumber)}</td>
                        <td data-label="Date & Time">${formatDateTime(tx.order_date)}</td>
                        <td data-label="Cashier ID">${escapeHtml(tx.cashier_id || '-')}</td>
                        <td data-label="Items">${itemsHtml}</td>
                        <td data-label="Total">${formatCurrency(tx.totalAmount)}</td>
                        <td data-label="Payment Method">${formatPayment(tx.payment_method)}</td>
                        <td data-label="Status">
                            <span class="status-badge ${statusClass(tx.status)}">
                                ${escapeHtml(formatStatusLabel(tx.status))}
                            </span>
                        </td>
                        <td data-label="Actions">
                            <button class="btn-small btn-primary transaction-view-btn" data-transaction="${tx.orderID}">
                                View
                            </button>
                        </td>
                    </tr>
                `;
            }).join('');

            this.tableBody.innerHTML = rowsHtml;

            this.bindRowActions(filtered);
        },

        bindRowActions(transactions) {
            const mapById = new Map(transactions.map(item => [String(item.orderID), item]));
            const buttons = this.tableBody.querySelectorAll('.transaction-view-btn');
            buttons.forEach(button => {
                button.addEventListener('click', (event) => {
                    event.stopPropagation();
                    const id = button.getAttribute('data-transaction');
                    const transaction = mapById.get(String(id));
                    if (transaction) {
                        this.showTransactionDetails(transaction);
                    }
                });
            });
        },

        showTransactionDetails(transaction) {
            const referenceDisplay = getReferenceValue(transaction);
            const itemsDetailHtml = renderItemsDetailHtml(transaction);
            const content = `
                <div style="display:flex; flex-direction:column; gap:12px;">
                    <div><strong>Transaction ID:</strong> ${formatTransactionId(transaction.orderID)}</div>
                    <div><strong>Reference:</strong> ${formatReference(transaction.referenceNumber)}</div>
                    <div><strong>Date:</strong> ${formatDateTime(transaction.order_date)}</div>
                    <div><strong>Cashier ID:</strong> ${escapeHtml(transaction.cashier_id || '-')}</div>
                    <div><strong>Status:</strong> ${escapeHtml(formatStatusLabel(transaction.status))}</div>
                    ${renderVoidDetails(transaction)}
                    <div><strong>Payment Method:</strong> ${formatPayment(transaction.payment_method)}</div>
                    ${renderTenderedDetails(transaction)}
                    <div><strong>Total:</strong> ${formatCurrency(transaction.totalAmount)}</div>
                    <div>
                        <strong>Items:</strong>
                        <div style="margin-top:8px;">${itemsDetailHtml}</div>
                    </div>
                    ${renderVoidAction(transaction)}
                </div>
            `;

            if (window.ModalHelper && typeof ModalHelper.open === 'function') {
                ModalHelper.open({
                    id: 'transactionDetailModal',
                    title: `Transaction #${transaction.orderID}`,
                    content,
                    width: '520px'
                });
                setTimeout(() => bindVoidAction(transaction), 0);
            } else if (typeof showToast === 'function') {
                showToast('Unable to open details modal.', 'warning');
            }
        },

        updateSummary() {
            if (this.todaysRevenueEl) {
                this.todaysRevenueEl.textContent = formatCurrency(state.summary.totalRevenue);
            }
            if (this.transactionCountEl) {
                this.transactionCountEl.textContent = state.summary.count.toString();
            }
            if (this.averageTransactionEl) {
                const avg = state.summary.count > 0
                    ? state.summary.totalRevenue / state.summary.count
                    : 0;
                this.averageTransactionEl.textContent = formatCurrency(avg);
            }

            // Update summary date label (Today / selected date / All Dates)
            if (this.summaryDateEl) {
                const sel = state.filters.date;
                if (!sel) {
                    this.summaryDateEl.textContent = 'All Dates';
                } else {
                    const today = new Date().toISOString().slice(0,10);
                    if (sel === today) {
                        this.summaryDateEl.textContent = 'Today';
                    } else {
                        // Friendly format YYYY-MM-DD -> Mon DD, YYYY
                        const d = new Date(sel + 'T00:00:00');
                        this.summaryDateEl.textContent = d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
                    }
                }
            }
        },


        getFilteredTransactions() {
            const search = state.filters.search;
            const paymentFilter = state.filters.payment;

            return state.transactions.filter(tx => {
                const haystack = [
                    tx.referenceNumber,
                    tx.orderID,
                    getItemsSearchText(tx),
                    tx.cashier_id,
                    tx.payment_method,
                    tx.status
                ].map(value => (value || '').toString().toLowerCase());

                const matchesSearch = !search || haystack.some(value => value.includes(search));
                const matchesPayment = !paymentFilter ||
                    (tx.payment_method || '').toLowerCase() === paymentFilter.toLowerCase();
                return matchesSearch && matchesPayment;
            });
        },

        renderLoading() {
            if (!this.tableBody) return;
            this.tableBody.innerHTML = `
                <tr>
                    <td colspan="9" style="text-align:center; padding: 24px; color: #6b7280;">
                        Loading transactions...
                    </td>
                </tr>
            `;
        },

        renderError(message) {
            if (!this.tableBody) return;
            this.tableBody.innerHTML = `
                <tr>
                    <td colspan="9" style="text-align:center; padding: 24px; color: #dc2626;">
                        ${escapeHtml(message)}
                    </td>
                </tr>
            `;
        }
    };

    function formatTransactionId(id) {
        if (id === null || id === undefined || id === '') return '-';
        const numeric = Number(id);
        const value = Number.isFinite(numeric) ? numeric.toString() : id.toString();
        return escapeHtml(value);
    }

    function formatReference(reference) {
        const value = reference && reference.toString().trim() !== '' ? reference : '';
        return escapeHtml(value);
    }

    function getReferenceValue(transaction) {
        if (!transaction) return '';
        const reference = transaction.referenceNumber;
        if (reference === null || reference === undefined) return '';
        const text = reference.toString().trim();
        return text !== '' ? text : '';
    }

    function getItemLines(tx) {
        if (!tx) return [];
        if (Array.isArray(tx.items_list)) {
            return tx.items_list
                .map(line => (line === null || line === undefined) ? '' : String(line).trim())
                .filter(line => line !== '');
        }
        if (Array.isArray(tx.items)) {
            return tx.items
                .map(line => (line === null || line === undefined) ? '' : String(line).trim())
                .filter(line => line !== '');
        }
        if (typeof tx.items === 'string') {
            const trimmed = tx.items.trim();
            if (trimmed === '') return [];
            const separators = trimmed.includes("\n") ? /\r?\n/ : /,\s*/;
            return trimmed.split(separators).map(part => part.trim()).filter(part => part !== '');
        }
        return [];
    }

    function renderItemsHtml(tx) {
        const lines = getItemLines(tx);
        if (lines.length === 0) {
            const fallback = typeof tx.items === 'string' && tx.items.trim() !== '' ? tx.items : 'No items';
            return escapeHtml(fallback);
        }
        if (lines.length === 1) {
            const single = String(lines[0]);
            if (single.toLowerCase() === 'no items') {
                return escapeHtml(single);
            }
        }
        return lines.map(line => escapeHtml(String(line))).join('<br>');
    }

    function renderItemsDetailHtml(tx) {
        const lines = getItemLines(tx);
        if (lines.length === 0) {
            const fallback = typeof tx.items === 'string' && tx.items.trim() !== '' ? tx.items : 'No items';
            return escapeHtml(fallback);
        }
        if (lines.length === 1) {
            const single = String(lines[0]);
            if (single.toLowerCase() === 'no items') {
                return escapeHtml(single);
            }
        }
        const items = lines.map(line => `<li>${escapeHtml(String(line))}</li>`).join('');
        return `<ul style="margin:0; padding-left:18px;">${items}</ul>`;
    }

    function getItemsSearchText(tx) {
        const lines = getItemLines(tx);
        if (lines.length > 0) {
            return lines.join(' ');
        }
        if (typeof tx.items === 'string') {
            return tx.items;
        }
        return '';
    }

    function formatDateTime(value) {
        if (!value) return '-';
        const date = new Date(value);
        if (Number.isNaN(date.getTime())) return value;
        return date.toLocaleString();
    }

    function formatCurrency(amount) {
        const number = Number(amount) || 0;
        try {
            return new Intl.NumberFormat('en-PH', {
                style: 'currency',
                currency: 'PHP'
            }).format(number);
        } catch (error) {
            return `PHP ${number.toFixed(2)}`;
        }
    }

    function formatPayment(method) {
        if (!method) return '-';
        const normalized = method.toString().toLowerCase();
        if (normalized === 'gcash') return 'GCash';
        return normalized.charAt(0).toUpperCase() + normalized.slice(1);
    }

    function renderTenderedDetails(transaction) {
        const method = (transaction?.payment_method || '').toLowerCase();
        if (method !== 'cash') return '';
        const tendered = transaction?.cash_tendered;
        const change = transaction?.change_amount;
        const tenderedDisplay = Number.isFinite(tendered) ? formatCurrency(tendered) : '-';
        const changeDisplay = Number.isFinite(change) ? formatCurrency(change) : '-';
        return `
            <div><strong>Cash Tendered:</strong> ${tenderedDisplay}</div>
            <div><strong>Change:</strong> ${changeDisplay}</div>
        `;
    }

    function renderVoidDetails(transaction) {
        if (!transaction || !transaction.void_reason) return '';
        const reason = escapeHtml(transaction.void_reason);
        const voidedAt = transaction.voided_at ? formatDateTime(transaction.voided_at) : '-';
        return `
            <div style="color:#b91c1c;"><strong>Void Reason:</strong> ${reason}</div>
            <div style="color:#b91c1c;"><strong>Voided At:</strong> ${voidedAt}</div>
        `;
    }

    function renderVoidAction(transaction) {
        const status = (transaction?.status || '').toLowerCase();
        if (status === 'cancelled') {
            return '<div style="color:#b91c1c;"><strong>Voided By:</strong> ' + escapeHtml(String(transaction?.voided_by ?? '-')) + '</div>';
        }
        return `
            <div style="margin-top:8px;">
                <button type="button" class="btn-small btn-danger" id="transactionVoidBtn">Void Transaction</button>
            </div>
        `;
    }

    function bindVoidAction(transaction) {
        const button = document.getElementById('transactionVoidBtn');
        if (!button) return;
        button.addEventListener('click', () => {
            // Create custom modal for void reason
            const modalHtml = `
                <div style="padding: 20px;">
                    <h3 style="margin: 0 0 10px 0; color: #dc3545;">Void Transaction</h3>
                    <p style="margin-bottom: 15px; color: #666;">Please provide a reason for voiding this transaction:</p>
                    <textarea id="voidReasonInput" 
                              style="width: 100%; min-height: 80px; padding: 10px; border: 1px solid #ddd; border-radius: 4px; font-family: inherit; resize: vertical;"
                              placeholder="Enter void reason..."
                              maxlength="500"></textarea>
                    <div style="margin-top: 15px; display: flex; gap: 10px; justify-content: flex-end;">
                        <button id="voidCancelBtn" style="padding: 8px 16px; background: #6c757d; color: white; border: none; border-radius: 4px; cursor: pointer;">Cancel</button>
                        <button id="voidConfirmBtn" style="padding: 8px 16px; background: #dc3545; color: white; border: none; border-radius: 4px; cursor: pointer;">Void Transaction</button>
                    </div>
                </div>
            `;

            const modal = createModalHelper(modalHtml);
            const textarea = document.getElementById('voidReasonInput');
            const confirmBtn = document.getElementById('voidConfirmBtn');
            const cancelBtn = document.getElementById('voidCancelBtn');

            // Auto-focus textarea
            setTimeout(() => textarea.focus(), 100);

            // Cancel handler
            cancelBtn.addEventListener('click', () => modal.close());

            // Confirm handler
            confirmBtn.addEventListener('click', async () => {
                const reason = textarea.value.trim();
                
                if (!reason) {
                    if (typeof showToast === 'function') {
                        showToast('Please enter a void reason', 'error');
                    }
                    textarea.focus();
                    return;
                }

                // Disable buttons during processing
                confirmBtn.disabled = true;
                cancelBtn.disabled = true;
                confirmBtn.textContent = 'Processing...';

                try {
                    const response = await fetch('db/orders_void_request.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: `orderID=${encodeURIComponent(transaction.orderID)}&void_reason=${encodeURIComponent(reason)}`
                    });
                    const payload = await response.json();
                    
                    if (response.ok && payload.status === 'success') {
                        if (typeof showToast === 'function') {
                            showToast('Void request sent for approval.', 'success');
                        }
                        modal.close();
                        if (TransactionsManager && typeof TransactionsManager.loadTransactions === 'function') {
                            TransactionsManager.loadTransactions();
                        }
                    } else {
                        if (typeof showToast === 'function') {
                            showToast(payload.message || 'Failed to request void', 'error');
                        }
                        // Re-enable buttons on error
                        confirmBtn.disabled = false;
                        cancelBtn.disabled = false;
                        confirmBtn.textContent = 'Void Transaction';
                    }
                } catch (error) {
                    console.error('Error voiding transaction:', error);
                    if (typeof showToast === 'function') {
                        showToast('Error voiding transaction', 'error');
                    }
                    // Re-enable buttons on error
                    confirmBtn.disabled = false;
                    cancelBtn.disabled = false;
                    confirmBtn.textContent = 'Void Transaction';
                }
            });

            // Allow Enter key to submit (Shift+Enter for new line)
            textarea.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    confirmBtn.click();
                }
            });
        });
    }

    // Helper function to create modal (uses existing modal backdrop if available)
    function createModalHelper(innerHtml) {
        const backdrop = document.createElement('div');
        backdrop.className = 'modal-backdrop';
        backdrop.style.cssText = 'position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); display: flex; align-items: center; justify-content: center; z-index: 9999;';
        
        const modal = document.createElement('div');
        modal.style.cssText = 'background: white; border-radius: 8px; max-width: 500px; width: 90%; max-height: 90vh; overflow: auto; box-shadow: 0 4px 20px rgba(0,0,0,0.3);';
        modal.innerHTML = innerHtml;
        
        backdrop.appendChild(modal);
        document.body.appendChild(backdrop);
        
        // Close on backdrop click
        backdrop.addEventListener('click', (e) => {
            if (e.target === backdrop) {
                backdrop.remove();
            }
        });
        
        return {
            close: () => backdrop.remove()
        };
    }

    function normalizeStatus(status) {
        if (!status) return '';
        return status.toLowerCase().replace(/\s+/g, '_').replace(/-+/g, '_');
    }

    function statusClass(status) { 
        const normalized = normalizeStatus(status);
        if (!normalized) return 'status-completed'; // Default for legacy orders with empty status
        if (normalized === 'completed' || normalized === 'complete') return 'status-completed';
        if (normalized === 'pending') return 'status-pending';
        if (normalized === 'pending_void') return 'status-pending-void';
        if (normalized === 'cancelled' || normalized === 'canceled') return 'status-cancelled';
        return `status-${normalized}`;
    }

    function formatStatusLabel(status) {
        const normalized = normalizeStatus(status);
        if (!normalized) return 'Completed'; // Default for legacy orders with empty status
        if (normalized === 'pending_void') return 'Pending Void';
        if (normalized === 'pending') return 'Pending';
        if (normalized === 'completed' || normalized === 'complete') return 'Completed';
        if (normalized === 'cancelled' || normalized === 'canceled') return 'Cancelled';
        return status;
    }

    function escapeHtml(value) {
        if (value === null || value === undefined) return '';
        return value.toString()
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    window.TransactionsManager = TransactionsManager;
})();
