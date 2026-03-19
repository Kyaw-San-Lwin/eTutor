(function () {
  const protocol = window.location.protocol === "https:" ? "https:" : "http:";
  const appOrigin = `${protocol}//localhost`;
  const projectBase = "/eTutor";
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
