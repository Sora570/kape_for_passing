// Employees Management JavaScript

var employees = window.employees || [];
var selectedEmployeeIds = window.selectedEmployeeIds instanceof Set ? window.selectedEmployeeIds : new Set();
window.employees = employees;
window.selectedEmployeeIds = selectedEmployeeIds;

// Tab switching for employee modal
function switchEmployeeTab(tabName) {
    // Hide all tab contents
    document.querySelectorAll('.tab-content').forEach(tab => {
        tab.classList.remove('active');
    });

    // Remove active class from all tab buttons
    document.querySelectorAll('.tab-button').forEach(button => {
        button.classList.remove('active');
    });

    // Show selected tab content
    const targetTab = document.getElementById(tabName + 'InfoTab');
    if (targetTab) {
        targetTab.classList.add('active');
    }

    // Activate corresponding tab button
    const targetButton = document.querySelector(`[onclick="switchEmployeeTab('${tabName}')"]`);
    if (targetButton) {
        targetButton.classList.add('active');
    }
}

// Load employees list
async function loadEmployees() {
    try {
        const response = await fetch('db/employees_get.php');

        if (!response.ok) {
            const errorText = await response.text();
            throw new Error('HTTP ' + response.status + ': ' + errorText);
        }

        const data = await response.json();

        // Check for array of employees
        if (Array.isArray(data)) {
            employees = data;
        } else if (data.employees) {
            employees = data.employees;
        } else if (data.error) {
            throw new Error(data.error);
        } else {
            employees = [];
        }

        selectedEmployeeIds.clear();
        renderEmployeesTable();
        updateEmployeeBulkActions();
        renderOnlineIndicator();
    } catch (error) {
        console.error('Error loading employees:', error);
        showToast('Failed to load employees: ' + error.message, 'error');
        employees = [];
        selectedEmployeeIds.clear();
        renderEmployeesTable();
        updateEmployeeBulkActions();
        renderOnlineIndicator();
    }
}

// Toast notifications helper
function showToast(message, type = 'info') {
    const toast = document.getElementById('toast') || createToastElement();
    const toastMessage = toast.querySelector('#toast-message');

    if (toastMessage) {
        toastMessage.innerText = message;
        toast.className = 'toast show';

        if (type === 'error') {
            toast.classList.add('toast-error');
        } else if (type === 'success') {
            toast.classList.add('toast-success');
        }

        setTimeout(() => {
            toast.className = toast.className.replace('show', '');
        }, 4000);
    }
}

function createToastElement() {
    const toast = document.createElement('div');
    toast.id = 'toast';
    toast.className = 'toast';
    toast.innerHTML = '<span id="toast-message"></span>';
    document.body.appendChild(toast);
    return toast;
}

var escapeHtml = window.escapeHtml || function (value) {
    if (value === null || value === undefined) return '';
    return String(value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
};
window.escapeHtml = escapeHtml;

// Render employees table
function renderEmployeesTable() {
    const tbody = document.getElementById('employeesTableBody');
    if (!tbody) return;

    if (employees.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="7" style="text-align: center; color: #6b7280; padding: 2rem;">
                    <div style="display: flex; flex-direction: column; align-items: center; gap: 12px;">
                        <div style="font-size: 48px;">👥</div>
                        <div>No employees found</div>
                    </div>
                </td>
            </tr>
        `;
        const selectAll = document.getElementById('employeesSelectAll');
        if (selectAll) {
            selectAll.checked = false;
            selectAll.indeterminate = false;
        }
        updateEmployeeBulkActions();
        return;
    }

    tbody.innerHTML = employees.map(emp => {
        const userId = Number(emp.userID);
        const isSelected = selectedEmployeeIds.has(userId);
        const role = emp.role || '';
        const roleClass = role ? `role-${role}` : 'role-unknown';
        return `
        <tr class="employee-row ${isSelected ? 'selected' : ''}" data-user-id="${userId}">
            <td data-label="ID"><strong>${escapeHtml(userId)}</strong></td>
            <td data-label="Username">${escapeHtml(emp.username || '')}</td>
            <td data-label="Employee ID"><code>${escapeHtml(emp.employee_id || 'N/A')}</code></td>
            <td data-label="Role">
                <span class="role-badge ${roleClass}">
                    ${escapeHtml(role.toUpperCase())}
                </span>
            </td>
            <td data-label="Created">${escapeHtml(formatDate(emp.createdAt || emp.created_at))}</td>
            <td data-label="Last Login">${escapeHtml(formatDate(emp.lastLogin))}</td>
            <td data-label="Actions" style="text-align: center;">
                <div class="action-buttons">
                    <button class="action-btn action-btn-edit" onclick="editEmployee(${userId})" title="Edit">
                        <ion-icon name="create-outline"></ion-icon>
                    </button>
                    ${emp.status === 'Online' ? `
                    <button class="action-btn action-btn-logout" onclick="forceLogoutEmployee(${userId})" title="Force logout">
                        <ion-icon name="log-out-outline"></ion-icon>
                    </button>
                    ` : ''}
                    <button class="action-btn action-btn-delete" onclick="deleteEmployee(${userId})" title="Delete">
                        <ion-icon name="trash-outline"></ion-icon>
                    </button>
                </div>
            </td>
            <td data-label="Select" class="employee-select">
                <input type="checkbox" class="employee-checkbox" data-user-id="${userId}" ${isSelected ? 'checked' : ''}>
            </td>
        </tr>
    `;
    }).join('');

    attachEmployeeRowHandlers();
    syncEmployeeSelectAllState();
    updateEmployeeBulkActions();
}

function attachEmployeeRowHandlers() {
    document.querySelectorAll('.employee-checkbox').forEach(checkbox => {
        checkbox.addEventListener('click', event => {
            event.stopPropagation();
            const id = parseInt(event.currentTarget.dataset.userId, 10);
            if (!Number.isFinite(id)) return;

            if (event.currentTarget.checked) {
                selectedEmployeeIds.add(id);
                const rowElement = event.currentTarget.closest('tr');
                if (rowElement) {
                    rowElement.classList.add('selected');
                }
            } else {
                selectedEmployeeIds.delete(id);
                const rowElement = event.currentTarget.closest('tr');
                if (rowElement) {
                    rowElement.classList.remove('selected');
                }
            }

            syncEmployeeSelectAllState();
            updateEmployeeBulkActions();
        });
    });
}

function syncEmployeeSelectAllState() {
    const selectAll = document.getElementById('employeesSelectAll');
    if (!selectAll) return;

    const total = employees.length;
    const selected = selectedEmployeeIds.size;

    selectAll.checked = total > 0 && selected === total;
    selectAll.indeterminate = selected > 0 && selected < total;
}

function updateEmployeeBulkActions() {
    const bulkBar = document.getElementById('employeeBulkActions');
    const deleteBtn = document.getElementById('employeeBulkDeleteBtn');
    const attachBtn = document.getElementById('employeeBulkAttachFileBtn');
    const countEl = document.getElementById('selectedEmployeesCount');

    if (!bulkBar || !deleteBtn) return;

    const count = selectedEmployeeIds.size;
    if (count > 0) {
        bulkBar.classList.add('visible');
        deleteBtn.disabled = false;
        if (attachBtn) attachBtn.disabled = false;
        if (countEl) countEl.textContent = count;
    } else {
        bulkBar.classList.remove('visible');
        deleteBtn.disabled = true;
        if (attachBtn) attachBtn.disabled = true;
        if (countEl) countEl.textContent = '0';
    }
}

function renderOnlineIndicator() {
    const indicator = document.getElementById('employeeOnlineIndicator');
    if (!indicator) return;

    const onlineEmployees = employees.filter(emp => emp.status === 'Online');
    if (onlineEmployees.length === 0) {
        indicator.innerHTML = '<span class="status-pill offline">No employees online</span>';
        return;
    }

    const names = onlineEmployees
        .map(emp => {
            const name = escapeHtml(emp.username || `User ${emp.userID}`);
            const role = escapeHtml(emp.role || 'user');
            return `${name} (${role})`;
        })
        .join(', ');

    indicator.innerHTML = `
        <span class="status-pill online">${onlineEmployees.length} online</span>
        <span class="online-names">${names}</span>
    `;
}

// Format date display
function formatDate(dateString) {
    if (!dateString) return 'Never';
    const date = new Date(dateString);
    return date.toLocaleDateString();
}

function updateCredentialFieldVisibility() {
    const roleSelect = document.getElementById('employeeRole');
    const passwordGroup = document.getElementById('employeePasswordGroup');
    const pinGroup = document.getElementById('employeePinGroup');
    const passwordInput = document.getElementById('employeePassword');
    const pinInput = document.getElementById('employeePin');

    if (!roleSelect || !passwordGroup || !pinGroup || !passwordInput || !pinInput) {
        return;
    }

    const role = roleSelect.value;
    if (role === 'admin') {
        passwordGroup.style.display = '';
        pinGroup.style.display = 'none';
        passwordInput.required = true;
        pinInput.required = false;
        pinInput.value = '';
    } else {
        passwordGroup.style.display = 'none';
        pinGroup.style.display = '';
        passwordInput.required = false;
        passwordInput.value = '';
        pinInput.required = true;
    }
}

function updateEditCredentialVisibility(roleSelect, container) {
    if (!roleSelect || !container) {
        return;
    }
    const passwordGroup = container.querySelector('#editPasswordGroup');
    const pinGroup = container.querySelector('#editPinGroup');
    const passwordInput = container.querySelector('#editPassword');
    const pinInput = container.querySelector('#editPin');

    if (!passwordGroup || !pinGroup || !passwordInput || !pinInput) {
        return;
    }

    if (roleSelect.value === 'admin') {
        passwordGroup.style.display = '';
        pinGroup.style.display = 'none';
        passwordInput.required = false;
        pinInput.required = false;
        pinInput.value = '';
    } else {
        passwordGroup.style.display = 'none';
        pinGroup.style.display = '';
        passwordInput.required = false;
        passwordInput.value = '';
        pinInput.required = false;
    }
}

function setupEditCredentialHandlers(roleSelect, container) {
    if (!roleSelect || !container) {
        return;
    }
    const handler = () => updateEditCredentialVisibility(roleSelect, container);
    roleSelect.addEventListener('change', handler);
    // Store handler for potential cleanup if needed
    roleSelect.dataset.credentialHandler = 'true';
    updateEditCredentialVisibility(roleSelect, container);
}

// Show add employee modal (use .show class for visibility per Employees.css)
function showAddEmployeeModal() {
    const modal = document.getElementById('addEmployeeModal');
    if (modal) {
        modal.style.display = 'flex';
        modal.classList.add('show');
        const form = document.getElementById('addEmployeeForm');
        if (form) {
            form.reset();
        }
        const roleSelect = document.getElementById('employeeRole');
        if (roleSelect) {
            roleSelect.value = 'cashier';
        }
        updateCredentialFieldVisibility();
        loadBranchDropdown();
    }
}

// Close add employee modal
function closeAddEmployeeModal() {
    const modal = document.getElementById('addEmployeeModal');
    if (modal) {
        modal.classList.remove('show');
        modal.style.display = 'none';
    }
    const form = document.getElementById('addEmployeeForm');
    if (form) {
        form.reset();
    }
    const roleSelect = document.getElementById('employeeRole');
    if (roleSelect) {
        roleSelect.value = 'cashier';
    }
    updateCredentialFieldVisibility();
}

// Load branches into Add Employee branch dropdown
async function loadBranchDropdown() {
    const select = document.getElementById('employeeBranch');
    if (!select) return;
    select.innerHTML = '<option value="">Loading branches...</option>';
    try {
        const res = await fetch('db/branches_getAll.php');
        const data = await res.json();
        if (data.status === 'success' && Array.isArray(data.branches)) {
            select.innerHTML = '<option value="">Owner (can see everything in both branches)</option>';
            const sortedBranches = data.branches.slice().sort(function (a, b) {
                return Number(a.branch_id) - Number(b.branch_id);
            });
            sortedBranches.forEach(function (b) {
                const opt = document.createElement('option');
                opt.value = b.branch_id;
                opt.textContent = b.branch_name || 'Branch ' + b.branch_id;
                select.appendChild(opt);
            });
        } else {
            select.innerHTML = '<option value="">No branches</option>';
        }
    } catch (e) {
        console.error('Load branches:', e);
        select.innerHTML = '<option value="">Failed to load branches</option>';
    }
}

// Handle add employee form submission
document.addEventListener('DOMContentLoaded', function () {
    const addBtn = document.getElementById('addEmployeeBtn');
    if (addBtn) {
        addBtn.addEventListener('click', function () { showAddEmployeeModal(); });
    }
    const form = document.getElementById('addEmployeeForm');
    const roleSelect = document.getElementById('employeeRole');

    if (roleSelect) {
        roleSelect.addEventListener('change', updateCredentialFieldVisibility);
    }
    updateCredentialFieldVisibility();

    if (form) {
        // Client-side input sanitizers
        const firstNameEl = document.getElementById('employeeFirstName');
        const lastNameEl = document.getElementById('employeeLastName');
        const phoneEl = document.getElementById('employeePhone');
        const pinEl = document.getElementById('employeePin');
        const emailEl = document.getElementById('employeeEmail');

        if (firstNameEl) {
            firstNameEl.setAttribute('inputmode', 'text');
            firstNameEl.addEventListener('input', () => {
                // Allow only letters and spaces
                firstNameEl.value = firstNameEl.value.replace(/[^A-Za-z\s]/g, '');
            });
        }
        if (lastNameEl) {
            lastNameEl.setAttribute('inputmode', 'text');
            lastNameEl.addEventListener('input', () => {
                lastNameEl.value = lastNameEl.value.replace(/[^A-Za-z\s]/g, '');
            });
        }
        if (phoneEl) {
            phoneEl.setAttribute('inputmode', 'numeric');
            phoneEl.addEventListener('input', () => {
                phoneEl.value = phoneEl.value.replace(/\D/g, '');
            });
        }
        if (pinEl) {
            pinEl.setAttribute('inputmode', 'numeric');
            pinEl.addEventListener('input', () => {
                pinEl.value = pinEl.value.replace(/\D/g, '').slice(0, 4);
            });
        }

        form.addEventListener('submit', async function (e) {
            e.preventDefault();

            // Basic Information
            const firstName = document.getElementById('employeeFirstName').value;
            const lastName = document.getElementById('employeeLastName').value;
            const email = document.getElementById('employeeEmail').value;
            const phone = document.getElementById('employeePhone').value;
            const address = document.getElementById('employeeAddress').value;

            // Login Information
            const username = document.getElementById('employeeUsername').value;
            let password = document.getElementById('employeePassword').value;
            const role = document.getElementById('employeeRole').value;
            const pin = document.getElementById('employeePin').value;
            const employeeId = document.getElementById('employeeId').value;

            if (!firstName || !lastName || !email || !phone || !address || !username || !employeeId) {
                showToast('Please fill in all required fields.', 'error');
                return;
            }

            // Basic client-side validation
            if (!/^[A-Za-z\s]+$/.test(firstName) || !/^[A-Za-z\s]+$/.test(lastName)) {
                showToast('First and last names must contain letters and spaces only.', 'error');
                return;
            }
            if (!/^\S+@\S+\.\S+$/.test(email)) {
                showToast('Please enter a valid email address.', 'error');
                return;
            }
            if (!/^\d+$/.test(phone)) {
                showToast('Phone number must contain digits only.', 'error');
                return;
            }
            if (phone.length !== 11) {
                showToast('Phone number must be exactly 11 digits.', 'error');
                return;
            }

            if (!['admin', 'cashier'].includes(role)) {
                showToast('Invalid role selected.', 'error');
                return;
            }

            if (role === 'admin') {
                if (!password) {
                    showToast('Password is required for admin accounts.', 'error');
                    return;
                }
                // Enforce client-side password strength for admin accounts
                if (!(password.length >= 8 && /[A-Za-z]/.test(password) && /[0-9]/.test(password))) {
                    showToast('Password must be at least 8 characters and include letters and numbers.', 'error');
                    return;
                }
            } else {
                if (!pin) {
                    showToast('PIN is required for cashier accounts.', 'error');
                    return;
                }
                if (!/^[0-9]{4}$/.test(pin)) {
                    showToast('PIN must be exactly 4 digits.', 'error');
                    return;
                }
                if (!password) {
                    password = pin;
                }
            }

            const pinToSend = role === 'cashier' ? pin : '';
            const passwordToSend = password;
            const branchEl = document.getElementById('employeeBranch');
            const branchId = (branchEl && branchEl.value) ? branchEl.value : '';

            try {
                let body = `firstName=${encodeURIComponent(firstName)}&lastName=${encodeURIComponent(lastName)}&email=${encodeURIComponent(email)}&phone=${encodeURIComponent(phone)}&address=${encodeURIComponent(address)}&username=${encodeURIComponent(username)}&password=${encodeURIComponent(passwordToSend)}&role=${encodeURIComponent(role)}&pin=${encodeURIComponent(pinToSend)}&employeeId=${encodeURIComponent(employeeId)}`;
                if (branchId) body += '&branch_id=' + encodeURIComponent(branchId);
                const response = await fetch('db/employees_add.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: body
                });

                if (response.ok) {
                    showToast('Employee added successfully!', 'success');
                    closeAddEmployeeModal();
                    loadEmployees();
                } else {
                    const error = await response.text();
                    showToast('Failed to add employee: ' + error, 'error');
                }
            } catch (error) {
                console.error('Error adding employee:', error);
                showToast('Error adding employee', 'error');
            }
        });
    }
});

function editEmployee(userID) {
    const employee = employees.find(emp => Number(emp.userID) === Number(userID));
    if (!employee) {
        showToast('Unable to locate employee record.', 'error');
        return;
    }

    openEditEmployeeModal(employee);
}

// Delete employee
async function deleteEmployee(userID) {
    const confirmed = await confirmAction('Are you sure you want to delete this employee?');
    if (!confirmed) {
        return;
    }

    try {
        const response = await fetch('db/employees_delete.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `userID=${userID}`
        });

        if (response.ok) {
            selectedEmployeeIds.delete(Number(userID));
            showToast('Employee deleted successfully!', 'success');
            await loadEmployees(); // Refresh list
            updateEmployeeBulkActions();
        } else {
            showToast('Failed to delete employee', 'error');
        }
    } catch (error) {
        console.error('Error deleting employee:', error);
        showToast('Error deleting employee', 'error');
    }
}

async function forceLogoutEmployee(userID) {
    const confirmed = await confirmAction('Force logout this user? Their current session will be ended.');
    if (!confirmed) {
        return;
    }

    try {
        const response = await fetch('db/force_logout.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `userID=${encodeURIComponent(userID)}`
        });

        const result = await response.json();
        if (!response.ok || result.status !== 'success') {
            throw new Error(result.message || 'Failed to force logout user');
        }

        showToast('User has been logged out.', 'success');
    } catch (error) {
        console.error('Error forcing logout:', error);
        showToast(error.message || 'Failed to force logout user', 'error');
    }
}

function openEditEmployeeModal(employee) {
    const modalId = 'employeeEditModal';
    const content = `
        <form id="editEmployeeForm" class="employee-edit-form">
            <input type="hidden" name="userID" value="${escapeHtml(employee.userID)}">
            <div class="form-grid">
                <div class="form-group">
                    <label for="editFirstName">First Name</label>
                    <input id="editFirstName" name="firstName" type="text" required value="${escapeHtml(employee.first_name || '')}">
                </div>
                <div class="form-group">
                    <label for="editLastName">Last Name</label>
                    <input id="editLastName" name="lastName" type="text" required value="${escapeHtml(employee.last_name || '')}">
                </div>
            </div>
            <div class="form-grid">
                <div class="form-group">
                    <label for="editEmail">Email</label>
                    <input id="editEmail" name="email" type="email" required value="${escapeHtml(employee.email || '')}">
                </div>
                <div class="form-group">
                    <label for="editPhone">Phone</label>
                    <input id="editPhone" name="phone" type="tel" inputmode="numeric" pattern="[0-9]{11}" maxlength="11" placeholder="09123456789" required value="${escapeHtml(employee.phone || '')}">
                </div>
            </div>
            <div class="form-group">
                <label for="editAddress">Address</label>
                <textarea id="editAddress" name="address" rows="2" required>${escapeHtml(employee.address || '')}</textarea>
            </div>
            <div class="form-grid">
                <div class="form-group">
                    <label for="editRole">Role</label>
                    <select id="editRole" name="role" required>
                        <option value="cashier" ${employee.role === 'cashier' ? 'selected' : ''}>Cashier</option>
                        <option value="admin" ${employee.role === 'admin' ? 'selected' : ''}>Admin</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="editEmployeeId">Employee ID</label>
                    <input id="editEmployeeId" name="employeeId" type="text" required value="${escapeHtml(employee.employee_id || '')}">
                </div>
            </div>
            <div class="form-group">
                <label for="editEmployeeBranch">Branch</label>
                <select id="editEmployeeBranch" name="branch_id">
                    <option value="">Loading branches...</option>
                </select>
            </div>
            <div class="form-grid">
                <div class="form-group" id="editPasswordGroup" style="display:${employee.role === 'admin' ? '' : 'none'};">
                    <label for="editPassword">New Password <span class="optional">(leave blank to keep current)</span></label>
                    <input id="editPassword" name="password" type="password" autocomplete="new-password" placeholder="********">
                </div>
                <div class="form-group" id="editPinGroup" style="display:${employee.role === 'cashier' ? '' : 'none'};">
                    <label for="editPin">New 4-digit PIN <span class="optional">(leave blank to keep current)</span></label>
                    <input id="editPin" name="pin" type="password" inputmode="numeric" pattern="[0-9]{4}" maxlength="4" placeholder="1234">
                </div>
            </div>
            <div class="form-actions">
                <button type="button" class="btn-secondary" data-modal-close>Cancel</button>
                <button type="submit" class="btn-primary">Save Changes</button>
            </div>
        </form>
    `;

    ModalHelper.open({
        id: modalId,
        title: `Edit Employee - ${escapeHtml(employee.username)}`,
        content,
        width: '560px',
        onOpen: ({ body }) => {
            const form = body.querySelector('#editEmployeeForm');

            // Add input sanitizers for edit form
            const editFirstName = body.querySelector('#editFirstName');
            const editLastName = body.querySelector('#editLastName');
            const editPhone = body.querySelector('#editPhone');
            const editPin = body.querySelector('#editPin');
            const editAddress = body.querySelector('#editAddress');

            if (editFirstName) {
                editFirstName.addEventListener('input', () => {
                    editFirstName.value = editFirstName.value.replace(/[^A-Za-z\s]/g, '');
                });
            }
            if (editLastName) {
                editLastName.addEventListener('input', () => {
                    editLastName.value = editLastName.value.replace(/[^A-Za-z\s]/g, '');
                });
            }
            if (editPhone) {
                editPhone.addEventListener('input', () => {
                    editPhone.value = editPhone.value.replace(/\D/g, '').slice(0, 11);
                });
            }
            if (editPin) {
                editPin.addEventListener('input', () => {
                    editPin.value = editPin.value.replace(/\D/g, '').slice(0, 4);
                });
            }

            form.addEventListener('submit', handleEditEmployeeSubmit);
            const roleSelect = body.querySelector('#editRole');
            setupEditCredentialHandlers(roleSelect, body);
            loadEditEmployeeBranches(body, employee.branch_id ?? '');
            const cancelBtn = body.querySelector('[data-modal-close]');
            cancelBtn?.addEventListener('click', () => ModalHelper.close(modalId));
        }
    });
}

async function loadEditEmployeeBranches(body, selectedBranchId) {
    const select = body.querySelector('#editEmployeeBranch');
    if (!select) return;
    select.innerHTML = '<option value="">Loading branches...</option>';
    try {
        const res = await fetch('db/branches_getAll.php');
        const data = await res.json();
        if (data.status === 'success' && Array.isArray(data.branches)) {
            select.innerHTML = '<option value="">Owner (can see everything in both branches)</option>';
            const sortedBranches = data.branches.slice().sort(function (a, b) {
                return Number(a.branch_id) - Number(b.branch_id);
            });
            sortedBranches.forEach(function (b) {
                const opt = document.createElement('option');
                opt.value = b.branch_id;
                opt.textContent = b.branch_name || 'Branch ' + b.branch_id;
                if (String(b.branch_id) === String(selectedBranchId)) {
                    opt.selected = true;
                }
                select.appendChild(opt);
            });
        } else {
            select.innerHTML = '<option value="">No branches</option>';
        }
    } catch (e) {
        console.error('Load branches:', e);
        select.innerHTML = '<option value="">Failed to load branches</option>';
    }
}

async function handleEditEmployeeSubmit(event) {
    event.preventDefault();
    const form = event.currentTarget;
    const submitBtn = form.querySelector('button[type="submit"]');
    const originalText = submitBtn.textContent;

    submitBtn.disabled = true;
    submitBtn.textContent = 'Saving...';

    try {
        const formData = new FormData(form);
        const payload = new URLSearchParams();
        for (const [key, value] of formData.entries()) {
            if (key === 'branch_id') {
                payload.append(key, value);
            } else {
                payload.append(key, value.trim());
            }
        }

        // Validation for edit form
        const firstName = payload.get('firstName') || '';
        const lastName = payload.get('lastName') || '';
        const email = payload.get('email') || '';
        const phone = payload.get('phone') || '';
        const address = payload.get('address') || '';
        const employeeId = payload.get('employeeId') || '';
        const pin = payload.get('pin') || '';

        if (!firstName || !lastName || !email || !phone || !address || !employeeId) {
            throw new Error('Please fill in all required fields.');
        }

        if (!/^[A-Za-z\s]+$/.test(firstName) || !/^[A-Za-z\s]+$/.test(lastName)) {
            throw new Error('First and last names must contain letters and spaces only.');
        }

        if (!/^\S+@\S+\.\S+$/.test(email)) {
            throw new Error('Please enter a valid email address.');
        }

        if (!/^\d+$/.test(phone)) {
            throw new Error('Phone number must contain digits only.');
        }

        if (phone.length !== 11) {
            throw new Error('Phone number must be exactly 11 digits.');
        }

        // Client-side check for password strength if provided and role is admin
        const roleVal = payload.get('role');
        const pwVal = payload.get('password') || '';
        if (roleVal === 'admin' && pwVal && !(pwVal.length >= 8 && /[A-Za-z]/.test(pwVal) && /[0-9]/.test(pwVal))) {
            throw new Error('Password must be at least 8 characters and include letters and numbers.');
        }

        // Validate PIN if provided
        if (pin && (!/^\d{4}$/.test(pin))) {
            throw new Error('PIN must be exactly 4 digits.');
        }

        const response = await fetch('db/employees_update.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: payload.toString()
        });

        if (!response.ok) {
            const errorText = await response.text();
            throw new Error(errorText || 'Failed to update employee.');
        }

        const result = await response.json();
        if (result.status !== 'success') {
            throw new Error(result.message || 'Failed to update employee.');
        }

        showToast('Employee updated successfully!', 'success');
        ModalHelper.close('employeeEditModal');
        await loadEmployees();
        updateEmployeeBulkActions();
    } catch (error) {
        console.error('Error updating employee:', error);
        showToast(error.message || 'Failed to update employee.', 'error');
    } finally {
        submitBtn.disabled = false;
        submitBtn.textContent = originalText;
    }
}

async function deleteSelectedEmployees() {
    const ids = Array.from(selectedEmployeeIds);
    if (ids.length === 0) {
        showToast('No employees selected', 'warning');
        return;
    }

    const confirmMessage = ids.length === 1
        ? 'Delete selected employee?'
        : `Delete ${ids.length} employees?`;
    const confirmed = await confirmAction(confirmMessage);
    if (!confirmed) {
        return;
    }

    try {
        for (const id of ids) {
            const response = await fetch('db/employees_delete.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `userID=${encodeURIComponent(id)}`
            });
            const resultText = await response.text();
            if (!response.ok || resultText.trim() !== 'success') {
                throw new Error(resultText || 'Failed to delete employee');
            }
        }

        showToast(`Deleted ${ids.length} employee${ids.length > 1 ? 's' : ''}`, 'success');
        selectedEmployeeIds.clear();
        await loadEmployees();
        updateEmployeeBulkActions();
    } catch (error) {
        console.error('Bulk delete error:', error);
        showToast(error.message || 'Failed to delete selected employees', 'error');
    }
}

// Employee filtering functions
function initEmployeeFilters() {
    const searchInput = document.getElementById('employeeSearch');
    const roleFilter = document.getElementById('employeeRoleFilter');

    if (searchInput) {
        searchInput.addEventListener('input', filterEmployees);
    }

    if (roleFilter) {
        roleFilter.addEventListener('change', filterEmployees);
    }
}

function filterEmployees() {
    const searchTerm = document.getElementById('employeeSearch')?.value.toLowerCase() || '';
    const roleFilter = document.getElementById('employeeRoleFilter')?.value || '';

    const filteredEmployees = employees.filter(emp => {
        const matchesSearch = emp.username.toLowerCase().includes(searchTerm) ||
            String(emp.employee_id || '').toLowerCase().includes(searchTerm);
        const matchesRole = !roleFilter || emp.role === roleFilter;

        return matchesSearch && matchesRole;
    });

    // Temporarily store original and render filtered
    const originalEmployees = employees;
    employees = filteredEmployees;
    renderEmployeesTable();
    employees = originalEmployees; // Restore original data
}

// Initialize on document ready
document.addEventListener('DOMContentLoaded', function () {
    initEmployeeFilters();

    const selectAll = document.getElementById('employeesSelectAll');
    if (selectAll) {
        selectAll.addEventListener('change', event => {
            const checked = event.target.checked;
            selectedEmployeeIds.clear();
            if (checked) {
                document.querySelectorAll("#employeesTableBody .employee-row").forEach(row => {
                    const id = Number(row.dataset.userId);
                    if (Number.isFinite(id)) {
                        selectedEmployeeIds.add(id);
                    }
                });
            }
            renderEmployeesTable();
            updateEmployeeBulkActions();
        });
    }

    const bulkDeleteBtn = document.getElementById('employeeBulkDeleteBtn');
    if (bulkDeleteBtn) {
        bulkDeleteBtn.addEventListener('click', deleteSelectedEmployees);
    }

    // Use event delegation for attach file button - more reliable
    document.addEventListener('click', function (event) {
        if (event.target.closest('#employeeBulkAttachFileBtn')) {
            openAttachFileModal();
        }
    });

    const attachFileForm = document.getElementById('attachFileForm');
    if (attachFileForm) {
        attachFileForm.addEventListener('submit', handleAttachFileSubmit);
    } else {
        console.error('Attach file form NOT found in DOM');
    }
});

// File Attachment Functions
function openAttachFileModal() {
    const modal = document.getElementById('attachFileModal');
    if (!modal) {
        console.error('Attach file modal not found in DOM');
        showToast('Modal not found', 'error');
        return;
    }

    const ids = Array.from(selectedEmployeeIds);
    if (ids.length === 0) {
        showToast('No employees selected', 'warning');
        return;
    }

    // Populate recipients list
    const recipientsList = document.getElementById('attachFileRecipientsList');
    const selectedCount = document.getElementById('attachFileSelectedCount');

    if (!recipientsList || !selectedCount) {
        console.error('Recipients list or count element not found');
        showToast('Modal elements not found', 'error');
        return;
    }

    const recipientHTML = employees
        .filter(emp => ids.includes(Number(emp.userID)))
        .map(emp => `
            <div style="padding: 8px; border-bottom: 1px solid #eee; display: flex; align-items: center;">
                <span style="color: #555;">${escapeHtml(emp.username)} (${escapeHtml(emp.email || 'N/A')})</span>
            </div>
        `).join('');

    recipientsList.innerHTML = recipientHTML || '<div style="padding: 8px; color: #999;">No recipients</div>';
    selectedCount.textContent = `${ids.length} recipient${ids.length > 1 ? 's' : ''} selected`;

    // Reset form
    const attachFileForm = document.getElementById('attachFileForm');
    if (attachFileForm) {
        attachFileForm.reset();
    }

    // Show modal
    modal.classList.add('show');
    console.log('Modal show class added, modal classes:', modal.className);
}

function closeAttachFileModal() {
    const modal = document.getElementById('attachFileModal');
    if (modal) {
        modal.classList.remove('show');
    }
}

function handleAttachFileSubmit(event) {
    event.preventDefault();

    const ids = Array.from(selectedEmployeeIds);
    if (ids.length === 0) {
        showToast('No employees selected', 'warning');
        return;
    }

    const fileInput = document.getElementById('attachFileInput');
    const messageInput = document.getElementById('attachFileMessage');

    if (!fileInput.files || fileInput.files.length === 0) {
        showToast('Please select a file', 'warning');
        return;
    }

    const file = fileInput.files[0];
    const maxSize = 10 * 1024 * 1024; // 10MB

    if (file.size > maxSize) {
        showToast('File size exceeds 10MB limit', 'error');
        return;
    }

    // Validate file type
    const allowedExtensions = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'txt', 'jpg', 'jpeg', 'png'];
    const fileExtension = file.name.split('.').pop().toLowerCase();

    if (!allowedExtensions.includes(fileExtension)) {
        showToast('File type not supported', 'error');
        return;
    }

    sendFileToEmployees(file, messageInput.value.trim(), ids);
}

async function sendFileToEmployees(file, message, employeeIds) {
    const loadingOverlay = document.getElementById('loadingOverlay');
    if (loadingOverlay) loadingOverlay.style.display = 'flex';

    try {
        const formData = new FormData();
        formData.append('file', file);
        formData.append('message', message);
        formData.append('employeeIds', JSON.stringify(employeeIds));

        const response = await fetch('db/send_employee_file.php', {
            method: 'POST',
            body: formData
        });

        const resultText = await response.text();

        if (response.ok && resultText.trim() === 'success') {
            showToast(`File sent successfully to ${employeeIds.length} employee${employeeIds.length > 1 ? 's' : ''}`, 'success');
            closeAttachFileModal();
            selectedEmployeeIds.clear();
            updateEmployeeBulkActions();
        } else {
            throw new Error(resultText || 'Failed to send file');
        }
    } catch (error) {
        console.error('Send file error:', error);
        showToast(error.message || 'Failed to send file to employees', 'error');
    } finally {
        if (loadingOverlay) loadingOverlay.style.display = 'none';
    }
}

// Reset employee password
async function resetEmployeePassword(userID) {
    const confirmed = await confirmAction('Are you sure you want to reset the password for this employee? A new temporary password will be generated and displayed.');
    if (!confirmed) {
        return;
    }

    try {
        const response = await fetch('db/employees_reset_password.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `userID=${userID}`
        });

        const result = await response.json();

        if (result.status === 'success') {
            showToast(`Password reset successfully! New password: ${result.newPassword}`, 'success');
            showToast(`New password for User ID ${userID}: ${result.newPassword}. Please save this and provide it to the employee.`, 'info', { duration: 8000 });
        } else {
            showToast(result.message || 'Failed to reset password', 'error');
        }
    } catch (error) {
        console.error('Error resetting password:', error);
        showToast('Error resetting password', 'error');
    }
}

// Expose functions globally
window.showAddEmployeeModal = showAddEmployeeModal;
window.closeAddEmployeeModal = closeAddEmployeeModal;
window.loadEmployees = loadEmployees;
window.editEmployee = editEmployee;
window.deleteEmployee = deleteEmployee;
window.forceLogoutEmployee = forceLogoutEmployee;
window.switchEmployeeTab = switchEmployeeTab;
window.openAttachFileModal = openAttachFileModal;
window.closeAttachFileModal = closeAttachFileModal;
window.resetEmployeePassword = resetEmployeePassword;







