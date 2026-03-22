document.addEventListener("DOMContentLoaded", function () {
  const user = window.Auth.requireAuth(["staff"]);
  if (!user) {
    return;
  }

  const isAdmin = Boolean(user.is_admin);
  const isAdminPage = document.body.dataset.adminPage === "1";

  if (isAdminPage && !isAdmin) {
    window.location.replace("./Staff_Dashboard.html");
    return;
  }

  document.querySelectorAll("[data-admin-only]").forEach(function (element) {
    if (!isAdmin) {
      element.remove();
    }
  });
});
