document.addEventListener("DOMContentLoaded", async function () {
  const user = window.Auth.requireAuth(["staff"]);
  if (!user) {
    return;
  }

  if (!user.is_admin) {
    window.location.replace("./Staff_Dashboard.html");
    return;
  }

  bindShell();
  bindResetForm();
  await loadLastLogin();
});

function bindShell() {
  const logoutLink = document.querySelector(".logout");
  if (logoutLink) {
    logoutLink.addEventListener("click", function (event) {
      event.preventDefault();
      window.Auth.logout();
    });
  }

  document.querySelectorAll(".dropdown-toggle").forEach(function (item) {
    item.addEventListener("click", function () {
      const parent = this.parentElement;
      document.querySelectorAll(".dropdown").forEach(function (dropdown) {
        if (dropdown !== parent) {
          dropdown.classList.remove("active");
        }
      });
      parent.classList.toggle("active");
    });
  });
}

function bindResetForm() {
  const form = document.getElementById("resetForm");
  if (!form) {
    return;
  }

  form.addEventListener("submit", async function (event) {
    event.preventDefault();

    const loginInput = document.getElementById("resetLogin");
    const newPasswordInput = document.getElementById("resetNewPassword");
    const confirmPasswordInput = document.getElementById("resetConfirmPassword");
    const submitBtn = document.getElementById("resetSubmitBtn");
    const status = document.getElementById("resetStatus");

    const login = String(loginInput?.value || "").trim();
    const newPassword = String(newPasswordInput?.value || "");
    const confirmPassword = String(confirmPasswordInput?.value || "");

    if (!login) {
      setStatus(status, "Username or email is required.", true);
      return;
    }
    if (newPassword.length < 8) {
      setStatus(status, "New password must be at least 8 characters.", true);
      return;
    }
    if (newPassword !== confirmPassword) {
      setStatus(status, "Password confirmation does not match.", true);
      return;
    }

    if (submitBtn) {
      submitBtn.disabled = true;
    }

    try {
      const response = await window.ApiClient.post("user", "resetPassword", {
        login: login,
        new_password: newPassword
      });

      setStatus(
        status,
        response.message || "Password reset successfully. Existing sessions have been invalidated.",
        false
      );
      form.reset();
    } catch (error) {
      setStatus(status, error.message || "Failed to reset password.", true);
    } finally {
      if (submitBtn) {
        submitBtn.disabled = false;
      }
    }
  });

  const toggle = document.getElementById("toggleResetPasswords");
  if (toggle) {
    toggle.addEventListener("change", function () {
      const newPasswordInput = document.getElementById("resetNewPassword");
      const confirmPasswordInput = document.getElementById("resetConfirmPassword");
      const type = toggle.checked ? "text" : "password";

      if (newPasswordInput) {
        newPasswordInput.type = type;
      }
      if (confirmPasswordInput) {
        confirmPasswordInput.type = type;
      }
    });
  }
}

function setStatus(node, message, isError) {
  if (!node) {
    if (window.Toast?.show && message) {
      window.Toast.show(message, isError ? "error" : "success");
    }
    return;
  }

  node.textContent = message || "";
  node.className = `text-sm mt-2 ${isError ? "text-red-500" : "text-green-600"}`;
  if (window.Toast?.show && message) {
    window.Toast.show(message, isError ? "error" : "success");
  }
}

async function loadLastLogin() {
  const target = document.getElementById("lastLoginValue");
  if (!target) {
    return;
  }

  try {
    const response = await window.ApiClient.get("dashboard", "lastLogin");
    target.textContent = formatDateTime(response.data?.last_login) || "N/A";
  } catch (error) {
    target.textContent = "N/A";
  }
}

function formatDateTime(value) {
  if (!value) {
    return "";
  }

  const date = new Date(value);
  if (Number.isNaN(date.getTime())) {
    return value;
  }

  return date.toLocaleString("en-GB", {
    day: "2-digit",
    month: "short",
    year: "numeric",
    hour: "2-digit",
    minute: "2-digit",
    hour12: false
  });
}
