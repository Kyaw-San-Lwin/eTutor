document.addEventListener("DOMContentLoaded", async function () {
  const user = window.Auth.requireAuth(["staff"]);
  if (!user) {
    return;
  }

  bindShell();
  updateGeneratedTime();
  await loadLastLogin();

  if (!user.is_admin) {
    setStatus("Admin permission is required to view statistical reports.", true);
    return;
  }

  await Promise.allSettled([
    loadStatistics(),
    loadActivityLogs()
  ]);
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

async function loadStatistics() {
  try {
    const response = await window.ApiClient.get("report", "statistics");
    const data = response.data || {};

    setText("messagesLast7Days", data.messages_last_7_days ?? 0);
    setText("avgMessagesPerTutor", data.average_messages_per_tutor ?? 0);
    setText("activeAllocations", data.active_allocations ?? 0);
    setText("scheduledMeetings", data.scheduled_meetings ?? 0);
    setStatus("Statistical report loaded.", false);
  } catch (error) {
    setStatus(error.message || "Unable to load statistics.", true);
  }
}

async function loadActivityLogs() {
  const body = document.getElementById("activityRows");
  if (!body) {
    return;
  }

  try {
    const response = await window.ApiClient.get("report", "activityLogs", { limit: 10, offset: 0 });
    const rows = response.data?.items || [];

    if (!rows.length) {
      body.innerHTML = '<tr><td colspan="4" class="py-2 text-gray-500">No recent activity logs.</td></tr>';
      return;
    }

    body.innerHTML = rows.map(function (row) {
      return `
        <tr>
          <td class="py-2">${escapeHtml(row.full_name || row.display_name || row.user_name || `User #${row.user_id || "N/A"}`)}</td>
          <td>${escapeHtml(row.activity_type || "Activity")}</td>
          <td>${escapeHtml(row.page_visited || "N/A")}</td>
          <td>${escapeHtml(formatDateTime(row.access_time))}</td>
        </tr>
      `;
    }).join("");
  } catch (error) {
    body.innerHTML = '<tr><td colspan="4" class="py-2 text-red-500">Unable to load activity logs.</td></tr>';
  }
}

function setText(id, value) {
  const node = document.getElementById(id);
  if (node) {
    node.textContent = String(value ?? 0);
  }
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

function escapeHtml(value) {
  return String(value ?? "")
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#39;");
}
