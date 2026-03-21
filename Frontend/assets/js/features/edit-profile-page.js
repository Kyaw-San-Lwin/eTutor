document.addEventListener("DOMContentLoaded", async function () {
  const mode = document.body.dataset.profileMode || "";
  if (!mode) {
    return;
  }

  const roleMap = {
    "student-edit": ["student"],
    "tutor-edit": ["tutor"],
    "staff-edit": ["staff"]
  };
  const allowedRoles = roleMap[mode] || [];
  const user = window.Auth.requireAuth(allowedRoles);
  if (!user) {
    return;
  }

  bindLogout();
  bindDropdowns();
  bindImagePreview();
  bindSaveActions();
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

    const validationError = validatePhotoFile(file);
    if (validationError) {
      input.value = "";
      preview.removeAttribute("src");
      preview.style.display = "none";
      icon.style.display = "flex";
      setMessage("profileEditMessage", validationError, true);
      return;
    }

    const reader = new FileReader();
    reader.onload = function (event) {
      preview.src = event.target?.result || "";
      preview.style.display = "block";
      icon.style.display = "none";
      setMessage("profileEditMessage", "", false);
    };
    reader.readAsDataURL(file);
  });
}

function bindSaveActions() {
  const profileSaveBtn = document.getElementById("profileSaveBtn");
  const passwordSaveBtn = document.getElementById("passwordSaveBtn");

  if (profileSaveBtn) {
    profileSaveBtn.addEventListener("click", saveProfile);
  }

  if (passwordSaveBtn) {
    passwordSaveBtn.addEventListener("click", changePassword);
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

async function loadCurrentProfile() {
  const mode = document.body.dataset.profileMode || "";

  try {
    const response = await window.ApiClient.get("user", "me");
    const data = response.data || {};
    const profile = data.profile || {};

    setValue("profileEmail", data.email || "");
    if (mode === "staff-edit") {
      setValue("profileFullName", profile.display_name || "");
      setValue("profileDepartmentInput", profile.department || "");
    } else {
      setValue("profilePhone", profile.contact_number || "");
    }
    setProfilePreview(profile.profile_photo || "");
    setMessage("profileEditMessage", "", false);
  } catch (error) {
    setMessage("profileEditMessage", error.message || "Unable to load profile.", true);
  }
}

async function saveProfile() {
  const mode = document.body.dataset.profileMode || "";
  const button = document.getElementById("profileSaveBtn");
  const phoneInput = document.getElementById("profilePhone");
  const phoneNumber = phoneInput ? phoneInput.value.trim() : "";
  const fullName = getValue("profileFullName").trim();
  const department = getValue("profileDepartmentInput").trim();
  const fileInput = document.getElementById("uploadImage");
  const selectedFile = fileInput && fileInput.files ? fileInput.files[0] : null;

  setMessage("profileEditMessage", "", false);

  if (mode === "staff-edit") {
    if (!fullName) {
      setMessage("profileEditMessage", "Full name is required.", true);
      return;
    }
  } else if (!phoneNumber) {
    setMessage("profileEditMessage", "Phone number is required.", true);
    return;
  }

  if (selectedFile) {
    const validationError = validatePhotoFile(selectedFile);
    if (validationError) {
      setMessage("profileEditMessage", validationError, true);
      return;
    }
  }

  if (button) {
    button.disabled = true;
  }

  try {
    const payload = mode === "staff-edit"
      ? {
          full_name: fullName,
          department
        }
      : {
          contact_number: phoneNumber
        };
    const response = await window.ApiClient.put("user", "updateMe", payload);

    let photoUpdated = false;
    let photoError = "";
    if (selectedFile) {
      try {
        const formData = new FormData();
        formData.append("file", selectedFile);

        const photoResponse = await window.ApiClient.request({
          controller: "user",
          action: "uploadMyPhoto",
          method: "POST",
          body: formData
        });

        setProfilePreview(photoResponse.data?.profile_photo || "");
        if (fileInput) {
          fileInput.value = "";
        }
        photoUpdated = true;
      } catch (photoUploadError) {
        photoError = photoUploadError.message || "Photo upload failed.";
      }
    }

    if (mode === "staff-edit") {
      setValue("profileFullName", response.data?.full_name || fullName);
      setValue("profileDepartmentInput", response.data?.department || department);
    } else {
      setValue("profilePhone", response.data?.contact_number || phoneNumber);
    }
    if (photoError) {
      setMessage("profileEditMessage", `Profile updated, but ${photoError}`, true);
      return;
    }

    const successMessage = photoUpdated
      ? "Profile and photo updated successfully."
      : (response.message || "Profile updated successfully.");
    setMessage("profileEditMessage", successMessage, false);
  } catch (error) {
    setMessage("profileEditMessage", error.message || "Unable to update profile.", true);
  } finally {
    if (button) {
      button.disabled = false;
    }
  }
}

async function changePassword() {
  const button = document.getElementById("passwordSaveBtn");
  const currentPassword = getValue("currentPassword").trim();
  const newPassword = getValue("newPassword").trim();
  const confirmPassword = getValue("confirmPassword").trim();

  setMessage("passwordChangeMessage", "", false);

  if (!currentPassword || !newPassword || !confirmPassword) {
    setMessage("passwordChangeMessage", "All password fields are required.", true);
    return;
  }

  if (newPassword.length < 8) {
    setMessage("passwordChangeMessage", "New password must be at least 8 characters.", true);
    return;
  }

  if (newPassword !== confirmPassword) {
    setMessage("passwordChangeMessage", "New password and confirm password do not match.", true);
    return;
  }

  if (button) {
    button.disabled = true;
  }

  try {
    const response = await window.ApiClient.post("user", "changeMyPassword", {
      old_password: currentPassword,
      new_password: newPassword
    });

    setValue("currentPassword", "");
    setValue("newPassword", "");
    setValue("confirmPassword", "");
    setMessage("passwordChangeMessage", response.message || "Password changed successfully.", false);
  } catch (error) {
    setMessage("passwordChangeMessage", error.message || "Unable to change password.", true);
  } finally {
    if (button) {
      button.disabled = false;
    }
  }
}

function setValue(id, value) {
  const element = document.getElementById(id);
  if (element) {
    element.value = value;
  }
}

function setProfilePreview(path) {
  const preview = document.getElementById("profilePreview");
  const icon = document.getElementById("defaultIcon");
  if (!preview || !icon) {
    return;
  }

  if (!path) {
    preview.removeAttribute("src");
    preview.style.display = "none";
    icon.style.display = "flex";
    return;
  }

  preview.src = resolveAssetUrl(path);
  preview.style.display = "block";
  icon.style.display = "none";
}

function getValue(id) {
  const element = document.getElementById(id);
  return element ? element.value : "";
}

function setMessage(id, message, isError) {
  const element = document.getElementById(id);
  if (!element) {
    return;
  }

  element.textContent = message;
  element.className = `text-sm mt-2 ${isError ? "text-red-500" : "text-green-600"}`;
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

function validatePhotoFile(file) {
  if (!file) {
    return "";
  }

  const allowedTypes = ["image/jpeg", "image/png", "image/webp"];
  if (!allowedTypes.includes(file.type)) {
    return "Only JPG, PNG, or WEBP images are allowed.";
  }

  if (file.size > 5 * 1024 * 1024) {
    return "Photo too large. Maximum size is 5MB.";
  }

  return "";
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
