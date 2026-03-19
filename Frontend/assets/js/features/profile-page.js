document.addEventListener("DOMContentLoaded", async function () {
  const mode = document.body.dataset.profileMode || "";
  if (!mode) {
    return;
  }

  const allowedRoles = mode === "tutor-self" ? ["tutor"] : ["student"];
  const user = window.Auth.requireAuth(allowedRoles);
  if (!user) {
    return;
  }

  bindLogout();
  await loadLastLogin();

  if (mode === "student-self" || mode === "tutor-self") {
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

async function loadSelfProfile() {
  try {
    const response = await window.ApiClient.get("user", "me");
    const data = response.data || {};
    const profile = data.profile || {};

    setText("profileName", profile.display_name || data.user_name || "N/A");
    setText("profilePhone", profile.contact_number || "N/A");
    setText("profileEmail", data.email || "N/A");

    const programmeValue = profile.programme || "N/A";
    const departmentValue = profile.department || "N/A";
    const userNameValue = data.user_name || "N/A";

    if (document.body.dataset.profileMode === "student-self") {
      setText("profileThirdValue", programmeValue);
    } else {
      setText("profileThirdValue", userNameValue);
      setText("profileDepartment", departmentValue);
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
