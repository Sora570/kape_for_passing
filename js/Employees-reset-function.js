
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
window.editEmployee = editEmployee;
window.deleteEmployee = deleteEmployee;
window.deleteSelectedEmployees = deleteSelectedEmployees;
window.resetEmployeePassword = resetEmployeePassword;
