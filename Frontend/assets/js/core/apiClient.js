(function () {
  let refreshPromise = null;

  function buildUrl(controller, action, query) {
    const params = new URLSearchParams();
    params.set("controller", controller);

    if (action) {
      params.set("action", action);
    }

    Object.entries(query || {}).forEach(([key, value]) => {
      if (value !== undefined && value !== null && value !== "") {
        params.set(key, String(value));
      }
    });

    return `${window.AppConfig.apiBaseUrl}?${params.toString()}`;
  }

  async function parseResponse(response) {
    const contentType = response.headers.get("content-type") || "";
    if (contentType.includes("application/json")) {
      return response.json();
    }

    const text = await response.text();
    return { success: response.ok, message: text || "Unexpected response" };
  }

  async function refreshAccessToken() {
    if (refreshPromise) {
      return refreshPromise;
    }

    const refreshToken = window.AuthStorage.getRefreshToken();
    if (!refreshToken) {
      return false;
    }

    refreshPromise = fetch(buildUrl("auth", "refresh"), {
      method: "POST",
      headers: {
        "Content-Type": "application/json"
      },
      body: JSON.stringify({ refresh_token: refreshToken })
    })
      .then(async (response) => {
        const payload = await parseResponse(response);
        const accessToken = payload.access_token || payload.data?.access_token;

        if (!response.ok || !accessToken) {
          return false;
        }

        window.AuthStorage.updateAccessToken(accessToken);
        return true;
      })
      .catch(() => false)
      .finally(() => {
        refreshPromise = null;
      });

    return refreshPromise;
  }

  async function request(options) {
    const opts = options || {};
    const method = opts.method || "GET";
    const useAuth = opts.useAuth !== false;
    const headers = Object.assign({}, opts.headers || {});
    const requestOptions = { method, headers };

    if (useAuth) {
      const accessToken = window.AuthStorage.getAccessToken();
      if (accessToken) {
        requestOptions.headers.Authorization = `Bearer ${accessToken}`;
      }
    }

    if (opts.body instanceof FormData) {
      requestOptions.body = opts.body;
    } else if (opts.body !== undefined && opts.body !== null) {
      requestOptions.headers["Content-Type"] = "application/json";
      requestOptions.body = JSON.stringify(opts.body);
    }

    const response = await fetch(buildUrl(opts.controller, opts.action, opts.query), requestOptions);
    const payload = await parseResponse(response);

    if (response.status === 401 && useAuth && opts.retry !== false) {
      const refreshed = await refreshAccessToken();
      if (refreshed) {
        return request(Object.assign({}, opts, { retry: false }));
      }

      window.AuthStorage.clear();
      window.location.replace(window.AppConfig.pages.login);
      throw new Error("Session expired");
    }

    if (!response.ok || payload.success === false) {
      const error = new Error(payload.message || `Request failed with status ${response.status}`);
      error.status = response.status;
      error.payload = payload;
      throw error;
    }

    return payload;
  }

  window.ApiClient = {
    request,
    get(controller, action, query, options) {
      return request(Object.assign({}, options, { controller, action, query, method: "GET" }));
    },
    post(controller, action, body, options) {
      return request(Object.assign({}, options, { controller, action, body, method: "POST" }));
    },
    put(controller, action, body, options) {
      return request(Object.assign({}, options, { controller, action, body, method: "PUT" }));
    },
    delete(controller, action, body, options) {
      return request(Object.assign({}, options, { controller, action, body, method: "DELETE" }));
    }
  };
})();
