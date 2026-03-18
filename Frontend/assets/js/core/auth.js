(function () {
  function getHomePage(user) {
    if (!user || !user.role) {
      return window.AppConfig.pages.login;
    }

    if (user.role === "student") {
      return window.AppConfig.pages.studentDashboard;
    }

    if (user.role === "tutor") {
      return window.AppConfig.pages.tutorDashboard;
    }

    return window.AppConfig.pages.staffDashboard;
  }

  async function login(loginValue, password) {
    const payload = await window.ApiClient.post(
      "auth",
      "",
      {
        login: loginValue,
        password
      },
      { useAuth: false }
    );

    const user = payload.user || payload.data?.user || null;
    const accessToken = payload.access_token || payload.data?.access_token || null;
    const refreshToken = payload.refresh_token || payload.data?.refresh_token || null;

    if (!user || !accessToken) {
      throw new Error("Login response is incomplete");
    }

    window.AuthStorage.setAuth({
      user,
      accessToken,
      refreshToken
    });

    return user;
  }

  function logout() {
    window.AuthStorage.clear();
    window.location.replace(window.AppConfig.pages.login);
  }

  function requireAuth(allowedRoles) {
    const accessToken = window.AuthStorage.getAccessToken();
    const user = window.AuthStorage.getUser();

    if (!accessToken || !user) {
      logout();
      return null;
    }

    if (Array.isArray(allowedRoles) && allowedRoles.length > 0 && !allowedRoles.includes(user.role)) {
      window.location.replace(getHomePage(user));
      return null;
    }

    return user;
  }

  window.Auth = {
    getHomePage,
    login,
    logout,
    requireAuth
  };
})();
