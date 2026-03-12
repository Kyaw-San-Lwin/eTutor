async function apiRequest(controller, action = "", method = "GET", data = null, extra = {}) {
  const token = Storage.get("access_token");
  const headers = Object.assign({}, extra.headers || {});

  if (!(data instanceof FormData)) {
    headers["Content-Type"] = "application/json";
  }
  if (token) {
    headers["Authorization"] = "Bearer " + token;
  }

  const params = new URLSearchParams({ controller });
  if (action) {
    params.set("action", action);
  }
  if (extra.query) {
    Object.entries(extra.query).forEach(([key, value]) => {
      if (value !== undefined && value !== null && value !== "") {
        params.set(key, String(value));
      }
    });
  }

  let body = null;
  if (data) {
    body = data instanceof FormData ? data : JSON.stringify(data);
  }

  const response = await fetch(API_BASE + "?" + params.toString(), {
    method,
    headers,
    body
  });

  if (response.status === 401) {
    const refreshed = await refreshAccessToken();
    if (refreshed) {
      return apiRequest(controller, action, method, data, extra);
    }
    Storage.clearAuth();
    window.location.href = "Login.html";
    return null;
  }

  return response.json();
}

async function refreshAccessToken() {
  const refreshToken = Storage.get("refresh_token");
  if (!refreshToken) {
    return false;
  }

  const response = await fetch(API_BASE + "?controller=auth&action=refresh", {
    method: "POST",
    headers: {
      "Content-Type": "application/json"
    },
    body: JSON.stringify({ refresh_token: refreshToken })
  });

  if (!response.ok) {
    return false;
  }

  const data = await response.json();
  if (data && data.access_token) {
    Storage.set("access_token", data.access_token);
    return true;
  }
  return false;
}
