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
  const loginError = document.getElementById("loginError");
  const usernameEmailError = document.getElementById("loginUsernameEmailError");
  const passwordError = document.getElementById("loginPasswordError");

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
      const user = await window.Auth.login(loginValue, password);
      window.location.replace(window.Auth.getHomePage(user));
    } catch (error) {
      loginError.textContent = error.message || "Login failed.";
    }
  });
});
