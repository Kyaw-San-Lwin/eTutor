(function () {
  const KEYS = {
    accessToken: "etutor_access_token",
    refreshToken: "etutor_refresh_token",
    user: "etutor_auth_user"
  };

  function readJson(key) {
    const value = localStorage.getItem(key);
    if (!value) {
      return null;
    }

    try {
      return JSON.parse(value);
    } catch (error) {
      return null;
    }
  }

  window.AuthStorage = {
    keys: KEYS,
    getAccessToken() {
      return localStorage.getItem(KEYS.accessToken);
    },
    getRefreshToken() {
      return localStorage.getItem(KEYS.refreshToken);
    },
    getUser() {
      return readJson(KEYS.user);
    },
    setAuth(payload) {
      if (payload.accessToken) {
        localStorage.setItem(KEYS.accessToken, payload.accessToken);
      }

      if (payload.refreshToken) {
        localStorage.setItem(KEYS.refreshToken, payload.refreshToken);
      }

      if (payload.user) {
        localStorage.setItem(KEYS.user, JSON.stringify(payload.user));
      }
    },
    updateAccessToken(accessToken) {
      if (accessToken) {
        localStorage.setItem(KEYS.accessToken, accessToken);
      }
    },
    clear() {
      localStorage.removeItem(KEYS.accessToken);
      localStorage.removeItem(KEYS.refreshToken);
      localStorage.removeItem(KEYS.user);
    }
  };
})();
