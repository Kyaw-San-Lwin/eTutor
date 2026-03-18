(function () {
  const marker = "/Frontend/";
  const path = window.location.pathname;
  const markerIndex = path.indexOf(marker);
  const projectBase = markerIndex >= 0 ? path.slice(0, markerIndex) : "";
  const origin = window.location.origin;

  window.AppConfig = {
    origin,
    projectBase,
    apiBaseUrl: `${origin}${projectBase}/Backend/api/index.php`,
    pages: {
      login: `${origin}${projectBase}/Frontend/Pages/Auth/Login.html`,
      studentDashboard: `${origin}${projectBase}/Frontend/Pages/Student/Student_Dashboard.html`,
      tutorDashboard: `${origin}${projectBase}/Frontend/Pages/Tutor/Tutor_Dashboard.html`,
      staffDashboard: `${origin}${projectBase}/Frontend/Pages/Staff/Staff_Dashboard.html`
    }
  };
})();
