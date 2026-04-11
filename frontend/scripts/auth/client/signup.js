// Connected to pages/client/signup.html

document.addEventListener('DOMContentLoaded', function () {
    const form      = document.getElementById('signupForm');
    const messageEl = document.getElementById('signupMessage');

    if (!form) return;

    function showMessage(msg, type) {
        messageEl.className = 'mt-5 rounded-xl border px-4 py-3 text-sm';
        if (type === 'error') {
            messageEl.classList.add('border-red-200', 'bg-red-50', 'text-red-700');
        } else {
            messageEl.classList.add('border-green-200', 'bg-green-50', 'text-green-700');
        }
        messageEl.textContent = msg;
        messageEl.classList.remove('hidden');
    }

    function hideMessage() {
        messageEl.classList.add('hidden');
    }

    form.addEventListener('submit', async function (e) {
        e.preventDefault();
        hideMessage();

        const firstName       = document.getElementById('firstName').value.trim();
        const lastName        = document.getElementById('lastName').value.trim();
        const email           = document.getElementById('email').value.trim();
        const phone           = document.getElementById('phone').value.trim();
        const password        = document.getElementById('password').value;
        const confirmPassword = document.getElementById('confirmPassword').value;
        const submitBtn       = form.querySelector('[type="submit"]');

        // ── Client-side pre-checks ────────────────────────
        if (password !== confirmPassword) {
            showMessage('Passwords do not match.', 'error');
            return;
        }

        if (password.length < 6) {
            showMessage('Password must be at least 6 characters long.', 'error');
            return;
        }

        submitBtn.disabled    = true;
        submitBtn.textContent = 'Creating Account…';

        const { ok, data } = await API.Auth.register({
            first_name:            firstName,
            last_name:             lastName,
            email:                 email || undefined,
            phone,
            password,
            password_confirmation: confirmPassword,
        });

        submitBtn.disabled    = false;
        submitBtn.textContent = 'Create Account';

        if (!ok) {
            // Laravel validation returns errors as an object keyed by field
            if (data.errors) {
                const first = Object.values(data.errors)[0];
                showMessage(Array.isArray(first) ? first[0] : first, 'error');
            } else {
                showMessage(data.message || 'Registration failed. Please try again.', 'error');
            }
            return;
        }

        // Auto-login: store token + user, then go to dashboard
        API.setToken(data.token);
        API.setUser(data.user);

        showMessage('Account created successfully! Redirecting…', 'success');

        setTimeout(() => {
            window.location.href = './dashboard.html';
        }, 1200);
    });
});
