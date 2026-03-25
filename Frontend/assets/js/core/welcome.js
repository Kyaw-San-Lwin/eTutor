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

    const user = window.AuthStorage?.getUser?.() || {};
    const welcomeKey = getWelcomeKey();
    const fromSession = sessionStorage.getItem(welcomeKey) === "1";
    const fromUserFlag = user.is_first_login === true || user.is_first_login === 1 || user.is_first_login === "1";
    const mustChangePassword = user.must_change_password === true || user.must_change_password === 1 || user.must_change_password === "1";

    if (fromSession || fromUserFlag || mustChangePassword) {
      setTimeout(function () {
        modal.classList.remove("hidden");
      }, 300);
      sessionStorage.removeItem(welcomeKey);

      if (fromUserFlag && window.AuthStorage?.setAuth) {
        const nextUser = Object.assign({}, user, { is_first_login: false });
        window.AuthStorage.setAuth({ user: nextUser });
      }
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
