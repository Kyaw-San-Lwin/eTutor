document.addEventListener("DOMContentLoaded", async function () {
  const user = window.Auth.requireAuth(["staff"]);
  if (!user) {
    return;
  }

  bindStaffShell();
  setHeaderUser(user);
  renderAdminMenu(user);

  await Promise.allSettled([
    loadLastLogin(),
    loadDashboardMetrics(),
    loadRecentActivity()
  ]);
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

function renderAdminMenu(user) {
  const placeholder = document.getElementById("admin-reports-placeholder");
  if (!placeholder) {
    return;
  }

  if (!user || !user.is_admin) {
    placeholder.innerHTML = "";
    return;
  }

  const wrapper = document.createElement("div");
  wrapper.className = "dropdown";
  wrapper.innerHTML = `
    <div class="nav-item dropdown-toggle">
      <i class="bi bi-file-bar-graph"></i>
      <span class="menu-text">Reports</span>
      <i class="bi bi-chevron-down arrow"></i>
    </div>
    <div class="dropdown-menu">
      <a href="./Exception_Report.html">
        <i class="bi bi-exclamation-triangle"></i>
        <span class="menu-text">Exception Report</span>
      </a>
      <a href="./Statistical_Report.html">
        <i class="bi bi-bar-chart-line"></i>
        <span class="menu-text">Statistical Report</span>
      </a>
    </div>
  `;

  placeholder.replaceWith(wrapper);
}

function setHeaderUser(user) {
  const nameNode = document.getElementById("staffHeaderName");
  const roleNode = document.getElementById("staffHeaderRole");

  if (nameNode) {
    nameNode.textContent = user.user_name || "Staff User";
  }
  if (roleNode) {
    roleNode.textContent = user.is_admin ? "Admin Staff" : "Staff";
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

async function loadDashboardMetrics() {
  try {
    const response = await window.ApiClient.get("dashboard");
    const metrics = response.data?.metrics || {};

    setMetric("metricActiveTutors", metrics.total_tutors);
    setMetric("metricActiveStudents", metrics.total_students);
    setMetric("metricMessages7", metrics.messages_last_7_days ?? metrics.unread_messages);
    setMetric("metricMeetings", metrics.scheduled_meetings);
  } catch (error) {
    setMetric("metricActiveTutors", "N/A");
    setMetric("metricActiveStudents", "N/A");
    setMetric("metricMessages7", "N/A");
    setMetric("metricMeetings", "N/A");
  }
}

async function loadRecentActivity() {
  const tableBody = document.getElementById("recentActivityBody");
  if (!tableBody) {
    return;
  }

  tableBody.innerHTML = '<tr><td colspan="3" class="py-3 text-gray-500">Loading...</td></tr>';

  try {
    const response = await window.ApiClient.get("report", "activityLogs", { limit: 6, offset: 0 });
    const items = response.data?.items || [];

    if (!items.length) {
      tableBody.innerHTML = '<tr><td colspan="3" class="py-3 text-gray-500">No recent activity.</td></tr>';
      return;
    }

    tableBody.innerHTML = items.map(function (item) {
      return `
        <tr>
          <td class="py-3">${escapeHtml(item.user_name || `User #${item.user_id || "N/A"}`)}</td>
          <td>${escapeHtml(item.activity_type || "Activity")}</td>
          <td class="text-gray-500">${escapeHtml(formatDateTime(item.access_time))}</td>
        </tr>
      `;
    }).join("");
  } catch (error) {
    tableBody.innerHTML = '<tr><td colspan="3" class="py-3 text-gray-500">Recent activity is unavailable for this account.</td></tr>';
  }
}

function setMetric(id, value) {
  const node = document.getElementById(id);
  if (node) {
    node.textContent = value == null ? "0" : String(value);
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
    hour: "2-digit",
    minute: "2-digit"
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
