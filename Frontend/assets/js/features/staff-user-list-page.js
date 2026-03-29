document.addEventListener("DOMContentLoaded", async function () {
  const roleFilter = document.body.dataset.userListRole || "";
  if (!roleFilter) {
    return;
  }

  const user = window.Auth.requireAuth(["staff"]);
  if (!user) {
    return;
  }

  listState.isAdmin = Boolean(user.is_admin);

  bindStaffShell();
  setupListLabels(roleFilter);
  toggleAdminActions();

  await Promise.allSettled([
    loadLastLogin(),
    loadUsers(roleFilter)
  ]);
});

const listState = {
  roleFilter: "",
  users: [],
  isAdmin: false,
  page: 1,
  pageSize: 10
};

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

  const searchInput = document.getElementById("searchInput");
  const filterSelect = document.getElementById("filterProgram");
  if (searchInput) {
    searchInput.addEventListener("input", function () {
      listState.page = 1;
      renderUsers();
    });
  }
  if (filterSelect) {
    filterSelect.addEventListener("change", function () {
      listState.page = 1;
      renderUsers();
    });
  }
}

function setupListLabels(roleFilter) {
  const programHeader = document.getElementById("programHeader");
  const filterLabel = document.getElementById("filterLabel");
  const searchInput = document.getElementById("searchInput");

  if (roleFilter === "tutor") {
    if (programHeader) {
      programHeader.textContent = "Department";
    }
    if (filterLabel) {
      filterLabel.textContent = "All Departments";
    }
    if (searchInput) {
      searchInput.placeholder = "Search by tutor name or department";
    }
  } else {
    if (programHeader) {
      programHeader.textContent = "Program";
    }
    if (filterLabel) {
      filterLabel.textContent = "All Programs";
    }
    if (searchInput) {
      searchInput.placeholder = "Search by student name or program";
    }
  }
}

function toggleAdminActions() {
  const actionHeader = document.getElementById("adminActionHeader");
  if (!actionHeader) {
    return;
  }

  actionHeader.classList.remove("hidden");
  actionHeader.textContent = "Actions";
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

async function loadUsers(roleFilter) {
  listState.roleFilter = roleFilter;
  const table = document.getElementById("studentTable");
  if (table) {
    table.innerHTML = `<tr><td colspan="5">Loading...</td></tr>`;
  }

  try {
    const response = await window.ApiClient.get("user", "", { limit: 100, offset: 0 });
    const rows = Array.isArray(response.data) ? response.data : [];
    listState.users = rows.filter(function (row) {
      return String(row.role_name || "").toLowerCase() === roleFilter;
    });
    listState.page = 1;

    populateFilterOptions();
    renderUsers();
  } catch (error) {
    if (table) {
      table.innerHTML = `<tr><td colspan="5">${escapeHtml(error.message || "Unable to load users.")}</td></tr>`;
    }
  }
}

function populateFilterOptions() {
  const filterSelect = document.getElementById("filterProgram");
  if (!filterSelect) {
    return;
  }

  const roleFilter = listState.roleFilter;
  const values = new Set();
  listState.users.forEach(function (user) {
    const field = roleFilter === "tutor" ? user.department : user.programme;
    if (field) {
      values.add(String(field));
    }
  });

  filterSelect.innerHTML = "";
  const defaultOption = document.createElement("option");
  defaultOption.value = "all";
  defaultOption.id = "filterLabel";
  defaultOption.textContent = roleFilter === "tutor" ? "All Departments" : "All Programs";
  filterSelect.appendChild(defaultOption);

  Array.from(values).sort().forEach(function (value) {
    const option = document.createElement("option");
    option.value = value.toLowerCase();
    option.textContent = value;
    filterSelect.appendChild(option);
  });
}

function renderUsers() {
  const table = document.getElementById("studentTable");
  if (!table) {
    return;
  }

  const search = String(document.getElementById("searchInput")?.value || "").trim().toLowerCase();
  const selectedFilter = String(document.getElementById("filterProgram")?.value || "all").toLowerCase();
  const roleFilter = listState.roleFilter;

  const filteredUsers = listState.users.filter(function (user) {
    const displayName = (user.full_name || user.user_name || "").toLowerCase();
    const category = String(roleFilter === "tutor" ? (user.department || "") : (user.programme || "")).toLowerCase();
    const email = String(user.email || "").toLowerCase();

    const matchesSearch = !search
      || displayName.includes(search)
      || category.includes(search)
      || email.includes(search);

    if (!matchesSearch) {
      return false;
    }

    if (selectedFilter !== "all" && category !== selectedFilter) {
      return false;
    }

    return true;
  });

  if (!filteredUsers.length) {
    table.innerHTML = `<tr><td colspan="5">No records found.</td></tr>`;
    renderUserPagination(0);
    return;
  }

  const totalPages = Math.max(1, Math.ceil(filteredUsers.length / listState.pageSize));
  if (listState.page > totalPages) {
    listState.page = totalPages;
  }
  const start = (listState.page - 1) * listState.pageSize;
  const pageRows = filteredUsers.slice(start, start + listState.pageSize);

  table.innerHTML = pageRows.map(function (user, index) {
    const displayName = user.full_name || user.user_name || "N/A";
    const phone = user.contact_number || "N/A";
    const category = roleFilter === "tutor"
      ? (user.department || "N/A")
      : (user.programme || "N/A");
    const avatar = user.profile_photo
      ? resolveAssetUrl(user.profile_photo)
      : getAvatarFromName(displayName);
    const viewUrl = buildDashboardViewUrl(Number(user.user_id || 0), roleFilter);

    const actionCell = `
      <td class="actions-cell">
        <div class="action-buttons">
          <a href="${viewUrl}" class="assign-btn">View Dashboard</a>
          ${listState.isAdmin
            ? `<button type="button" class="assign-btn" data-reset-user-id="${Number(user.user_id || 0)}" data-reset-login="${escapeHtml(user.email || user.user_name || "")}">Reset Password</button>`
            : ""}
        </div>
      </td>
    `;

    return `
      <tr>
        <td>
          <div class="student-name">
            <img src="${avatar}" class="student-avatar" alt="Avatar">
            <span class="student-name-text">${escapeHtml(displayName)}</span>
          </div>
        </td>
        <td>${escapeHtml(phone)}</td>
        <td>${escapeHtml(category)}</td>
        <td>${escapeHtml(user.email || "N/A")}</td>
        ${actionCell}
      </tr>
    `;
  }).join("");
  renderUserPagination(totalPages);

  if (listState.isAdmin) {
    table.querySelectorAll("[data-reset-user-id]").forEach(function (button) {
      button.addEventListener("click", function () {
        const login = String(button.getAttribute("data-reset-login") || "").trim();
        goToResetPassword(login);
      });
    });
  }
}

function renderUserPagination(totalPages) {
  const table = document.getElementById("studentTable");
  if (!table) {
    return;
  }
  const wrapper = table.closest(".table-container") || table.closest("table");
  if (!wrapper) {
    return;
  }
  const hostId = "staffUserPagination";
  let host = document.getElementById(hostId);
  if (!host) {
    host = document.createElement("div");
    host.id = hostId;
    host.className = "flex items-center justify-end gap-3 mt-3";
    wrapper.insertAdjacentElement("afterend", host);
  }
  if (totalPages <= 1) {
    host.innerHTML = "";
    return;
  }
  host.innerHTML = `
    <button type="button" id="staffUserPrevPageBtn" class="px-3 py-1 rounded border border-gray-300 bg-white text-sm">Prev</button>
    <span class="text-sm text-gray-600">Page ${listState.page} / ${totalPages}</span>
    <button type="button" id="staffUserNextPageBtn" class="px-3 py-1 rounded border border-gray-300 bg-white text-sm">Next</button>
  `;
  const prev = document.getElementById("staffUserPrevPageBtn");
  const next = document.getElementById("staffUserNextPageBtn");
  if (prev) {
    prev.disabled = listState.page <= 1;
    prev.addEventListener("click", function () {
      if (listState.page <= 1) {
        return;
      }
      listState.page -= 1;
      renderUsers();
    });
  }
  if (next) {
    next.disabled = listState.page >= totalPages;
    next.addEventListener("click", function () {
      if (listState.page >= totalPages) {
        return;
      }
      listState.page += 1;
      renderUsers();
    });
  }
}

function goToResetPassword(login) {
  const params = new URLSearchParams();
  if (login) {
    params.set("login", login);
  }
  window.location.href = `./Reset_Password.html${params.toString() ? `?${params.toString()}` : ""}`;
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

function buildDashboardViewUrl(userId, role) {
  const normalizedRole = String(role || "").toLowerCase();
  const params = new URLSearchParams();
  params.set("view_user_id", String(userId));
  params.set("view_role", normalizedRole);

  if (normalizedRole === "student") {
    return `./Student_Dashboard_View.html?${params.toString()}`;
  }

  if (normalizedRole === "tutor") {
    return `./Tutor_Dashboard_View.html?${params.toString()}`;
  }

  return `./Staff_Dashboard.html?${params.toString()}`;
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
  const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64"><rect width="100%" height="100%" fill="#1d4ed8"/><text x="50%" y="52%" dominant-baseline="middle" text-anchor="middle" font-family="Arial" font-size="24" fill="#ffffff">${initials}</text></svg>`;
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


