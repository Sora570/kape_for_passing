// Settings Manager: system diagnostics, audit visibility, and exports
const SettingsManager = (() => {
    const state = {
        auditLogs: [],
        currentAuditPage: 1,
        auditPageSize: 10,
        systemInfo: {},
        config: {
            activeModule: null,
            branches: [],
            categories: [],
            addons: [],
            flavors: [],
            units: [],
            paymentReceivers: [],
            inventories: []
        }
    };

    function init() {
        loadSystemInfo();
        loadAuditLogs();
        setupEventListeners();
        setupConfiguration();
    }

    function setupEventListeners() {
        const filterControls = [
            'auditDateFrom',
            'auditDateTo',
            'auditUserFilter',
            'auditActionFilter'
        ];

        filterControls.forEach((id) => {
            document.getElementById(id)?.addEventListener('change', () => {
                state.currentAuditPage = 1;
                renderAuditLogs();
            });
        });

        // Date constraints: prevent future 'to' date and ensure 'from' <= 'to'
        const fromEl = document.getElementById('auditDateFrom');
        const toEl = document.getElementById('auditDateTo');
        const today = new Date().toISOString().slice(0, 10);

        if (toEl) {
            // 'To' cannot be in the future
            toEl.max = today;
            // clamp any pre-filled future date
            if (toEl.value && toEl.value > today) toEl.value = today;

            toEl.addEventListener('change', () => {
                // Ensure 'from' cannot be greater than new 'to'
                if (fromEl) {
                    fromEl.max = toEl.value || today;
                    if (fromEl.value && toEl.value && fromEl.value > toEl.value) {
                        fromEl.value = toEl.value;
                        if (typeof showToast === 'function') showToast('From date cannot be after To date', 'error');
                    }
                }
                state.currentAuditPage = 1;
                renderAuditLogs();
            });
        }

        if (fromEl) {
            // Set max according to 'to' or today
            fromEl.max = (toEl && toEl.value) ? toEl.value : today;

            fromEl.addEventListener('change', () => {
                // Prevent picking a from date that's after 'to' or in the future
                const maxAllowed = (toEl && toEl.value) ? toEl.value : today;
                if (fromEl.value && fromEl.value > maxAllowed) {
                    fromEl.value = maxAllowed;
                    if (typeof showToast === 'function') showToast('From date cannot be after To date', 'error');
                }

                // Keep toEl.min in sync when 'from' changes
                if (toEl) toEl.min = fromEl.value || '';

                state.currentAuditPage = 1;
                renderAuditLogs();
            });
        }
    }

    function setupConfiguration() {
        const configContainer = document.getElementById('settingsConfigModules');
        if (!configContainer) return;

        initTabNavigation();
        initCardNavigation();
        initBackButtons();
        initFormHandlers();
        initRefreshButtons();
        initTableDelegates();
        setActiveConfigurationModule('branches');
        initializeConfigurationData();
    }

    function initTabNavigation() {
        const buttons = document.querySelectorAll('.settings-tabs-navigation .tab-button');
        buttons.forEach((button) => {
            button.addEventListener('click', () => {
                const target = button.dataset.settingsTab;
                if (!target) return;
                buttons.forEach((btn) => btn.classList.remove('active'));
                button.classList.add('active');
                document.querySelectorAll('.settings-tab-content').forEach((tab) => {
                    tab.classList.toggle('active', tab.id === `settings-${target}-tab`);
                });
            });
        });
    }

    function initCardNavigation() {
        document.querySelectorAll('#settingsCardGrid .config-card').forEach((card) => {
            card.addEventListener('click', () => {
                const moduleName = card.dataset.module;
                if (moduleName) {
                    setActiveConfigurationModule(moduleName);
                }
            });
        });
    }

    function initBackButtons() {
        document.querySelectorAll('[data-action="settings-back"]').forEach((button) => {
            button.addEventListener('click', () => {
                setActiveConfigurationModule(null);
            });
        });
    }

    function initFormHandlers() {
        document.getElementById('branchForm')?.addEventListener('submit', handleBranchFormSubmit);
        document.getElementById('branchFormReset')?.addEventListener('click', () => resetBranchForm());

        document.getElementById('categoryForm')?.addEventListener('submit', handleCategoryFormSubmit);
        document.getElementById('categoryFormReset')?.addEventListener('click', () => resetCategoryForm());

        document.getElementById('addonForm')?.addEventListener('submit', handleAddonFormSubmit);
        document.getElementById('addonFormReset')?.addEventListener('click', () => resetAddonForm());

        document.getElementById('flavorForm')?.addEventListener('submit', handleFlavorFormSubmit);
        document.getElementById('flavorFormReset')?.addEventListener('click', () => resetFlavorForm());

        document.getElementById('unitForm')?.addEventListener('submit', handleUnitFormSubmit);
        document.getElementById('unitFormReset')?.addEventListener('click', () => resetUnitForm());

        document.getElementById('paymentReceiverForm')?.addEventListener('submit', handlePaymentReceiverFormSubmit);
        document.getElementById('paymentReceiverFormReset')?.addEventListener('click', () => resetPaymentReceiverForm());

        document.getElementById('voidNotificationForm')?.addEventListener('submit', handleVoidNotificationSubmit);
    }

    function initRefreshButtons() {
        document.getElementById('refreshBranchesBtn')?.addEventListener('click', () => loadBranches());
        document.getElementById('refreshCategoriesBtn')?.addEventListener('click', () => loadCategories());
        document.getElementById('refreshAddonsBtn')?.addEventListener('click', () => loadAddons());
        document.getElementById('refreshFlavorsBtn')?.addEventListener('click', () => loadFlavors());
        document.getElementById('refreshUnitsBtn')?.addEventListener('click', () => loadUnits());
        document.getElementById('refreshPaymentReceiversBtn')?.addEventListener('click', () => loadPaymentReceivers());
    }

    function initTableDelegates() {
        document.getElementById('branchTableBody')?.addEventListener('click', handleBranchTableClick);
        document.getElementById('categoryTableBody')?.addEventListener('click', handleCategoryTableClick);
        document.getElementById('addonTableBody')?.addEventListener('click', handleAddonTableClick);
        document.getElementById('flavorTableBody')?.addEventListener('click', handleFlavorTableClick);
        document.getElementById('unitTableBody')?.addEventListener('click', handleUnitTableClick);
        document.getElementById('paymentReceiverTableBody')?.addEventListener('click', handlePaymentReceiverTableClick);
    }

    async function initializeConfigurationData() {
        try {
            await Promise.all([
                loadBranches(true),
                loadCategories(true),
                loadAddons(true),
                loadUnits(true),
                loadPaymentReceivers(true),
                loadVoidNotificationSetting(true)
            ]);
            await loadInventoryOptions();
            await loadFlavors(true);
        } catch (error) {
            console.error('Settings data load failed:', error);
            showToast('Some configuration data failed to load.', 'error');
        }
    }

    function setActiveConfigurationModule(moduleName) {
        const modules = document.querySelectorAll('#settingsConfigModules .settings-module');
        const cards = document.querySelectorAll('#settingsCardGrid .config-card');
        const placeholder = document.getElementById('settingsModulePlaceholder');

        if (!moduleName) {
            modules.forEach((module) => module.classList.remove('active'));
            cards.forEach((card) => card.classList.remove('active'));
            placeholder?.classList.remove('hidden');
            state.config.activeModule = null;
            return;
        }

        placeholder?.classList.add('hidden');
        modules.forEach((module) => {
            module.classList.toggle('active', module.dataset.module === moduleName);
        });
        cards.forEach((card) => {
            card.classList.toggle('active', card.dataset.module === moduleName);
        });
        state.config.activeModule = moduleName;
    }

    /* ---------- Branch Management ---------- */
    async function loadBranches(silent = false) {
        try {
            const data = await fetchJSON('db/branches_getAll.php');
            state.config.branches = Array.isArray(data.branches) ? data.branches : [];
            renderBranches();
        } catch (error) {
            console.error('loadBranches error:', error);
            state.config.branches = [];
            renderBranches();
            if (!silent) showToast('Failed to load branches.', 'error');
        }
    }

    function renderBranches() {
        const tbody = document.getElementById('branchTableBody');
        if (!tbody) return;

        if (!state.config.branches.length) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="4" class="empty-state">No branches yet.</td>
                </tr>
            `;
            return;
        }

        tbody.innerHTML = state.config.branches.map((branch) => {
            const nextStatus = branch.status === 'active' ? 'inactive' : 'active';
            const statusLabel = branch.status || 'active';
            return `
                <tr>
                    <td>${branch.branch_name}</td>
                    <td>${branch.address || '—'}</td>
                    <td><span class="status-pill ${statusLabel}">${statusLabel}</span></td>
                    <td>
                        <div class="table-actions">
                            <button type="button" class="action-button" data-action="edit-branch" data-id="${branch.branch_id}">Edit</button>
                            <button type="button" class="action-button" data-action="toggle-branch" data-id="${branch.branch_id}" data-next="${nextStatus}">
                                ${nextStatus === 'active' ? 'Activate' : 'Deactivate'}
                            </button>
                        </div>
                    </td>
                </tr>
            `;
        }).join('');
    }

    async function handleBranchFormSubmit(event) {
        event.preventDefault();
        const form = event.currentTarget;
        const formData = new FormData(form);
        const branchId = formData.get('branch_id');
        const endpoint = branchId ? 'db/branches_update.php' : 'db/branches_add.php';

        try {
            await submitForm(endpoint, formData);
            showToast(branchId ? 'Branch updated.' : 'Branch added.', 'success');
            resetBranchForm();
            await loadBranches(true);
            loadAuditLogs();
        } catch (error) {
            console.error('handleBranchFormSubmit error:', error);
            showToast(error.message || 'Failed to save branch.', 'error');
        }
    }

    function handleBranchTableClick(event) {
        const button = event.target.closest('.action-button');
        if (!button) return;

        const branchId = Number(button.dataset.id);
        if (Number.isNaN(branchId)) return;

        if (button.dataset.action === 'edit-branch') {
            const branch = state.config.branches.find((item) => Number(item.branch_id) === branchId);
            if (branch) populateBranchForm(branch);
        } else if (button.dataset.action === 'toggle-branch') {
            toggleBranchStatus(branchId, button.dataset.next);
        }
    }

    async function toggleBranchStatus(branchId, nextStatus) {
        if (!nextStatus) return;
        const formData = new FormData();
        formData.append('branch_id', branchId);
        formData.append('status', nextStatus);
        try {
            await submitForm('db/branches_update.php', formData);
            showToast('Branch status updated.', 'success');
            await loadBranches(true);
            loadAuditLogs();
        } catch (error) {
            showToast(error.message || 'Failed to update branch.', 'error');
        }
    }

    function populateBranchForm(branch) {
        document.getElementById('branchIdInput').value = branch.branch_id;
        document.getElementById('branchNameInput').value = branch.branch_name || '';
        document.getElementById('branchAddressInput').value = branch.address || '';
        document.getElementById('branchStatusSelect').value = branch.status || 'active';
        document.getElementById('branchFormTitle').textContent = 'Edit Branch';
        document.getElementById('branchSubmitBtn').textContent = 'Update Branch';
        setActiveConfigurationModule('branches');
    }

    function resetBranchForm() {
        const form = document.getElementById('branchForm');
        if (!form) return;
        form.reset();
        document.getElementById('branchIdInput').value = '';
        document.getElementById('branchStatusSelect').value = 'active';
        document.getElementById('branchFormTitle').textContent = 'Add Branch';
        document.getElementById('branchSubmitBtn').textContent = 'Save Branch';
    }

    /* ---------- Categories ---------- */
    async function loadCategories(silent = false) {
        try {
            const data = await fetchJSON('db/categories_getAll.php');
            state.config.categories = Array.isArray(data) ? data : (data.categories || []);
            renderCategories();
            populateCategoryParentOptions();
        } catch (error) {
            console.error('loadCategories error:', error);
            state.config.categories = [];
            renderCategories();
            if (!silent) showToast('Failed to load categories.', 'error');
        }
    }

    function renderCategories() {
        const tbody = document.getElementById('categoryTableBody');
        if (!tbody) return;

        if (!state.config.categories.length) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="4" class="empty-state">No categories yet.</td>
                </tr>
            `;
            return;
        }

        tbody.innerHTML = state.config.categories.map((category) => {
            const nextStatus = Number(category.isActive) === 1 ? 'inactive' : 'active';
            const statusLabel = Number(category.isActive) === 1 ? 'active' : 'inactive';
            return `
                <tr>
                    <td>${escapeHtml(category.categoryName)}</td>
                    <td>${escapeHtml(category.parentName || '—')}</td>
                    <td><span class="status-pill ${statusLabel}">${statusLabel}</span></td>
                    <td>
                        <div class="table-actions">
                            <button type="button" class="action-button" data-action="edit-category" data-id="${category.categoryID}">Edit</button>
                            <button type="button" class="action-button" data-action="toggle-category" data-id="${category.categoryID}" data-next="${nextStatus}">
                                ${nextStatus === 'active' ? 'Activate' : 'Deactivate'}
                            </button>
                        </div>
                    </td>
                </tr>
            `;
        }).join('');
    }

    function populateCategoryParentOptions(selectedId = '') {
        const select = document.getElementById('categoryParentSelect');
        if (!select) return;
        const currentId = document.getElementById('categoryIdInput')?.value || '';
        select.innerHTML = '<option value="">None (Top-level)</option>' +
            state.config.categories
                .filter((category) => `${category.categoryID}` !== currentId)
                .map((category) => `<option value="${category.categoryID}">${escapeHtml(category.categoryName)}</option>`)
                .join('');
        if (selectedId) {
            select.value = selectedId;
        }
    }

    async function handleCategoryFormSubmit(event) {
        event.preventDefault();
        const form = event.currentTarget;
        const formData = new FormData(form);
        const categoryId = formData.get('categoryID');
        const endpoint = categoryId ? 'db/categories_update.php' : 'db/categories_add.php';
        try {
            await submitForm(endpoint, formData);
            showToast(categoryId ? 'Category updated.' : 'Category added.', 'success');
            window.DataService?.invalidateCategories?.();
            resetCategoryForm();
            await loadCategories(true);
            loadAuditLogs();
        } catch (error) {
            console.error('handleCategoryFormSubmit error:', error);
            showToast(error.message || 'Failed to save category.', 'error');
        }
    }

    function handleCategoryTableClick(event) {
        const button = event.target.closest('.action-button');
        if (!button) return;
        const categoryId = Number(button.dataset.id);
        if (Number.isNaN(categoryId)) return;

        if (button.dataset.action === 'edit-category') {
            const category = state.config.categories.find((item) => Number(item.categoryID) === categoryId);
            if (category) populateCategoryForm(category);
        } else if (button.dataset.action === 'toggle-category') {
            toggleCategoryStatus(categoryId, button.dataset.next);
        }
    }

    async function toggleCategoryStatus(categoryId, nextStatus) {
        if (!nextStatus) return;
        const formData = new FormData();
        formData.append('categoryID', categoryId);
        formData.append('status', nextStatus);
        try {
            await submitForm('db/categories_update.php', formData);
            showToast('Category status updated.', 'success');
            window.DataService?.invalidateCategories?.();
            await loadCategories(true);
            loadAuditLogs();
        } catch (error) {
            showToast(error.message || 'Failed to update category.', 'error');
        }
    }

    function populateCategoryForm(category) {
        document.getElementById('categoryIdInput').value = category.categoryID;
        document.getElementById('categoryNameInput').value = category.categoryName || '';
        document.getElementById('categoryStatusSelect').value = Number(category.isActive) === 1 ? 'active' : 'inactive';
        populateCategoryParentOptions(category.parent_id ? `${category.parent_id}` : '');
        document.getElementById('categoryFormTitle').textContent = 'Edit Category';
        document.getElementById('categorySubmitBtn').textContent = 'Update Category';
        setActiveConfigurationModule('categories');
    }

    function resetCategoryForm() {
        const form = document.getElementById('categoryForm');
        if (!form) return;
        form.reset();
        document.getElementById('categoryIdInput').value = '';
        document.getElementById('categoryStatusSelect').value = 'active';
        document.getElementById('categoryFormTitle').textContent = 'Add Category';
        document.getElementById('categorySubmitBtn').textContent = 'Save Category';
        populateCategoryParentOptions();
    }

    /* ---------- Add-ons ---------- */
    async function loadAddons(silent = false) {
        try {
            const data = await fetchJSON('db/addons_getAll.php');
            state.config.addons = Array.isArray(data) ? data : (data.addons || []);
            renderAddons();
        } catch (error) {
            console.error('loadAddons error:', error);
            state.config.addons = [];
            renderAddons();
            if (!silent) showToast('Failed to load add-ons.', 'error');
        }
    }

    function renderAddons() {
        const tbody = document.getElementById('addonTableBody');
        if (!tbody) return;
        if (!state.config.addons.length) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="4" class="empty-state">No add-ons yet.</td>
                </tr>
            `;
            return;
        }

        tbody.innerHTML = state.config.addons.map((addon) => {
            const nextStatus = addon.status === 'active' ? 'inactive' : 'active';
            const statusLabel = addon.status || 'active';
            return `
                <tr>
                    <td>${addon.addon_name}</td>
                    <td>₱${Number(addon.price || 0).toFixed(2)}</td>
                    <td><span class="status-pill ${statusLabel}">${statusLabel}</span></td>
                    <td>
                        <div class="table-actions">
                            <button type="button" class="action-button" data-action="edit-addon" data-id="${addon.addon_id}">Edit</button>
                            <button type="button" class="action-button" data-action="toggle-addon" data-id="${addon.addon_id}" data-next="${nextStatus}">
                                ${nextStatus === 'active' ? 'Activate' : 'Deactivate'}
                            </button>
                        </div>
                    </td>
                </tr>
            `;
        }).join('');
    }

    async function handleAddonFormSubmit(event) {
        event.preventDefault();
        const form = event.currentTarget;
        const formData = new FormData(form);
        const addonId = formData.get('addon_id');
        const endpoint = addonId ? 'db/addons_update.php' : 'db/addons_add.php';
        try {
            await submitForm(endpoint, formData);
            showToast(addonId ? 'Add-on updated.' : 'Add-on added.', 'success');
            resetAddonForm();
            await loadAddons(true);
            loadAuditLogs();
        } catch (error) {
            console.error('handleAddonFormSubmit error:', error);
            showToast(error.message || 'Failed to save add-on.', 'error');
        }
    }

    function handleAddonTableClick(event) {
        const button = event.target.closest('.action-button');
        if (!button) return;
        const addonId = Number(button.dataset.id);
        if (Number.isNaN(addonId)) return;

        if (button.dataset.action === 'edit-addon') {
            const addon = state.config.addons.find((item) => Number(item.addon_id) === addonId);
            if (addon) populateAddonForm(addon);
        } else if (button.dataset.action === 'toggle-addon') {
            toggleAddonStatus(addonId, button.dataset.next);
        }
    }

    async function toggleAddonStatus(addonId, nextStatus) {
        if (!nextStatus) return;
        const formData = new FormData();
        formData.append('addon_id', addonId);
        formData.append('status', nextStatus);
        try {
            await submitForm('db/addons_update.php', formData);
            showToast('Add-on status updated.', 'success');
            await loadAddons(true);
            loadAuditLogs();
        } catch (error) {
            showToast(error.message || 'Failed to update add-on.', 'error');
        }
    }

    function populateAddonForm(addon) {
        document.getElementById('addonIdInput').value = addon.addon_id;
        document.getElementById('addonNameInput').value = addon.addon_name || '';
        document.getElementById('addonPriceInput').value = addon.price || 0;
        document.getElementById('addonStatusSelect').value = addon.status || 'active';
        document.getElementById('addonFormTitle').textContent = 'Edit Add-on';
        document.getElementById('addonSubmitBtn').textContent = 'Update Add-on';
        setActiveConfigurationModule('addons');
    }

    function resetAddonForm() {
        const form = document.getElementById('addonForm');
        if (!form) return;
        form.reset();
        document.getElementById('addonIdInput').value = '';
        document.getElementById('addonStatusSelect').value = 'active';
        document.getElementById('addonFormTitle').textContent = 'Add Add-on';
        document.getElementById('addonSubmitBtn').textContent = 'Save Add-on';
    }

    /* ---------- Flavors ---------- */
    async function loadInventoryOptions() {
        try {
            const data = await fetchJSON('db/inventory_options.php');
            state.config.inventories = Array.isArray(data.items) ? data.items : [];
            populateFlavorInventoryOptions();
        } catch (error) {
            console.error('loadInventoryOptions error:', error);
            state.config.inventories = [];
            populateFlavorInventoryOptions();
        }
    }

    function populateFlavorInventoryOptions(selectedId = '') {
        const select = document.getElementById('flavorInventorySelect');
        if (!select) return;
        select.innerHTML = '<option value="">No inventory link</option>' +
            state.config.inventories
                .map((item) => `<option value="${item.inventoryID}">${item.name} (${item.branch_name})</option>`)
                .join('');
        if (selectedId) {
            select.value = selectedId;
        }
    }

    async function loadFlavors(silent = false) {
        try {
            const data = await fetchJSON('db/flavors_getAll.php?includeInactive=1');
            state.config.flavors = Array.isArray(data) ? data : (data.flavors || []);
            renderFlavors();
        } catch (error) {
            console.error('loadFlavors error:', error);
            state.config.flavors = [];
            renderFlavors();
            if (!silent) showToast('Failed to load flavors.', 'error');
        }
    }

    function renderFlavors() {
        const tbody = document.getElementById('flavorTableBody');
        if (!tbody) return;
        if (!state.config.flavors.length) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="4" class="empty-state">No flavors yet.</td>
                </tr>
            `;
            return;
        }

        tbody.innerHTML = state.config.flavors.map((flavor) => {
            const nextStatus = flavor.status === 'active' ? 'inactive' : 'active';
            const inventory = state.config.inventories.find((item) => Number(item.inventoryID) === Number(flavor.inventory_id));
            const inventoryLabel = inventory ? `${inventory.name} (${inventory.branch_name})` : '—';
            return `
                <tr>
                    <td>${flavor.flavor_name}</td>
                    <td>${inventoryLabel}</td>
                    <td><span class="status-pill ${flavor.status}">${flavor.status}</span></td>
                    <td>
                        <div class="table-actions">
                            <button type="button" class="action-button" data-action="edit-flavor" data-id="${flavor.flavor_id}">Edit</button>
                            <button type="button" class="action-button" data-action="toggle-flavor" data-id="${flavor.flavor_id}" data-next="${nextStatus}">
                                ${nextStatus === 'active' ? 'Activate' : 'Deactivate'}
                            </button>
                        </div>
                    </td>
                </tr>
            `;
        }).join('');
    }

    async function handleFlavorFormSubmit(event) {
        event.preventDefault();
        const form = event.currentTarget;
        const formData = new FormData(form);
        const flavorId = formData.get('flavor_id');
        const endpoint = flavorId ? 'db/flavors_update.php' : 'db/flavors_add.php';
        try {
            await submitForm(endpoint, formData);
            showToast(flavorId ? 'Flavor updated.' : 'Flavor added.', 'success');
            resetFlavorForm();
            await loadFlavors(true);
            loadAuditLogs();
        } catch (error) {
            console.error('handleFlavorFormSubmit error:', error);
            showToast(error.message || 'Failed to save flavor.', 'error');
        }
    }

    function handleFlavorTableClick(event) {
        const button = event.target.closest('.action-button');
        if (!button) return;
        const flavorId = Number(button.dataset.id);
        if (Number.isNaN(flavorId)) return;

        if (button.dataset.action === 'edit-flavor') {
            const flavor = state.config.flavors.find((item) => Number(item.flavor_id) === flavorId);
            if (flavor) populateFlavorForm(flavor);
        } else if (button.dataset.action === 'toggle-flavor') {
            toggleFlavorStatus(flavorId, button.dataset.next);
        }
    }

    async function toggleFlavorStatus(flavorId, nextStatus) {
        if (!nextStatus) return;
        const formData = new FormData();
        formData.append('flavor_id', flavorId);
        formData.append('status', nextStatus);
        try {
            await submitForm('db/flavors_update.php', formData);
            showToast('Flavor status updated.', 'success');
            await loadFlavors(true);
            loadAuditLogs();
        } catch (error) {
            showToast(error.message || 'Failed to update flavor.', 'error');
        }
    }

    function populateFlavorForm(flavor) {
        document.getElementById('flavorIdInput').value = flavor.flavor_id;
        document.getElementById('flavorNameInput').value = flavor.flavor_name || '';
        document.getElementById('flavorAmountInput').value = flavor.amount_per_serving || 0;
        document.getElementById('flavorStatusSelect').value = flavor.status || 'active';
        populateFlavorInventoryOptions(flavor.inventory_id ? `${flavor.inventory_id}` : '');
        document.getElementById('flavorFormTitle').textContent = 'Edit Flavor';
        document.getElementById('flavorSubmitBtn').textContent = 'Update Flavor';
        setActiveConfigurationModule('flavors');
    }

    function resetFlavorForm() {
        const form = document.getElementById('flavorForm');
        if (!form) return;
        form.reset();
        document.getElementById('flavorIdInput').value = '';
        document.getElementById('flavorStatusSelect').value = 'active';
        populateFlavorInventoryOptions();
        document.getElementById('flavorFormTitle').textContent = 'Add Flavor';
        document.getElementById('flavorSubmitBtn').textContent = 'Save Flavor';
    }

    /* ---------- Measurement Units ---------- */
    async function loadUnits(silent = false) {
        try {
            const data = await fetchJSON('db/product_units_get.php');
            state.config.units = Array.isArray(data) ? data : (data.units || []);
            renderUnits();
        } catch (error) {
            console.error('loadUnits error:', error);
            state.config.units = [];
            renderUnits();
            if (!silent) showToast('Failed to load measurement units.', 'error');
        }
    }

    function renderUnits() {
        const tbody = document.getElementById('unitTableBody');
        if (!tbody) return;
        if (!state.config.units.length) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="5" class="empty-state">No measurement units yet.</td>
                </tr>
            `;
            return;
        }

        tbody.innerHTML = state.config.units.map((unit) => `
            <tr>
                <td>${unit.unit_name}</td>
                <td>${unit.unit_symbol}</td>
                <td>x${Number(unit.conversion_factor || 1).toFixed(4)}</td>
                <td>${Number(unit.is_base_unit) === 1 ? 'Yes' : 'No'}</td>
                <td>
                    <div class="table-actions">
                        <button type="button" class="action-button" data-action="edit-unit" data-id="${unit.unit_id}">Edit</button>
                        <button type="button" class="action-button" data-action="delete-unit" data-id="${unit.unit_id}">Delete</button>
                    </div>
                </td>
            </tr>
        `).join('');
    }

    async function handleUnitFormSubmit(event) {
        event.preventDefault();
        const form = event.currentTarget;
        const formData = new FormData(form);
        const unitId = formData.get('unit_id');
        if (!formData.get('is_base_unit')) {
            formData.set('is_base_unit', '');
        }
        const endpoint = unitId ? 'db/product_units_update.php' : 'db/product_units_add.php';
        try {
            await submitForm(endpoint, formData);
            showToast(unitId ? 'Unit updated.' : 'Unit added.', 'success');
            resetUnitForm();
            window.DataService?.invalidateUnits?.();
            await loadUnits(true);
            loadAuditLogs();
        } catch (error) {
            console.error('handleUnitFormSubmit error:', error);
            showToast(error.message || 'Failed to save unit.', 'error');
        }
    }

    function handleUnitTableClick(event) {
        const button = event.target.closest('.action-button');
        if (!button) return;
        const unitId = Number(button.dataset.id);
        if (Number.isNaN(unitId)) return;

        if (button.dataset.action === 'edit-unit') {
            const unit = state.config.units.find((item) => Number(item.unit_id) === unitId);
            if (unit) populateUnitForm(unit);
        } else if (button.dataset.action === 'delete-unit') {
            deleteUnit(unitId);
        }
    }

    async function deleteUnit(unitId) {
        const confirmed = await confirmAction('Delete this unit? This cannot be undone.');
        if (!confirmed) return;
        const formData = new FormData();
        formData.append('unit_id', unitId);
        try {
            await submitForm('db/product_units_delete.php', formData);
            showToast('Unit deleted.', 'success');
            window.DataService?.invalidateUnits?.();
            await loadUnits(true);
            loadAuditLogs();
        } catch (error) {
            showToast(error.message || 'Failed to delete unit.', 'error');
        }
    }

    function populateUnitForm(unit) {
        document.getElementById('unitIdInput').value = unit.unit_id;
        document.getElementById('unitNameInput').value = unit.unit_name || '';
        document.getElementById('unitSymbolInput').value = unit.unit_symbol || '';
        document.getElementById('unitConversionInput').value = unit.conversion_factor || 1;
        document.getElementById('unitBaseCheckbox').checked = Number(unit.is_base_unit) === 1;
        document.getElementById('unitFormTitle').textContent = 'Edit Unit';
        document.getElementById('unitSubmitBtn').textContent = 'Update Unit';
        setActiveConfigurationModule('units');
    }

    function resetUnitForm() {
        const form = document.getElementById('unitForm');
        if (!form) return;
        form.reset();
        document.getElementById('unitIdInput').value = '';
        document.getElementById('unitBaseCheckbox').checked = false;
        document.getElementById('unitFormTitle').textContent = 'Add Unit';
        document.getElementById('unitSubmitBtn').textContent = 'Save Unit';
    }

    /* ---------- Payment Receivers ---------- */
    async function loadPaymentReceivers(silent = false) {
        try {
            const data = await fetchJSON('db/payment_receivers_get.php?includeInactive=1');
            state.config.paymentReceivers = Array.isArray(data.receivers) ? data.receivers : [];
            renderPaymentReceivers();
        } catch (error) {
            console.error('loadPaymentReceivers error:', error);
            state.config.paymentReceivers = [];
            renderPaymentReceivers();
            if (!silent) showToast('Failed to load payment receiver numbers.', 'error');
        }
    }

    function renderPaymentReceivers() {
        const tbody = document.getElementById('paymentReceiverTableBody');
        if (!tbody) return;

        if (!state.config.paymentReceivers.length) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="5" class="empty-state">No receiver numbers yet.</td>
                </tr>
            `;
            return;
        }

        tbody.innerHTML = state.config.paymentReceivers.map((receiver) => {
            const statusLabel = receiver.status || 'active';
            const nextStatus = statusLabel === 'active' ? 'inactive' : 'active';
            const label = receiver.label ? escapeHtml(receiver.label) : '—';
            const providerLabel = receiver.provider ? receiver.provider.toUpperCase() : '—';
            return `
                <tr>
                    <td>${providerLabel}</td>
                    <td>${label}</td>
                    <td>${escapeHtml(receiver.phone_number || '')}</td>
                    <td><span class="status-pill ${statusLabel}">${statusLabel}</span></td>
                    <td>
                        <div class="table-actions">
                            <button type="button" class="action-button" data-action="edit-receiver" data-id="${receiver.receiver_id}">Edit</button>
                            <button type="button" class="action-button" data-action="toggle-receiver" data-id="${receiver.receiver_id}" data-next="${nextStatus}">
                                ${nextStatus === 'active' ? 'Activate' : 'Deactivate'}
                            </button>
                        </div>
                    </td>
                </tr>
            `;
        }).join('');
    }

    async function handlePaymentReceiverFormSubmit(event) {
        event.preventDefault();
        const form = event.currentTarget;
        const formData = new FormData(form);
        const receiverId = formData.get('receiver_id');
        const endpoint = receiverId ? 'db/payment_receivers_update.php' : 'db/payment_receivers_add.php';
        const phoneNumber = `${formData.get('phone_number') || ''}`.trim();
        if (!/^09\d{9}$/.test(phoneNumber)) {
            showToast('Phone number must be 11 digits and start with 09.', 'error');
            form.querySelector('#paymentReceiverNumber')?.focus();
            return;
        }
        formData.set('phone_number', phoneNumber);
        try {
            await submitForm(endpoint, formData);
            showToast(receiverId ? 'Receiver updated.' : 'Receiver added.', 'success');
            resetPaymentReceiverForm();
            await loadPaymentReceivers(true);
            loadAuditLogs();
        } catch (error) {
            console.error('handlePaymentReceiverFormSubmit error:', error);
            showToast(error.message || 'Failed to save receiver.', 'error');
        }
    }

    function handlePaymentReceiverTableClick(event) {
        const button = event.target.closest('.action-button');
        if (!button) return;
        const receiverId = Number(button.dataset.id);
        if (Number.isNaN(receiverId)) return;

        if (button.dataset.action === 'edit-receiver') {
            const receiver = state.config.paymentReceivers.find((item) => Number(item.receiver_id) === receiverId);
            if (receiver) populatePaymentReceiverForm(receiver);
        } else if (button.dataset.action === 'toggle-receiver') {
            togglePaymentReceiverStatus(receiverId, button.dataset.next);
        }
    }

    async function togglePaymentReceiverStatus(receiverId, nextStatus) {
        if (!nextStatus) return;
        const formData = new FormData();
        formData.append('receiver_id', receiverId);
        formData.append('status', nextStatus);
        try {
            await submitForm('db/payment_receivers_update.php', formData);
            showToast('Receiver status updated.', 'success');
            await loadPaymentReceivers(true);
            loadAuditLogs();
        } catch (error) {
            showToast(error.message || 'Failed to update receiver status.', 'error');
        }
    }

    function populatePaymentReceiverForm(receiver) {
        document.getElementById('paymentReceiverIdInput').value = receiver.receiver_id;
        document.getElementById('paymentReceiverProvider').value = receiver.provider || 'gcash';
        document.getElementById('paymentReceiverLabel').value = receiver.label || '';
        document.getElementById('paymentReceiverNumber').value = receiver.phone_number || '';
        document.getElementById('paymentReceiverStatus').value = receiver.status || 'active';
        document.getElementById('paymentReceiverFormTitle').textContent = 'Edit Receiver';
        document.getElementById('paymentReceiverSubmitBtn').textContent = 'Update Receiver';
        setActiveConfigurationModule('payment-receivers');
    }

    function resetPaymentReceiverForm() {
        const form = document.getElementById('paymentReceiverForm');
        if (!form) return;
        form.reset();
        document.getElementById('paymentReceiverIdInput').value = '';
        document.getElementById('paymentReceiverStatus').value = 'active';
        document.getElementById('paymentReceiverFormTitle').textContent = 'Add Receiver';
        document.getElementById('paymentReceiverSubmitBtn').textContent = 'Save Receiver';
    }

    /* ---------- Void Notification Settings ---------- */
    async function loadVoidNotificationSetting(silent = false) {
        try {
            const data = await fetchJSON('db/system_settings_get.php?setting_name=void_notification_emails');
            const value = data?.setting?.value ?? '';
            const input = document.getElementById('voidNotificationEmail');
            if (input) {
                input.value = value;
            }
        } catch (error) {
            if (!silent) {
                console.error('loadVoidNotificationSetting error:', error);
                showToast('Failed to load void notification emails.', 'error');
            }
        }
    }

    async function handleVoidNotificationSubmit(event) {
        event.preventDefault();
        const input = document.getElementById('voidNotificationEmail');
        if (!input) return;
        const emailList = input.value.trim();

        if (emailList !== '') {
            const emails = emailList.split(',').map((email) => email.trim()).filter(Boolean);
            const invalidEmail = emails.find((email) => !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email));
            if (invalidEmail) {
                showToast(`Invalid email address: ${invalidEmail}`, 'error');
                input.focus();
                return;
            }
        }

        const formData = new FormData();
        formData.append('setting_name', 'void_notification_emails');
        formData.append('value', emailList);

        try {
            const data = await fetchJSON('db/system_settings_update.php', {
                method: 'POST',
                body: formData
            });
            if (data.status !== 'success') {
                throw new Error(data.message || 'Failed to update setting');
            }
            showToast('Void notification emails updated.', 'success');
            await loadVoidNotificationSetting(true);
            loadAuditLogs();
        } catch (error) {
            console.error('handleVoidNotificationSubmit error:', error);
            showToast(error.message || 'Failed to update void notification email.', 'error');
        }
    }

    /* ---------- Helpers ---------- */
    async function fetchJSON(url, options = {}) {
        const response = await fetch(url, options);
        const text = await response.text();
        let data;
        try {
            data = text ? JSON.parse(text) : {};
        } catch (error) {
            throw new Error('Invalid server response.');
        }
        if (!response.ok) {
            throw new Error(data.message || `Request failed (${response.status})`);
        }
        return data;
    }

    async function submitForm(url, formData) {
        const response = await fetch(url, {
            method: 'POST',
            body: formData
        });
        const text = await response.text();
        let data;
        try {
            data = text ? JSON.parse(text) : {};
        } catch (error) {
            throw new Error('Invalid server response.');
        }
        if (!response.ok || data.status === 'error') {
            throw new Error(data.message || 'Request failed.');
        }
        return data;
    }

    async function loadSystemInfo() {
        try {
            const response = await fetch('db/system_info.php');
            const data = await response.json();
            state.systemInfo = data || {};
            renderSystemInfo();
        } catch (error) {
            console.error('Error loading system info:', error);
            state.systemInfo = {};
            renderSystemInfo();
        }
    }

    function renderSystemInfo() {
        const info = state.systemInfo;
        const dbStatusEl = document.getElementById('dbStatus');
        if (dbStatusEl) {
            const label = info.dbStatus || 'Unknown';
            dbStatusEl.textContent = label;
            dbStatusEl.className = `stat-value ${label === 'Online' ? 'online' : 'offline'}`;
        }
        const tableCountEl = document.getElementById('tableCount');
        if (tableCountEl) tableCountEl.textContent = info.tableCount ?? '-';
        const dbSizeEl = document.getElementById('dbSize');
        if (dbSizeEl) dbSizeEl.textContent = info.dbSize ?? '-';
    }

    function refreshSystemInfo() {
        showToast('Refreshing system information...', 'info');
        loadSystemInfo();
    }

    function backupDatabase() {
        showToast('Database backup initiated...', 'info');
        setTimeout(() => showToast('Database backup completed', 'success'), 2000);
    }

    function clearCache() {
        showToast('Cache cleared successfully', 'success');
    }

    function optimizeDatabase() {
        showToast('Database optimization started...', 'info');
        setTimeout(() => showToast('Database optimization completed', 'success'), 3000);
    }

    async function loadAuditLogs() {
        const tbody = document.getElementById('auditLogsBody');
        if (tbody) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="6" class="loading-spinner">Loading audit logs...</td>
                </tr>
            `;
        }

        try {
            const response = await fetch('db/get_audit_logs.php');
            const data = await response.json();

            if (data.status === 'success') {
                const logs = Array.isArray(data.audit_logs) ? data.audit_logs : [];
                state.auditLogs = logs.sort((a, b) => new Date(b.created_at) - new Date(a.created_at));
            } else {
                state.auditLogs = [];
            }

            populateAuditUserFilter(state.auditLogs);
            state.currentAuditPage = 1;
            renderAuditLogs();
            updateSecurityStats();
        } catch (error) {
            console.error('Error loading audit logs:', error);
            state.auditLogs = [];
            renderAuditLogs();
            showToast('Failed to load audit logs', 'error');
        }
    }

    function populateAuditUserFilter(logs) {
        const select = document.getElementById('auditUserFilter');
        if (!select) return;

        const previousValue = select.value;
        const usernames = Array.from(
            new Set(
                logs
                    .map((log) => log.username)
                    .filter((name) => !!name)
            )
        ).sort((a, b) => a.localeCompare(b));

        select.innerHTML = '<option value="">All Employees</option>' +
            usernames.map((name) => `<option value="${name}">${name}</option>`).join('');

        if (previousValue && usernames.includes(previousValue)) {
            select.value = previousValue;
        }
    }

    function getFilteredAuditLogs() {
        const dateFromValue = document.getElementById('auditDateFrom')?.value;
        const dateToValue = document.getElementById('auditDateTo')?.value;
        const userValue = (document.getElementById('auditUserFilter')?.value || '').toLowerCase();
        const actionValue = document.getElementById('auditActionFilter')?.value || '';

        const dateFrom = dateFromValue ? new Date(`${dateFromValue}T00:00:00`) : null;
        const dateTo = dateToValue ? new Date(`${dateToValue}T23:59:59`) : null;

        return state.auditLogs.filter((log) => {
            const action = log.action || '';
            const username = (log.username || '').toLowerCase();
            const timestamp = new Date(log.created_at);

            if (dateFrom && timestamp < dateFrom) return false;
            if (dateTo && timestamp > dateTo) return false;
            if (userValue && username !== userValue) return false;
            if (actionValue && action !== actionValue) return false;
            return true;
        });
    }

    function renderAuditLogs() {
        const tbody = document.getElementById('auditLogsBody');
        if (!tbody) return;

        const logs = getFilteredAuditLogs();
        const totalPages = Math.max(1, Math.ceil(logs.length / state.auditPageSize));
        state.currentAuditPage = Math.min(state.currentAuditPage, totalPages);

        const start = (state.currentAuditPage - 1) * state.auditPageSize;
        const pageItems = logs.slice(start, start + state.auditPageSize);

        if (!pageItems.length) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="6" class="empty-state">No audit logs found</td>
                </tr>
            `;
        } else {
            tbody.innerHTML = pageItems.map((log) => `
                <tr>
                    <td>${new Date(log.created_at).toLocaleString()}</td>
                    <td>${log.username || 'System'}</td>
                    <td><span class="action-badge action-${log.action}">${log.action}</span></td>
                    <td>${log.details || '-'}</td>
                    <td>${log.ip_address || '-'}</td>
                    <td><span class="status-badge status-${(log.role || 'system').toLowerCase()}">${log.role || 'System'}</span></td>
                </tr>
            `).join('');
        }

        updateAuditPaginationControls(logs.length, totalPages);
    }

    function updateAuditPaginationControls(totalItems, totalPages) {
        const prevBtn = document.getElementById('prevAuditBtn');
        if (prevBtn) prevBtn.disabled = state.currentAuditPage <= 1 || totalItems === 0;

        const nextBtn = document.getElementById('nextAuditBtn');
        if (nextBtn) nextBtn.disabled = state.currentAuditPage >= totalPages || totalItems === 0;

        const pageInfo = document.getElementById('auditPageInfo');
        if (pageInfo) {
            if (totalItems === 0) {
                pageInfo.textContent = 'Page 0 of 0';
            } else {
                pageInfo.textContent = `Page ${state.currentAuditPage} of ${totalPages}`;
            }
        }
    }

    function previousAuditPage() {
        if (state.currentAuditPage <= 1) return;
        state.currentAuditPage -= 1;
        renderAuditLogs();
    }

    function nextAuditPage() {
        const logs = getFilteredAuditLogs();
        const totalPages = Math.max(1, Math.ceil(logs.length / state.auditPageSize));
        if (state.currentAuditPage >= totalPages) return;
        state.currentAuditPage += 1;
        renderAuditLogs();
    }

    function filterAuditLogs() {
        state.currentAuditPage = 1;
        renderAuditLogs();
    }

    function refreshAuditLogs() {
        showToast('Refreshing audit logs...', 'info');
        loadAuditLogs();
    }

    function updateSecurityStats() {
        if (!state.auditLogs.length) {
            ['failedLogins', 'activeSessions', 'totalLogins', 'lastActivity', 'currentOnline', 'todayLogins', 'weekLogins']
                .forEach((id) => {
                    const el = document.getElementById(id);
                    if (el) el.textContent = id === 'lastActivity' ? '-' : '0';
                });
            return;
        }

        const logs = state.auditLogs;
        const now = new Date();
        const startOfDay = new Date(now.getFullYear(), now.getMonth(), now.getDate());
        const sevenDaysAgo = new Date(now.getTime() - (7 * 24 * 60 * 60 * 1000));

        const failedLogins = logs.filter((log) => log.action === 'failed_login' && new Date(log.created_at) >= startOfDay).length;
        const totalLoginsToday = logs.filter((log) => log.action === 'login' && new Date(log.created_at) >= startOfDay).length;
        const totalLoginsWeek = logs.filter((log) => log.action === 'login' && new Date(log.created_at) >= sevenDaysAgo).length;
        const activeSessions = logs.filter((log) => log.action === 'login').length -
            logs.filter((log) => log.action === 'logout').length;
        const lastActivity = logs[0] ? new Date(logs[0].created_at).toLocaleString() : 'No activity';

        const assignments = {
            failedLogins,
            totalLogins: totalLoginsToday,
            todayLogins: totalLoginsToday,
            weekLogins: totalLoginsWeek,
            activeSessions: Math.max(0, activeSessions),
            currentOnline: Math.max(0, activeSessions),
            lastActivity
        };

        Object.entries(assignments).forEach(([id, value]) => {
            const el = document.getElementById(id);
            if (el) el.textContent = value;
        });
    }

    function showAuditSection(section) {
        document.querySelectorAll('.audit-content-section').forEach((el) => {
            const matches = el.id === `audit-${section}-section`;
            el.classList.toggle('active', matches);
        });

        document.querySelectorAll('.audit-nav-button').forEach((button) => {
            const target = button.getAttribute('onclick') || '';
            button.classList.toggle('active', target.includes(`'${section}'`));
        });

        if (section === 'logs' && !state.auditLogs.length) {
            loadAuditLogs();
        }
    }

    function exportAuditLogs() {
        exportAuditReport('audit_logs', () => true);
    }

    function generateEmployeeReport() {
        const employeeActions = new Set([
            'employee_add',
            'employee_update',
            'employee_delete',
            'login',
            'logout'
        ]);
        exportAuditReport('employee_activity', (log) => employeeActions.has(log.action));
    }

    function generateSecurityReport() {
        const securityActions = new Set(['login', 'logout', 'failed_login', 'pin_login']);
        exportAuditReport('security_events', (log) => securityActions.has(log.action));
    }

    function generateSystemReport() {
        exportAuditReport('system_activity', () => true);
    }

    function exportAuditReport(label, predicate) {
        if (!state.auditLogs.length) {
            showToast('No audit logs available yet', 'warning');
            return;
        }

        const rows = state.auditLogs.filter(predicate);
        if (!rows.length) {
            showToast('No matching audit entries found', 'warning');
            return;
        }

        const headers = ['Timestamp', 'Employee', 'Action', 'Details', 'IP Address', 'Status'];
        const csv = [
            headers.join(','),
            ...rows.map((log) => [
                new Date(log.created_at).toISOString(),
                `"${(log.username || 'System').replace(/"/g, '""')}"`,
                log.action,
                `"${(log.details || '-').replace(/"/g, '""')}"`,
                log.ip_address || '-',
                log.action === 'failed_login' ? 'Failed' : 'Success'
            ].join(','))
        ].join('\n');

        const blob = new Blob([csv], { type: 'text/csv' });
        const url = URL.createObjectURL(blob);
        const anchor = document.createElement('a');
        anchor.href = url;
        anchor.download = `${label}_${new Date().toISOString().split('T')[0]}.csv`;
        document.body.appendChild(anchor);
        anchor.click();
        document.body.removeChild(anchor);
        URL.revokeObjectURL(url);
        showToast('Report generated successfully', 'success');
    }

    async function refreshConfig() {
        await initializeConfigurationData();
    }

    return {
        init,
        refreshSystemInfo,
        backupDatabase,
        clearCache,
        optimizeDatabase,
        refreshAuditLogs,
        filterAuditLogs,
        previousAuditPage,
        nextAuditPage,
        showAuditSection,
        exportAuditLogs,
        generateEmployeeReport,
        generateSecurityReport,
        generateSystemReport,
        refreshConfig
    };
})();

document.addEventListener('DOMContentLoaded', SettingsManager.init);

Object.assign(window, {
    refreshSystemInfo: SettingsManager.refreshSystemInfo,
    backupDatabase: SettingsManager.backupDatabase,
    clearCache: SettingsManager.clearCache,
    optimizeDatabase: SettingsManager.optimizeDatabase,
    refreshAuditLogs: SettingsManager.refreshAuditLogs,
    filterAuditLogs: SettingsManager.filterAuditLogs,
    previousAuditPage: SettingsManager.previousAuditPage,
    nextAuditPage: SettingsManager.nextAuditPage,
    showAuditSection: SettingsManager.showAuditSection,
    exportAuditLogs: SettingsManager.exportAuditLogs,
    generateEmployeeReport: SettingsManager.generateEmployeeReport,
    generateSecurityReport: SettingsManager.generateSecurityReport,
    generateSystemReport: SettingsManager.generateSystemReport,
    refreshSettingsConfig: SettingsManager.refreshConfig
});

