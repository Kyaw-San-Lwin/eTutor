document.addEventListener("DOMContentLoaded", async function () {
  const user = window.Auth.requireAuth(["staff"]);
  if (!user) {
    return;
  }

  bindShell();
  await loadLastLogin();

  if (!user.is_admin) {
    setStatus("Admin permission is required to view exception reports.", true);
    return;
  }

  await loadExceptions();
});

function bindShell() {
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
    target.textContent = formatDateTime(response.data?.last_login) || "N/A";
  } catch (error) {
    target.textContent = "N/A";
  }
}

async function loadExceptions() {
  try {
    const response = await window.ApiClient.get("report", "exceptions");
    const data = response.data || {};

    renderList("studentsWithoutTutorList", data.students_without_personal_tutor, function (row) {
      const name = row.user_name || `User #${row.user_id || row.student_id}`;
      return `${escapeHtml(name)} (${escapeHtml(row.email || "No email")})`;
    });

    renderList("inactive7DaysList", data.inactive_7_days, function (row) {
      return `${escapeHtml(row.student_name || `Student #${row.student_id}`)} - ${Number(row.days_inactive || 0)} day(s) inactive`;
    });

    renderList("inactive28DaysList", data.inactive_28_days, function (row) {
      return `${escapeHtml(row.student_name || `Student #${row.student_id}`)} - ${Number(row.days_inactive || 0)} day(s) inactive`;
    });

    setStatus("Exception report loaded.", false);
  } catch (error) {
    setStatus(error.message || "Unable to load exception report.", true);
  }
}

function renderList(id, items, formatter) {
  const node = document.getElementById(id);
  if (!node) {
    return;
  }

  const list = Array.isArray(items) ? items : [];
  if (!list.length) {
    node.innerHTML = '<li class="text-gray-500">No records.</li>';
    return;
  }

  node.innerHTML = list.map(function (item) {
    return `<li class="py-1 border-b border-gray-100">${formatter(item)}</li>`;
  }).join("");
}

function setStatus(message, isError) {
  const node = document.getElementById("reportStatus");
  if (!node) {
    return;
  }
  node.textContent = message;
  node.className = `text-sm mb-4 ${isError ? "text-red-500" : "text-green-600"}`;
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

function escapeHtml(value) {
  return String(value ?? "")
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#39;");
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
