(function () {
  const appOrigin = window.location.origin;
  const pathname = window.location.pathname || "";
  const frontendIndex = pathname.toLowerCase().indexOf("/frontend/");
  const projectBase = frontendIndex > 0 ? pathname.slice(0, frontendIndex) : "/eTutor";
  const frontendBase = `${appOrigin}${projectBase}/Frontend`;
  const apiBaseUrl = `${appOrigin}${projectBase}/Backend/api/index.php`;

  window.AppConfig = {
    origin: appOrigin,
    projectBase,
    frontendBase,
    apiBaseUrl,
    pages: {
      login: `${frontendBase}/Pages/Auth/Login.html`,
      studentDashboard: `${frontendBase}/Pages/Student/Student_Dashboard.html`,
      tutorDashboard: `${frontendBase}/Pages/Tutor/Tutor_Dashboard.html`,
      staffDashboard: `${frontendBase}/Pages/Staff/Staff_Dashboard.html`
    }
  };
})();
