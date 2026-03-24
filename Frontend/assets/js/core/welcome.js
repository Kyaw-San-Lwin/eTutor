(function () {
  function getWelcomeKey() {
    const user = window.AuthStorage?.getUser?.() || {};
    const userId = user.id || user.user_id || "guest";
    return `etutor_show_welcome_${userId}`;
  }

  function closeWelcomeModal() {
    const modal = document.getElementById("welcomeModal");
    if (modal) {
      modal.classList.add("hidden");
    }
  }

  function initDashboardWelcome() {
    const modal = document.getElementById("welcomeModal");
    if (!modal) {
      return;
    }

    const welcomeKey = getWelcomeKey();
    if (sessionStorage.getItem(welcomeKey) === "1") {
      setTimeout(function () {
        modal.classList.remove("hidden");
      }, 300);
      sessionStorage.removeItem(welcomeKey);
    }

    modal.addEventListener("click", function (event) {
      if (event.target === modal) {
        closeWelcomeModal();
      }
    });
  }

  window.closeWelcomeModal = closeWelcomeModal;
  window.initDashboardWelcome = initDashboardWelcome;
})();

