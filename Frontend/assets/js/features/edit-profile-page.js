document.addEventListener("DOMContentLoaded", async function () {
  const mode = document.body.dataset.profileMode || "";
  if (!mode) {
    return;
  }

  const allowedRoles = mode === "tutor-edit" ? ["tutor"] : ["student"];
  const user = window.Auth.requireAuth(allowedRoles);
  if (!user) {
    return;
  }

  bindLogout();
  bindImagePreview();
  await Promise.allSettled([
    loadLastLogin(),
    loadCurrentProfile()
  ]);
});

function bindLogout() {
  const logoutLink = document.querySelector(".logout");
  if (logoutLink) {
    logoutLink.addEventListener("click", function (event) {
      event.preventDefault();
      window.Auth.logout();
    });
  }
}

function bindImagePreview() {
  const input = document.getElementById("uploadImage");
  const preview = document.getElementById("profilePreview");
  const icon = document.getElementById("defaultIcon");

  if (!input || !preview || !icon) {
    return;
  }

  input.addEventListener("change", function () {
    const file = input.files && input.files[0];
    if (!file) {
      return;
    }

    const reader = new FileReader();
    reader.onload = function (event) {
      preview.src = event.target?.result || "";
      preview.style.display = "block";
      icon.style.display = "none";
    };
    reader.readAsDataURL(file);
  });
}

async function loadLastLogin() {
  const target = document.getElementById("lastLoginValue");
  if (!target) {
    return;
  }

  try {
    const response = await window.ApiClient.get("dashboard", "lastLogin");
    target.textContent = formatDate(response.data?.last_login) || "N/A";
  } catch (error) {
    target.textContent = "N/A";
  }
}

async function loadCurrentProfile() {
  try {
    const response = await window.ApiClient.get("user", "me");
    const data = response.data || {};
    const profile = data.profile || {};

    setValue("profileEmail", data.email || "");
    setValue("profilePhone", profile.contact_number || "");
  } catch (error) {
    const note = document.getElementById("profileEditError");
    if (note) {
      note.textContent = error.message || "Unable to load profile.";
    }
  }
}

function setValue(id, value) {
  const element = document.getElementById(id);
  if (element) {
    element.value = value;
  }
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
