document.addEventListener("DOMContentLoaded", async function () {
  const user = window.Auth.requireAuth(["staff"]);
  if (!user) {
    return;
  }

  bindStaffShell();
  bindRoleVisibility();
  bindCreateUserSubmit();
  resetCreateUserForm();
  await loadLastLogin();
});

function bindStaffShell() {
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

function bindRoleVisibility() {
  const roleSelect = document.getElementById("role");
  if (!roleSelect) {
    return;
  }

  roleSelect.addEventListener("change", toggleRoleFields);
  toggleRoleFields();
}

function toggleRoleFields() {
  const role = String(document.getElementById("role")?.value || "");
  const programContainer = document.getElementById("programContainer");
  const staffTypeContainer = document.getElementById("staffTypeContainer");
  const departmentContainer = document.getElementById("departmentContainer");

  if (programContainer) {
    programContainer.classList.add("hidden");
  }
  if (staffTypeContainer) {
    staffTypeContainer.classList.add("hidden");
  }
  if (departmentContainer) {
    departmentContainer.classList.add("hidden");
  }

  if (role === "student") {
    if (programContainer) {
      programContainer.classList.remove("hidden");
    }
  } else if (role === "tutor") {
    if (departmentContainer) {
      departmentContainer.classList.remove("hidden");
    }
  } else if (role === "staff") {
    if (staffTypeContainer) {
      staffTypeContainer.classList.remove("hidden");
    }
    if (departmentContainer) {
      departmentContainer.classList.remove("hidden");
    }
  }
}

function bindCreateUserSubmit() {
  const form = document.getElementById("registerForm");
  if (!form) {
    return;
  }

  form.addEventListener("submit", async function (event) {
    event.preventDefault();
    clearErrors();
    setStatus("", false);

    const payload = buildPayloadFromForm();
    if (!payload) {
      return;
    }

    const submitBtn = document.getElementById("createUserBtn");
    if (submitBtn) {
      submitBtn.disabled = true;
    }

    try {
      const response = await window.ApiClient.post("user", "", payload);
      setStatus(response.message || "User created successfully.", false);
      form.reset();
      toggleRoleFields();
    } catch (error) {
      const handled = showCreateUserError(error);
      if (!handled) {
        setStatus(error.message || "Unable to create user.", true);
      }
    } finally {
      if (submitBtn) {
        submitBtn.disabled = false;
      }
    }
  });
}

function resetCreateUserForm() {
  const form = document.getElementById("registerForm");
  if (!form) {
    return;
  }
  form.reset();
  [
    "name",
    "email",
    "phNum",
    "role",
    "program",
    "staffType",
    "department",
    "password",
    "confirmPassword"
  ].forEach(function (id) {
    const node = document.getElementById(id);
    if (node) {
      node.value = "";
    }
  });
}

function buildPayloadFromForm() {
  const fullName = getValue("name");
  const email = getValue("email");
  const phoneNumber = getValue("phNum");
  const roleName = getValue("role");
  const programme = getValue("program");
  const staffType = getValue("staffType");
  const department = getValue("department");
  const password = getValue("password");
  const confirmPassword = getValue("confirmPassword");

  let valid = true;

  if (!fullName) {
    setError("nameError", "Full name is required.");
    valid = false;
  }

  if (!email || !isEmail(email)) {
    setError("emailError", "Valid email is required.");
    valid = false;
  }

  if (!roleName) {
    setError("roleError", "Role is required.");
    valid = false;
  }

  if (!password || password.length < 8) {
    setError("passwordError", "Password must be at least 8 characters.");
    valid = false;
  }

  if (password !== confirmPassword) {
    setError("confirmPasswordError", "Passwords do not match.");
    valid = false;
  }

  if (roleName === "student" && !programme) {
    setError("programError", "Program is required for student.");
    valid = false;
  }

  if (roleName === "tutor" && !department) {
    setError("departmentError", "Department is required for tutor.");
    valid = false;
  }

  if (roleName === "staff") {
    if (!staffType) {
      setError("staffTypeError", "Staff type is required.");
      valid = false;
    }
    if (!department) {
      setError("departmentError", "Department is required for staff.");
      valid = false;
    }
  }

  if (!valid) {
    return null;
  }

  const userName = buildUserName(fullName, email);
  const payload = {
    user_name: userName,
    email: email,
    password: password,
    role_name: roleName,
    full_name: fullName
  };

  if (phoneNumber) {
    payload.contact_number = phoneNumber;
  }
  if (programme) {
    payload.programme = programme;
  }
  if (department) {
    payload.department = department;
  }
  if (roleName === "staff") {
    payload.is_admin = staffType === "authorised" ? 1 : 0;
  }

  return payload;
}

function buildUserName(fullName, email) {
  const localPart = String(email || "").split("@")[0] || "";
  const normalized = localPart || fullName.toLowerCase().replace(/[^a-z0-9]+/g, "_");
  const clean = normalized.replace(/^_+|_+$/g, "").slice(0, 20) || "user";
  return `${clean}_${Date.now().toString().slice(-5)}`;
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

function clearErrors() {
  [
    "nameError",
    "emailError",
    "phoneError",
    "roleError",
    "programError",
    "staffTypeError",
    "departmentError",
    "passwordError",
    "confirmPasswordError"
  ].forEach(function (id) {
    const node = document.getElementById(id);
    if (node) {
      node.textContent = "";
    }
  });
}

function setError(id, message) {
  const node = document.getElementById(id);
  if (node) {
    node.textContent = message;
  }
}

function showCreateUserError(error) {
  const payload = error?.payload || {};
  const structuredErrors = payload?.errors && typeof payload.errors === "object" ? payload.errors : null;
  const rawMessage = String(payload.message || error?.message || "").trim();
  const message = rawMessage.toLowerCase();
  const field = String(payload.field || "").toLowerCase();

  if (structuredErrors) {
    const fieldIdMap = {
      full_name: "nameError",
      user_name: "nameError",
      email: "emailError",
      contact_number: "phoneError",
      phone_number: "phoneError",
      role_name: "roleError",
      role_id: "roleError",
      programme: "programError",
      department: "departmentError",
      is_admin: "staffTypeError",
      password: "passwordError",
      confirm_password: "confirmPasswordError"
    };

    let applied = false;
    Object.entries(structuredErrors).forEach(function ([key, value]) {
      const target = fieldIdMap[String(key || "").toLowerCase()];
      const text = String(value || "").trim();
      if (target && text) {
        setError(target, text);
        applied = true;
      }
    });

    if (applied) {
      setStatus(rawMessage || "Please correct the highlighted fields.", true);
      return true;
    }
  }

  if (field === "email" || message.includes("email already exists")) {
    setError("emailError", "This email already exists.");
    setStatus("Create user failed: this email is already in use.", true);
    return true;
  }

  if (field === "user_name" || message.includes("username already exists")) {
    setError("nameError", "This username already exists.");
    setStatus("Create user failed: generated username already exists. Please try again.", true);
    return true;
  }

  if (message.includes("role_id or role_name is required") || message.includes("invalid role")) {
    setError("roleError", "Please choose a valid role.");
    setStatus("Create user failed: role is missing or invalid.", true);
    return true;
  }

  if (message.includes("password must be at least 8")) {
    setError("passwordError", "Password must be at least 8 characters.");
    setStatus("Create user failed: password is too short.", true);
    return true;
  }

  if (message.includes("valid email") || message.includes("invalid email")) {
    setError("emailError", "Please enter a valid email address.");
    setStatus("Create user failed: email format is invalid.", true);
    return true;
  }

  if (error?.status === 409 || message.includes("already exists")) {
    setStatus(rawMessage || "Create user failed: this account already exists.", true);
    return true;
  }

  if (error?.status === 400) {
    setStatus(rawMessage || "Create user failed: please check required fields.", true);
    return true;
  }

  return false;
}

function setStatus(message, isError) {
  const node = document.getElementById("createUserStatus");
  if (!node) {
    return;
  }
  node.textContent = message;
  node.className = `text-sm mb-2 ${isError ? "text-red-500" : "text-green-600"}`;
}

function getValue(id) {
  const node = document.getElementById(id);
  return node ? String(node.value || "").trim() : "";
}

function isEmail(value) {
  return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value);
}

function formatDate(value) {
  if (!value) {
    return "";
  }

  const date = new Date(value);
  if (Number.isNaN(date.getTime())) {
    return value;
  }

  return date.toLocaleDateString("en-GB", {
    day: "2-digit",
    month: "2-digit",
    year: "2-digit"
  });
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
