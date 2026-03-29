document.addEventListener("DOMContentLoaded", function () {
  const existingUser = window.AuthStorage.getUser();
  const existingToken = window.AuthStorage.getAccessToken();

  if (existingUser && existingToken) {
    window.location.replace(window.Auth.getHomePage(existingUser));
    return;
  }

  const form = document.getElementById("loginForm");
  const loginInput = document.getElementById("loginUsernameEmail");
  const passwordInput = document.getElementById("loginPassword");
  const submitBtn = document.getElementById("loginSubmitBtn")
    || (form ? (form.querySelector('button[type="submit"]') || form.querySelector("button")) : null);
  const loginError = document.getElementById("loginError");
  const usernameEmailError = document.getElementById("loginUsernameEmailError");
  const passwordError = document.getElementById("loginPasswordError");
  let lockTimerId = null;

  restoreLockCountdown();
  const lockUiWatchId = setInterval(function () {
    syncLockUiState();
  }, 1000);
  document.addEventListener("visibilitychange", function () {
    if (!document.hidden) {
      syncLockUiState();
    }
  });

  form.addEventListener("submit", async function (event) {
    event.preventDefault();

    const loginValue = loginInput.value.trim();
    const password = passwordInput.value.trim();

    usernameEmailError.textContent = "";
    passwordError.textContent = "";
    loginError.textContent = "";

    let valid = true;

    if (!loginValue) {
      usernameEmailError.textContent = "Username or email is required.";
      valid = false;
    }

    if (!password) {
      passwordError.textContent = "Password is required.";
      valid = false;
    }

    if (!valid) {
      return;
    }

    try {
      if (submitBtn) {
        submitBtn.disabled = true;
      }
      const user = await window.Auth.login(loginValue, password);
      window.location.replace(window.Auth.getHomePage(user));
    } catch (error) {
      if (error && Number(error.status) === 429) {
        const retryAfter = Number(error?.payload?.data?.retry_after_seconds || 0);
        const lockedUntil = String(error?.payload?.data?.locked_until || "");
        const fallback = String(error.message || "Too many failed attempts. Please wait 1 minute before retrying.");
        if (retryAfter > 0) {
          startLockCountdown(retryAfter, fallback, lockedUntil);
        } else {
          loginError.textContent = fallback;
        }
      } else {
        loginError.textContent = error.message || "Login failed.";
      }
    } finally {
      if (submitBtn && !isCurrentlyLocked()) {
        submitBtn.disabled = false;
      }
    }
  });

  function isCurrentlyLocked() {
    const stored = Number(sessionStorage.getItem("etutor_login_lock_until_ts") || 0);
    return Number.isFinite(stored) && stored > Date.now();
  }

  function syncLockUiState() {
    const stored = Number(sessionStorage.getItem("etutor_login_lock_until_ts") || 0);
    const remainingRaw = Number.isFinite(stored) ? Math.ceil((stored - Date.now()) / 1000) : 0;
    if (remainingRaw > 70) {
      // Safety guard: remove stale lock data from older buggy values.
      sessionStorage.removeItem("etutor_login_lock_until_ts");
      if (submitBtn) {
        submitBtn.disabled = false;
      }
      if (lockTimerId) {
        clearInterval(lockTimerId);
        lockTimerId = null;
      }
      return;
    }

    if (!Number.isFinite(stored) || stored <= Date.now()) {
      sessionStorage.removeItem("etutor_login_lock_until_ts");
      if (submitBtn) {
        submitBtn.disabled = false;
      }
      if (lockTimerId) {
        clearInterval(lockTimerId);
        lockTimerId = null;
      }
      return;
    }

    const remaining = Math.max(0, remainingRaw);
    const mm = String(Math.floor(remaining / 60)).padStart(2, "0");
    const ss = String(remaining % 60).padStart(2, "0");
    if (submitBtn) {
      submitBtn.disabled = true;
    }
    if (loginError) {
      loginError.textContent = `Too many failed attempts. Please wait ${mm}:${ss}.`;
    }
  }

  function restoreLockCountdown() {
    const stored = Number(sessionStorage.getItem("etutor_login_lock_until_ts") || 0);
    if (!Number.isFinite(stored) || stored <= Date.now()) {
      sessionStorage.removeItem("etutor_login_lock_until_ts");
      if (submitBtn) {
        submitBtn.disabled = false;
      }
      return;
    }
    const remaining = Math.ceil((stored - Date.now()) / 1000);
    startLockCountdown(remaining, "", "");
  }

  function startLockCountdown(seconds, fallbackMessage, lockedUntil) {
    if (lockTimerId) {
      clearInterval(lockTimerId);
      lockTimerId = null;
    }

    // Never trust stale/offset timestamps from server; use retry-after seconds and clamp to policy window.
    const safeSeconds = Math.max(1, Math.min(60, Number(seconds) || 60));
    const endTs = Date.now() + safeSeconds * 1000;

    sessionStorage.setItem("etutor_login_lock_until_ts", String(endTs));

    if (submitBtn) {
      submitBtn.disabled = true;
    }

    const tick = function () {
      const remaining = Math.max(0, Math.ceil((endTs - Date.now()) / 1000));
      if (remaining <= 0) {
        loginError.textContent = "";
        sessionStorage.removeItem("etutor_login_lock_until_ts");
        if (submitBtn) {
          submitBtn.disabled = false;
        }
        if (lockTimerId) {
          clearInterval(lockTimerId);
          lockTimerId = null;
        }
        syncLockUiState();
        return;
      }

      const mm = String(Math.floor(remaining / 60)).padStart(2, "0");
      const ss = String(remaining % 60).padStart(2, "0");
      loginError.textContent = `Too many failed attempts. Please wait ${mm}:${ss}.`;
    };

    tick();
    lockTimerId = setInterval(tick, 1000);
  }
});
