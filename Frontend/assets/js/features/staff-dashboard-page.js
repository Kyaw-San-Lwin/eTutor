document.addEventListener("DOMContentLoaded", async function () {
  const user = window.Auth.requireAuth(["staff"]);
  if (!user) {
    return;
  }

  bindStaffShell();
  setHeaderUser(user);
  renderAdminMenu(user);
  initCharts();
  bindChartRangeChange(user);

  await Promise.allSettled([
    loadLastLogin(),
    loadDashboardMetrics(),
    loadRecentActivity(),
    loadLiveChartData(user)
  ]);
});

let messageChartInstance = null;
let tutorChartInstance = null;

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

function initCharts() {
  const messageCanvas = document.getElementById("messageChart");
  if (messageCanvas && typeof Chart !== "undefined") {
    const messageCtx = messageCanvas.getContext("2d");
    messageChartInstance = new Chart(messageCtx, {
      type: "line",
      data: {
        labels: [],
        datasets: [{
          label: "Activities",
          data: [],
          tension: 0.35,
          fill: false,
          borderWidth: 2,
          pointRadius: 3
        }]
      },
      options: {
        responsive: true,
        plugins: { legend: { display: true } },
        scales: { y: { beginAtZero: true } }
      }
    });
  }

  const tutorCanvas = document.getElementById("tutorChart");
  if (tutorCanvas && typeof Chart !== "undefined") {
    const tutorCtx = tutorCanvas.getContext("2d");
    tutorChartInstance = new Chart(tutorCtx, {
      type: "bar",
      data: {
        labels: [],
        datasets: [{
          label: "Actions",
          data: [],
          borderWidth: 1
        }]
      },
      options: {
        responsive: true,
        plugins: { legend: { display: false } },
        scales: { y: { beginAtZero: true } }
      }
    });
  }
}

async function loadLiveChartData(user) {
  if (!messageChartInstance || !tutorChartInstance) {
    return;
  }

  if (!user || !user.is_admin) {
    updateCharts(
      getLast7DayLabels(),
      new Array(7).fill(0),
      ["Admin access required"],
      [0]
    );
    return;
  }

  try {
    const days = getSelectedChartRangeDays();
    const [trendResponse, activeUsersResponse] = await Promise.all([
      window.ApiClient.get("report", "activityTrend", { days: days }),
      window.ApiClient.get("report", "activeUsers", { days: days })
    ]);

    const trendRows = Array.isArray(trendResponse.data) ? trendResponse.data : [];
    const dateRange = buildDateRange(days);
    const dayLabels = dateRange.map(function (item) { return item.label; });
    const dayMap = new Map(dateRange.map(function (item) {
      return [item.key, 0];
    }));

    trendRows.forEach(function (row) {
      const day = row.day ? new Date(row.day) : null;
      if (day && !Number.isNaN(day.getTime())) {
        const key = day.toISOString().slice(0, 10);
        if (dayMap.has(key)) {
          dayMap.set(key, Number(row.total || 0));
        }
      }
    });

    const lineValues = dateRange.map(function (item) {
      return Number(dayMap.get(item.key) || 0);
    });

    const activeUsers = Array.isArray(activeUsersResponse.data) ? activeUsersResponse.data : [];
    const barLabels = activeUsers.length
      ? activeUsers.map(function (entry) { return String(entry.user_name || "Unknown"); })
      : ["No data"];
    const barValues = activeUsers.length
      ? activeUsers.map(function (entry) { return Number(entry.actions || 0); })
      : [0];

    updateCharts(dayLabels, lineValues, barLabels, barValues);
    setActiveUsersChartTitle(days);
  } catch (error) {
    updateCharts(
      buildDateRange(7).map(function (item) { return item.label; }),
      new Array(7).fill(0),
      ["No data"],
      [0]
    );
    setActiveUsersChartTitle(getSelectedChartRangeDays());
  }
}

function updateCharts(lineLabels, lineValues, barLabels, barValues) {
  if (messageChartInstance) {
    messageChartInstance.data.labels = lineLabels;
    messageChartInstance.data.datasets[0].data = lineValues;
    messageChartInstance.update();
  }

  if (tutorChartInstance) {
    tutorChartInstance.data.labels = barLabels;
    tutorChartInstance.data.datasets[0].data = barValues;
    tutorChartInstance.update();
  }
}

function buildDateRange(days) {
  const range = [];
  const safeDays = Number(days) > 0 ? Number(days) : 7;
  for (let i = safeDays - 1; i >= 0; i--) {
    const day = new Date();
    day.setDate(day.getDate() - i);
    const key = day.toISOString().slice(0, 10);
    const label = day.toLocaleDateString("en-GB", { day: "2-digit", month: "short" });
    range.push({ key: key, label: label });
  }
  return range;
}

function getSelectedChartRangeDays() {
  const select = document.getElementById("chartRangeDays");
  const value = Number(select?.value || 7);
  if (!Number.isFinite(value) || value <= 0) {
    return 7;
  }
  return value;
}

function bindChartRangeChange(user) {
  const select = document.getElementById("chartRangeDays");
  if (!select) {
    return;
  }

  select.addEventListener("change", function () {
    loadLiveChartData(user);
  });
}

function setActiveUsersChartTitle(days) {
  const title = document.getElementById("activeUsersChartTitle");
  if (title) {
    title.textContent = `Most Active Users (Last ${days} Days)`;
  }
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
      <a href="./Activity_Logs.html">
        <i class="bi bi-clock-history"></i>
        <span class="menu-text">Activity Logs</span>
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

    setMetric("metricTotalUsers", metrics.total_users);
    setMetric("metricActiveTutors", metrics.total_tutors);
    setMetric("metricActiveStudents", metrics.total_students);
    setMetric("metricMessages7", metrics.messages_last_7_days ?? metrics.unread_messages);
  } catch (error) {
    setMetric("metricTotalUsers", "N/A");
    setMetric("metricActiveTutors", "N/A");
    setMetric("metricActiveStudents", "N/A");
    setMetric("metricMessages7", "N/A");
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
