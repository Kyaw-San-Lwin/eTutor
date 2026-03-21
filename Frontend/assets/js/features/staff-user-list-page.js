document.addEventListener("DOMContentLoaded", async function () {
  const roleFilter = document.body.dataset.userListRole || "";
  if (!roleFilter) {
    return;
  }

  const user = window.Auth.requireAuth(["staff"]);
  if (!user) {
    return;
  }

  bindStaffShell();
  setupListLabels(roleFilter);

  await Promise.allSettled([
    loadLastLogin(),
    loadUsers(roleFilter)
  ]);
});

const listState = {
  roleFilter: "",
  users: []
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
    searchInput.addEventListener("input", renderUsers);
  }
  if (filterSelect) {
    filterSelect.addEventListener("change", renderUsers);
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

async function loadUsers(roleFilter) {
  listState.roleFilter = roleFilter;
  const table = document.getElementById("studentTable");
  if (table) {
    table.innerHTML = '<tr><td colspan="4">Loading...</td></tr>';
  }

  try {
    const response = await window.ApiClient.get("user", "", { limit: 500, offset: 0 });
    const rows = Array.isArray(response.data) ? response.data : [];
    listState.users = rows.filter(function (row) {
      return String(row.role_name || "").toLowerCase() === roleFilter;
    });

    populateFilterOptions();
    renderUsers();
  } catch (error) {
    if (table) {
      table.innerHTML = `<tr><td colspan="4">${escapeHtml(error.message || "Unable to load users.")}</td></tr>`;
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
    table.innerHTML = '<tr><td colspan="4">No records found.</td></tr>';
    return;
  }

  table.innerHTML = filteredUsers.map(function (user, index) {
    const displayName = user.full_name || user.user_name || "N/A";
    const phone = user.contact_number || "N/A";
    const category = roleFilter === "tutor"
      ? (user.department || "N/A")
      : (user.programme || "N/A");
    const avatar = index % 2 === 0 ? "../../Images/profile.jpg" : "../../Images/profile 2.jpg";

    return `
      <tr>
        <td class="student-name">
          <img src="${avatar}" class="student-avatar" alt="Avatar">
          ${escapeHtml(displayName)}
        </td>
        <td>${escapeHtml(phone)}</td>
        <td>${escapeHtml(category)}</td>
        <td>${escapeHtml(user.email || "N/A")}</td>
      </tr>
    `;
  }).join("");
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
