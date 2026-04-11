// Central API service — Bethlehem Animal Clinic & Grooming System
const API_BASE = 'http://localhost:8000/api';

// ── Token / User Storage ──────────────────────────────────────────

function getToken() {
    return localStorage.getItem('auth_token');
}

function setToken(token) {
    localStorage.setItem('auth_token', token);
}

function getUser() {
    const raw = localStorage.getItem('auth_user');
    try { return raw ? JSON.parse(raw) : null; } catch { return null; }
}

function setUser(user) {
    localStorage.setItem('auth_user', JSON.stringify(user));
}

function clearAuth() {
    localStorage.removeItem('auth_token');
    localStorage.removeItem('auth_user');
}

// ── Base Request ──────────────────────────────────────────────────

async function apiRequest(method, path, body) {
    const headers = {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
    };

    const token = getToken();
    if (token) headers['Authorization'] = 'Bearer ' + token;

    const options = { method, headers };
    if (body !== undefined) options.body = JSON.stringify(body);

    try {
        const response = await fetch(API_BASE + path, options);
        const data = await response.json().catch(() => ({}));
        return { ok: response.ok, status: response.status, data };
    } catch (err) {
        return {
            ok: false,
            status: 0,
            data: { message: 'Unable to connect to the server. Make sure the backend is running.' },
        };
    }
}

// ── Auth Guards ───────────────────────────────────────────────────

// Redirect to login if not authenticated (for protected pages)
function requireAuth(redirectTo) {
    if (!getToken() || !getUser()) {
        window.location.replace(redirectTo || './login.html');
    }
}

// Redirect to admin login if not authenticated as admin
function requireAdmin(redirectTo) {
    const user = getUser();
    if (!getToken() || !user || user.role !== 'admin') {
        window.location.replace(redirectTo || './login.html');
    }
}

// Redirect away from login/register pages if already authenticated
function requireGuest(customerDash, adminDash) {
    const user = getUser();
    const token = getToken();
    if (token && user) {
        if (user.role === 'admin') {
            window.location.replace(adminDash || '../../pages/admin/dashboard.html');
        } else {
            window.location.replace(customerDash || './dashboard.html');
        }
    }
}

// ── Auth API Methods ──────────────────────────────────────────────

const Auth = {
    // Customer login by phone number
    customerLogin(phone, password) {
        return apiRequest('POST', '/login', { phone, password });
    },

    // Admin login by email
    adminLogin(email, password) {
        return apiRequest('POST', '/admin/login', { email, password });
    },

    // New customer registration
    register(data) {
        return apiRequest('POST', '/register', data);
    },

    // Logout — revokes token on backend and clears local storage
    async logout() {
        await apiRequest('POST', '/logout');
        clearAuth();
    },

    // Get current authenticated user from backend
    me() {
        return apiRequest('GET', '/me');
    },
};

// ── Global Exposure ───────────────────────────────────────────────

window.API = {
    getToken,
    setToken,
    getUser,
    setUser,
    clearAuth,
    requireAuth,
    requireAdmin,
    requireGuest,
    Auth,
};
