(function () {
  const DEFAULT_BASE_URL = "http://127.0.0.1:8000/api";
  const BASE_URL_STORAGE_KEY = "bethlehem.apiBaseUrl";
  const AUTH_TOKEN_STORAGE_KEY = "bethlehem.authToken";
  const AUTH_USER_STORAGE_KEY = "bethlehem.authUser";

  const configuredBaseUrl = window.localStorage.getItem(BASE_URL_STORAGE_KEY);
  const baseUrl = (configuredBaseUrl || DEFAULT_BASE_URL).replace(/\/+$/, "");

  function extractErrorMessage(data, fallbackMessage) {
    if (!data || typeof data !== "object") return fallbackMessage;

    if (typeof data.message === "string" && data.message.trim()) {
      return data.message;
    }

    if (data.errors && typeof data.errors === "object") {
      const firstField = Object.keys(data.errors)[0];
      const firstError = firstField && data.errors[firstField];
      if (Array.isArray(firstError) && firstError.length > 0) {
        return firstError[0];
      }
    }

    return fallbackMessage;
  }

  async function request(path, options = {}) {
    const { method = "GET", body, token } = options;

    const headers = {
      Accept: "application/json",
    };

    if (body !== undefined) {
      headers["Content-Type"] = "application/json";
    }

    if (token) {
      headers.Authorization = `Bearer ${token}`;
    }

    const response = await fetch(`${baseUrl}${path}`, {
      method,
      headers,
      body: body !== undefined ? JSON.stringify(body) : undefined,
    });

    const data = await response.json().catch(() => ({}));

    if (!response.ok) {
      const fallbackMessage = `Request failed with status ${response.status}`;
      const error = new Error(extractErrorMessage(data, fallbackMessage));
      error.status = response.status;
      error.data = data;
      throw error;
    }

    return data;
  }

  function setSession(token, user) {
    window.localStorage.setItem(AUTH_TOKEN_STORAGE_KEY, token);
    window.localStorage.setItem(AUTH_USER_STORAGE_KEY, JSON.stringify(user));
  }

  function clearSession() {
    window.localStorage.removeItem(AUTH_TOKEN_STORAGE_KEY);
    window.localStorage.removeItem(AUTH_USER_STORAGE_KEY);
  }

  function getToken() {
    return window.localStorage.getItem(AUTH_TOKEN_STORAGE_KEY);
  }

  function getUser() {
    const rawUser = window.localStorage.getItem(AUTH_USER_STORAGE_KEY);
    if (!rawUser) return null;

    try {
      return JSON.parse(rawUser);
    } catch {
      return null;
    }
  }

  window.BethlehemApi = {
    baseUrl,
    keys: {
      baseUrl: BASE_URL_STORAGE_KEY,
      authToken: AUTH_TOKEN_STORAGE_KEY,
      authUser: AUTH_USER_STORAGE_KEY,
    },
    request,
    register(payload) {
      return request("/register", { method: "POST", body: payload });
    },
    login(payload) {
      return request("/login", { method: "POST", body: payload });
    },
    me(token = getToken()) {
      return request("/me", { token });
    },
    logout(token = getToken()) {
      return request("/logout", { method: "POST", token });
    },
    setSession,
    clearSession,
    getToken,
    getUser,
  };
})();
