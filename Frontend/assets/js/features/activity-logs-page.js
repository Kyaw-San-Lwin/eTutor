document.addEventListener("DOMContentLoaded", async function () {
  const user = window.Auth.requireAuth(["staff"]);
  if (!user) {
    return;
  }

  bindShell();
  await loadLastLogin();

  if (!user.is_admin) {
    setStatus("Admin permission is required to view activity logs.", true);
    return;
  }

  await loadActivityLogs();
  bindFilters();
});

const activityLogState = {
  total: 0,
  limit: 20,
  offset: 0
};

function bindShell() {
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

function bindFilters() {
  const filterBtn = document.getElementById("applyFilterBtn");
  if (filterBtn) {
    filterBtn.addEventListener("click", function () {
      loadActivityLogs();
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

async function loadActivityLogs() {
  const tableBody = document.getElementById("activityLogRows");
  if (!tableBody) {
    return;
  }

  tableBody.innerHTML = '<tr><td colspan="6" class="py-2 text-gray-500">Loading...</td></tr>';

  const query = {
    limit: Number(document.getElementById("limitInput")?.value || 20),
    offset: Number(document.getElementById("offsetInput")?.value || 0),
    activity_type: String(document.getElementById("activityTypeInput")?.value || "").trim()
  };

  try {
    const response = await window.ApiClient.get("report", "activityLogs", query);
    const data = response.data || {};
    const items = Array.isArray(data.items) ? data.items : [];

    setText("summaryTotal", data.total ?? 0);
    setText("summaryLimit", data.limit ?? query.limit);
    setText("summaryOffset", data.offset ?? query.offset);
    activityLogState.total = Number(data.total ?? 0);
    activityLogState.limit = Number(data.limit ?? query.limit);
    activityLogState.offset = Number(data.offset ?? query.offset);

    if (!items.length) {
      tableBody.innerHTML = '<tr><td colspan="6" class="py-2 text-gray-500">No activity logs found.</td></tr>';
      renderActivityPagination();
      setStatus("No logs for selected filters.", false);
      return;
    }

    tableBody.innerHTML = items.map(function (row) {
      return `
        <tr>
          <td class="py-2">${Number(row.log_id || 0)}</td>
          <td>${escapeHtml(row.full_name || row.display_name || row.user_name || `User #${row.user_id || "N/A"}`)}</td>
          <td>${escapeHtml(row.activity_type || "Activity")}</td>
          <td>${escapeHtml(row.page_visited || "N/A")}</td>
          <td>${escapeHtml(row.ip_address || "N/A")}</td>
          <td>${escapeHtml(formatDateTime(row.access_time))}</td>
        </tr>
      `;
    }).join("");

    setStatus("Activity logs loaded.", false);
    renderActivityPagination();
  } catch (error) {
    tableBody.innerHTML = '<tr><td colspan="6" class="py-2 text-red-500">Unable to load activity logs.</td></tr>';
    setStatus(error.message || "Unable to load activity logs.", true);
  }
}

function renderActivityPagination() {
  const tableBody = document.getElementById("activityLogRows");
  if (!tableBody) {
    return;
  }
  const wrapper = tableBody.closest(".table-container") || tableBody.closest("table");
  if (!wrapper) {
    return;
  }
  const hostId = "activityLogPagination";
  let host = document.getElementById(hostId);
  if (!host) {
    host = document.createElement("div");
    host.id = hostId;
    host.className = "flex items-center justify-end gap-3 mt-3";
    wrapper.insertAdjacentElement("afterend", host);
  }
  const totalPages = Math.max(1, Math.ceil(activityLogState.total / Math.max(1, activityLogState.limit)));
  const currentPage = Math.floor(activityLogState.offset / Math.max(1, activityLogState.limit)) + 1;
  if (totalPages <= 1) {
    host.innerHTML = "";
    return;
  }

  host.innerHTML = `
    <button type="button" id="activityLogPrevPageBtn" class="px-3 py-1 rounded border border-gray-300 bg-white text-sm">Prev</button>
    <span class="text-sm text-gray-600">Page ${currentPage} / ${totalPages}</span>
    <button type="button" id="activityLogNextPageBtn" class="px-3 py-1 rounded border border-gray-300 bg-white text-sm">Next</button>
  `;

  const prev = document.getElementById("activityLogPrevPageBtn");
  const next = document.getElementById("activityLogNextPageBtn");
  if (prev) {
    prev.disabled = currentPage <= 1;
    prev.addEventListener("click", function () {
      const newOffset = Math.max(0, activityLogState.offset - activityLogState.limit);
      const offsetInput = document.getElementById("offsetInput");
      if (offsetInput) {
        offsetInput.value = String(newOffset);
      }
      loadActivityLogs();
    });
  }
  if (next) {
    next.disabled = currentPage >= totalPages;
    next.addEventListener("click", function () {
      const newOffset = activityLogState.offset + activityLogState.limit;
      const offsetInput = document.getElementById("offsetInput");
      if (offsetInput) {
        offsetInput.value = String(newOffset);
      }
      loadActivityLogs();
    });
  }
}

function setText(id, value) {
  const node = document.getElementById(id);
  if (node) {
    node.textContent = String(value ?? "");
  }
}

function setStatus(message, isError) {
  const node = document.getElementById("activityLogStatus");
  if (!node) {
    return;
  }
  node.textContent = message;
  node.className = `text-sm mb-3 ${isError ? "text-red-500" : "text-green-600"}`;
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
