document.addEventListener("DOMContentLoaded", async function () {
  const user = window.Auth.requireAuth(["staff"]);
  if (!user) {
    return;
  }

  bindShell();
  updateGeneratedTime();
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

  const printBtn = document.getElementById("printReportBtn");
  if (printBtn) {
    printBtn.addEventListener("click", function () {
      window.print();
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

    const noTutor = Array.isArray(data.students_without_personal_tutor) ? data.students_without_personal_tutor : [];
    const inactive7 = Array.isArray(data.inactive_7_days) ? data.inactive_7_days : [];
    const inactive28 = Array.isArray(data.inactive_28_days) ? data.inactive_28_days : [];

    const inactive28StudentIds = new Set(
      inactive28.map(function (row) { return Number(row.student_id || 0); })
    );
    const lowInactive = inactive7.filter(function (row) {
      return !inactive28StudentIds.has(Number(row.student_id || 0));
    });

    setText("highSeverityCount", noTutor.length);
    setText("mediumSeverityCount", inactive28.length);
    setText("lowSeverityCount", lowInactive.length);

    renderExceptionTable(noTutor, inactive28, lowInactive);

    setStatus("Exception report loaded.", false);
  } catch (error) {
    setStatus(error.message || "Unable to load exception report.", true);
    renderExceptionTable([], [], []);
  }
}

function renderExceptionTable(noTutor, inactive28, lowInactive) {
  const tbody = document.getElementById("exceptionRows");
  if (!tbody) {
    return;
  }

  const rows = [];

  noTutor.forEach(function (row) {
    const name = row.user_name || `User #${row.user_id || row.student_id || "N/A"}`;
    rows.push({
      type: "Unallocated Student",
      detail: `${name}<br>${escapeHtml(row.email || "No email")}`,
      severity: "high",
      date: formatDateTime(new Date().toISOString())
    });
  });

  inactive28.forEach(function (row) {
    const studentName = escapeHtml(row.student_name || `Student #${row.student_id || "N/A"}`);
    const tutorName = escapeHtml(row.tutor_name || `Tutor #${row.tutor_id || "N/A"}`);
    const days = Number(row.days_inactive || getDaysInactive(row.last_interaction_at) || 0);
    rows.push({
      type: "No Recent Interaction",
      detail: `${studentName}<br>Tutor: ${tutorName}<br>Inactive: ${days} day(s)`,
      severity: "medium",
      date: formatDateTime(row.last_interaction_at || "")
    });
  });

  lowInactive.forEach(function (row) {
    const studentName = escapeHtml(row.student_name || `Student #${row.student_id || "N/A"}`);
    const tutorName = escapeHtml(row.tutor_name || `Tutor #${row.tutor_id || "N/A"}`);
    const days = Number(row.days_inactive || getDaysInactive(row.last_interaction_at) || 0);
    rows.push({
      type: "Potential Inactivity",
      detail: `${studentName}<br>Tutor: ${tutorName}<br>Inactive: ${days} day(s)`,
      severity: "low",
      date: formatDateTime(row.last_interaction_at || "")
    });
  });

  if (!rows.length) {
    tbody.innerHTML = '<tr><td colspan="4" class="text-gray-500">No exception records found.</td></tr>';
    return;
  }

  tbody.innerHTML = rows.map(function (row) {
    const badgeClass = row.severity === "high" ? "high" : (row.severity === "medium" ? "medium" : "low");
    const badgeText = row.severity === "high" ? "High" : (row.severity === "medium" ? "Medium" : "Low");
    return `
      <tr>
        <td>${escapeHtml(row.type)}</td>
        <td>${row.detail}</td>
        <td><span class="badge ${badgeClass}">${badgeText}</span></td>
        <td>${escapeHtml(row.date || "N/A")}</td>
      </tr>
    `;
  }).join("");
}

function setText(id, value) {
  const node = document.getElementById(id);
  if (!node) {
    return;
  }

  node.textContent = String(value ?? 0);
}

function setStatus(message, isError) {
  const node = document.getElementById("reportStatus");
  if (!node) {
    return;
  }
  node.textContent = message;
  node.className = `text-sm mb-4 ${isError ? "text-red-500" : "text-green-600"}`;
}

function updateGeneratedTime() {
  const target = document.getElementById("reportGeneratedAt");
  if (!target) {
    return;
  }
  target.textContent = formatDateTime(new Date().toISOString());
}

function getDaysInactive(lastInteractionAt) {
  if (!lastInteractionAt) {
    return 0;
  }

  const date = new Date(lastInteractionAt);
  if (Number.isNaN(date.getTime())) {
    return 0;
  }

  const diffMs = Date.now() - date.getTime();
  return Math.max(0, Math.floor(diffMs / (24 * 60 * 60 * 1000)));
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
