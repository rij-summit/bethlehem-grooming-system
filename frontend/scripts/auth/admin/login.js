// Connected to pages/admin/login.html

document.addEventListener('DOMContentLoaded', function () {
    // Already logged in as admin? Go straight to dashboard.
    const user  = API.getUser();
    const token = API.getToken();
    if (token && user && user.role === 'admin') {
        window.location.replace('./dashboard.html');
        return;
    }

    const form = document.getElementById('adminLoginForm');
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

    // ── Form submit ───────────────────────────────────────
    form.addEventListener('submit', async function (e) {
        e.preventDefault();
        clearError();

        const email     = document.getElementById('adminEmail').value.trim();
        const password  = document.getElementById('adminPassword').value;
        const submitBtn = form.querySelector('[type="submit"]');

        if (!email || !password) {
            showError('Please fill in all fields.');
            return;
        }

        submitBtn.disabled    = true;
        submitBtn.textContent = 'Logging in…';

        const { ok, data } = await API.Auth.adminLogin(email, password);

        submitBtn.disabled    = false;
        submitBtn.textContent = 'Log In as Admin';

        if (!ok) {
            showError(data.message || 'Invalid credentials or unauthorized access.');
            return;
        }

        API.setToken(data.token);
        API.setUser(data.user);

        window.location.href = './dashboard.html';
    });
});
