async function apiRequest(url, options = {}) {
  let accessToken = localStorage.getItem("access_token");

  options.headers = {
    ...options.headers,
    Authorization: "Bearer " + accessToken,
  };

  let response = await fetch(url, options);

  // If access token expired
  if (response.status === 401) {
    let refreshed = await refreshAccessToken();

    if (refreshed) {
      // Retry original request with new token
      accessToken = localStorage.getItem("access_token");

      options.headers["Authorization"] = "Bearer " + accessToken;

      return fetch(url, options);
    } else {
      window.location.href = "/login.html";
    }
  }

  return response;
}
async function refreshAccessToken() {
  const refreshToken = localStorage.getItem("refresh_token");

  if (!refreshToken) return false;

  let response = await fetch("/api/refresh.php", {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
    },
    body: JSON.stringify({
      refresh_token: refreshToken,
    }),
  });

  if (response.ok) {
    let data = await response.json();
    localStorage.setItem("access_token", data.access_token);
    return true;
  } else {
    return false;
  }
}
