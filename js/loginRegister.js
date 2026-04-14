document.addEventListener('DOMContentLoaded', () => {
    const loginForm = document.getElementById('login-form');
    const identifierInput = document.getElementById('login-identifier');
    const passwordInput = document.getElementById('login-password');
    const loginButton = document.getElementById('login-btn');

    if (!loginForm || !identifierInput || !passwordInput || !loginButton) {
        console.error('Login form elements missing from DOM.');
        return;
    }

    const attemptLogin = () => {
        const identifier = identifierInput.value.trim();
        const secret = passwordInput.value.trim();

        if (!identifier || !secret) {
            showToast('Please enter both username/employee ID and password or PIN.', 'error');
            return;
        }

        const fd = new FormData();
        fd.append('identifier', identifier);
        fd.append('password', secret);

        loginButton.disabled = true;
        loginButton.textContent = 'Signing In...';

        fetch('db/login.php', {
            method: 'POST',
            body: fd,
            cache: 'no-cache'
        })
            .then(async (response) => {
                const data = await response.json().catch(() => ({
                    status: 'error',
                    message: 'Invalid server response'
                }));
                if (!response.ok) {
                    throw new Error(data.message || `HTTP ${response.status}`);
                }
                return data;
            })
            .then((result) => {
                if (result.status === 'success') {
                    showToast('Login successful!', 'success');
                    const redirectUrl = result.role === 'admin' ? 'admin' : 'cashier';
                    setTimeout(() => {
                        window.location.href = redirectUrl;
                    }, 500);
                } else {
                    showToast(result.message || 'Invalid credentials', 'error');
                }
            })
            .catch((error) => {
                console.error('Login error:', error);
                showToast(error.message || 'Authentication error. Please try again.', 'error');
            })
            .finally(() => {
                loginButton.disabled = false;
                loginButton.textContent = 'Sign In';
            });
    };

    loginForm.addEventListener('submit', (event) => {
        event.preventDefault();
        attemptLogin();
    });

    passwordInput.addEventListener('keypress', (event) => {
        if (event.key === 'Enter') {
            event.preventDefault();
            attemptLogin();
        }
    });
});
