// Connected to pages/client/login.html

document.addEventListener('DOMContentLoaded', function () {
    // Already logged in? Redirect to dashboard.
    API.requireGuest('./dashboard.html');

    const form = document.getElementById('customerLoginForm');
    const phoneInput = document.getElementById('phone');

    if (!form) return;

    // ── Inline error display ──────────────────────────────
    const errorEl = document.createElement('div');
    errorEl.className = 'hidden mt-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700';
    form.insertAdjacentElement('afterend', errorEl);

    function showError(msg) {
        errorEl.textContent = msg;
        errorEl.classList.remove('hidden');
    }

    function clearError() {
        errorEl.classList.add('hidden');
        errorEl.textContent = '';
    }

    // ── Phone input: digits-only mask ─────────────────────
    if (phoneInput) {
        phoneInput.addEventListener('input', function () {
            const digits = this.value.replace(/\D/g, '').slice(0, 11);
            this.value = digits;
            this.setCustomValidity(
                digits.length === 0 || /^09\d{0,9}$/.test(digits)
                    ? ''
                    : 'Please enter a valid 11-digit mobile number starting with 09.'
            );
        });
    }

    // ── Form submit ───────────────────────────────────────
    form.addEventListener('submit', async function (e) {
        e.preventDefault();
        clearError();

        const phone    = phoneInput.value.trim();
        const password = document.getElementById('password').value;
        const submitBtn = form.querySelector('[type="submit"]');

        if (!phone || !password) {
            showError('Please fill in all fields.');
            return;
        }

        if (!/^09\d{9}$/.test(phone)) {
            showError('Please enter a valid 11-digit mobile number starting with 09.');
            phoneInput.focus();
            return;
        }

        submitBtn.disabled = true;
        submitBtn.textContent = 'Logging in…';

        const { ok, data } = await API.Auth.customerLogin(phone, password);

        submitBtn.disabled = false;
        submitBtn.textContent = 'Log In';

        if (!ok) {
            showError(data.message || 'Invalid phone number or password.');
            return;
        }

        API.setToken(data.token);
        API.setUser(data.user);

        window.location.href = './dashboard.html';
    });
});
