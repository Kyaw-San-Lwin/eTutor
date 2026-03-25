document.addEventListener("DOMContentLoaded", async function () {
  const mode = document.body.dataset.profileMode || "";
  if (!mode) {
    return;
  }

  const roleMap = {
    "student-self": ["student"],
    "student-tutor": ["student"],
    "tutor-self": ["tutor"],
    "staff-self": ["staff"]
  };
  const allowedRoles = roleMap[mode] || [];
  const user = window.Auth.requireAuth(allowedRoles);
  if (!user) {
    return;
  }

  bindLogout();
  bindDropdowns();
  await loadLastLogin();

  if (mode === "student-self" || mode === "tutor-self" || mode === "staff-self") {
    await loadSelfProfile();
    return;
  }

  if (mode === "student-tutor") {
    await loadAllocatedTutorProfile();
  }
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

function bindDropdowns() {
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

async function loadSelfProfile() {
  const mode = document.body.dataset.profileMode || "";

  try {
    const response = await window.ApiClient.get("user", "me");
    const data = response.data || {};
    const profile = data.profile || {};

    setText("profileName", profile.full_name || profile.display_name || data.full_name || data.user_name || "N/A");
    const secondary = mode === "staff-self"
      ? (data.user_name || "N/A")
      : (profile.contact_number || "N/A");
    setText("profilePhone", secondary);
    setText("profileEmail", data.email || "N/A");
    setProfileImage(profile.profile_photo || "");

    const programmeValue = profile.programme || "N/A";
    const departmentValue = profile.department || "N/A";
    const userNameValue = data.user_name || "N/A";

    if (mode === "student-self") {
      setText("profileThirdValue", programmeValue);
    } else if (mode === "tutor-self") {
      setText("profileThirdValue", userNameValue);
      setText("profileDepartment", departmentValue);
    } else {
      setText("profileThirdValue", departmentValue);
    }
  } catch (error) {
    renderProfileError(error.message || "Unable to load profile.");
  }
}

async function loadAllocatedTutorProfile() {
  try {
    const response = await window.ApiClient.get("allocation", "myTutor");
    const data = response.data || null;

    if (!data) {
      setText("profileName", "No tutor allocated");
      setText("profilePhone", "N/A");
      setText("profileEmail", "N/A");
      setText("profileThirdValue", "N/A");
      setText("profileDepartment", "N/A");
      return;
    }

    setText("profileName", data.tutor_full_name || data.tutor_user_name || "Allocated Tutor");
    setText("profilePhone", data.tutor_contact_number || "N/A");
    setText("profileEmail", data.tutor_email || "N/A");
    setText("profileThirdValue", data.tutor_user_name || "N/A");
    setText("profileDepartment", data.tutor_department || "N/A");
    setProfileImage(data.tutor_profile_photo || "");
  } catch (error) {
    renderProfileError(error.message || "Unable to load tutor profile.");
  }
}

function renderProfileError(message) {
  setText("profileName", "Profile unavailable");
  setText("profilePhone", "N/A");
  setText("profileEmail", "N/A");
  setText("profileThirdValue", "N/A");
  setText("profileDepartment", "N/A");
  const note = document.getElementById("profileError");
  if (note) {
    note.textContent = message;
  }
  setProfileImage("");
}

function setProfileImage(path) {
  const image = document.getElementById("profileImage");
  if (!image) {
    return;
  }

  const name = document.getElementById("profileName")?.textContent || "User";
  image.src = path ? resolveAssetUrl(path) : getAvatarFromName(name);
}

function setText(id, value) {
  const element = document.getElementById(id);
  if (element) {
    element.textContent = value;
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

function resolveAssetUrl(path) {
  if (!path) {
    return "";
  }

  if (/^https?:\/\//i.test(path)) {
    return path;
  }

  const base = window.AppConfig.projectBase || "";
  if (path.startsWith(base + "/")) {
    return `${window.AppConfig.origin}${path}`;
  }

  if (path.startsWith("/")) {
    return `${window.AppConfig.origin}${base}${path}`;
  }

  return path;
}

function getAvatarFromName(name) {
  const safeName = String(name || "User").trim() || "User";
  const initials = safeName
    .split(/\s+/)
    .slice(0, 2)
    .map(function (part) { return part.charAt(0).toUpperCase(); })
    .join("") || "U";
  const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="180" height="180"><rect width="100%" height="100%" fill="#1d4ed8"/><text x="50%" y="52%" dominant-baseline="middle" text-anchor="middle" font-family="Arial" font-size="64" fill="#ffffff">${initials}</text></svg>`;
  return `data:image/svg+xml;utf8,${encodeURIComponent(svg)}`;
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
