<?php
// Minimal reset page; token is provided in query string
$token = htmlspecialchars($_GET['token'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Reset admin password</title>
    <link rel="stylesheet" href="css/loginRegister.css">
</head>
<body>
    <div class="container" style="max-width:420px; margin:60px auto; padding:24px;">
        <h2 style="margin-bottom:8px">Set new admin password</h2>
        <p style="color:#6b7280; margin-bottom:16px">Choose a strong password (min 8 chars, include letters and numbers).</p>
        <div id="toast" class="toast"><span id="toast-message"></span></div>
        <form id="reset-form">
            <input type="password" id="newPassword" placeholder="New password" required class="form-input" style="width:100%; margin-bottom:12px;">
            <input type="password" id="confirmPassword" placeholder="Confirm password" required class="form-input" style="width:100%; margin-bottom:12px;">
            <button id="reset-btn" type="submit" class="btn-primary">Reset password</button>
            <a href="login" style="display:block; margin-top:12px; color:#2563eb;">Back to sign in</a>
        </form>
    </div>

    <script src="js/uiToast.js"></script>
    <script>
        const token = '<?php echo $token; ?>';
        const form = document.getElementById('reset-form');
        const btn = document.getElementById('reset-btn');
        form.addEventListener('submit', function(e){
            e.preventDefault();
            const pw = document.getElementById('newPassword').value;
            const pw2 = document.getElementById('confirmPassword').value;
            if (!pw || !pw2) { showToast('Please fill both fields','error'); return; }
            if (pw !== pw2) { showToast('Passwords do not match','error'); return; }
            // Basic client-side strength check
            if (pw.length < 8 || !(/[A-Za-z]/.test(pw) && /[0-9]/.test(pw))) {
                showToast('Password must be at least 8 chars and include letters and numbers','error');
                return;
            }
            btn.disabled = true;
            fetch('db/admin_reset_password.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'token=' + encodeURIComponent(token) + '&newPassword=' + encodeURIComponent(pw)
            }).then(r => r.json()).then(resp => {
                if (resp.status === 'success') {
                    showToast(resp.message || 'Password reset successful','success');
                    setTimeout(() => window.location.href = 'login', 1500);
                } else {
                    showToast(resp.message || 'Error resetting password','error');
                    btn.disabled = false;
                }
            }).catch(err => {
                console.error(err);
                showToast('Error resetting password','error');
                btn.disabled = false;
            });
        });
    </script>
</body>
</html>