document.addEventListener("DOMContentLoaded", async function () {
  const user = window.Auth.requireAuth(["tutor"]);
  if (!user) {
    return;
  }

  bindShell();
  await Promise.allSettled([
    loadLastLogin(),
    loadAssignedStudents()
  ]);
});

const tutorStudentState = {
  students: [],
  page: 1,
  pageSize: 10
};

function bindShell() {
  const logoutLink = document.querySelector(".logout");
  if (logoutLink) {
    logoutLink.addEventListener("click", function (event) {
      event.preventDefault();
      window.Auth.logout();
    });
  }

  const searchInput = document.getElementById("searchInput");
  const filterSelect = document.getElementById("filterProgram");

  if (searchInput) {
    searchInput.addEventListener("input", function () {
      tutorStudentState.page = 1;
      renderStudents();
    });
  }
  if (filterSelect) {
    filterSelect.addEventListener("change", function () {
      tutorStudentState.page = 1;
      renderStudents();
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

async function loadAssignedStudents() {
  const table = document.getElementById("studentTable");
  if (table) {
    table.innerHTML = '<tr><td colspan="4">Loading...</td></tr>';
  }

  try {
    const response = await window.ApiClient.get("dashboard", "tutorTutees", {
      limit: 100,
      offset: 0,
      sort_by: "last_interaction",
      sort_dir: "desc"
    });
    const rows = Array.isArray(response.data) ? response.data : [];

    tutorStudentState.students = rows.map(function (row) {
      return {
        name: row.student_full_name || row.student_user_name || "Student",
        phone: row.student_contact_number || "N/A",
        program: row.student_programme || "N/A",
        email: row.student_email || "N/A",
        image: row.student_profile_photo
          ? resolveAssetUrl(row.student_profile_photo)
          : getAvatarFromName(row.student_full_name || row.student_user_name || "Student"),
        lastInteractionAt: row.last_interaction_at || null,
        unreadMessages: Number(row.unread_messages || 0),
        documentsUploaded: Number(row.documents_uploaded || 0),
        riskLevel: row.risk_level || "normal"
      };
    });
    tutorStudentState.page = 1;

    populateProgramFilter();
    renderStudents();
    setStatus("", false);
  } catch (error) {
    if (table) {
      table.innerHTML = `<tr><td colspan="4">${escapeHtml(error.message || "Unable to load students.")}</td></tr>`;
    }
    setStatus(error.message || "Unable to load assigned students.", true);
  }
}

function populateProgramFilter() {
  const select = document.getElementById("filterProgram");
  if (!select) {
    return;
  }

  const programs = new Set();
  tutorStudentState.students.forEach(function (student) {
    if (student.program && student.program !== "N/A") {
      programs.add(student.program);
    }
  });

  select.innerHTML = '<option value="all">All Programs</option>';
  Array.from(programs).sort().forEach(function (program) {
    const option = document.createElement("option");
    option.value = program.toLowerCase();
    option.textContent = program;
    select.appendChild(option);
  });
}

function renderStudents() {
  const table = document.getElementById("studentTable");
  if (!table) {
    return;
  }

  const search = String(document.getElementById("searchInput")?.value || "").trim().toLowerCase();
  const filter = String(document.getElementById("filterProgram")?.value || "all").toLowerCase();

  const filtered = tutorStudentState.students.filter(function (student) {
    const name = student.name.toLowerCase();
    const program = student.program.toLowerCase();
    const email = student.email.toLowerCase();

    const matchesSearch = !search
      || name.includes(search)
      || program.includes(search)
      || email.includes(search);
    if (!matchesSearch) {
      return false;
    }

    if (filter !== "all" && program !== filter) {
      return false;
    }

    return true;
  });

  if (!filtered.length) {
    table.innerHTML = '<tr><td colspan="4">No assigned students found.</td></tr>';
    renderTutorStudentPagination(0);
    return;
  }

  const totalPages = Math.max(1, Math.ceil(filtered.length / tutorStudentState.pageSize));
  if (tutorStudentState.page > totalPages) {
    tutorStudentState.page = totalPages;
  }
  const start = (tutorStudentState.page - 1) * tutorStudentState.pageSize;
  const pageRows = filtered.slice(start, start + tutorStudentState.pageSize);

  table.innerHTML = pageRows.map(function (student) {
    return `
      <tr>
        <td class="student-name">
          <img src="${escapeHtml(student.image)}" class="student-avatar" alt="Avatar">
          ${escapeHtml(student.name)}
        </td>
        <td>${escapeHtml(student.phone)}</td>
        <td>${escapeHtml(student.program)}</td>
        <td>${escapeHtml(student.email)}</td>
      </tr>
    `;
  }).join("");
  renderTutorStudentPagination(totalPages);
}

function renderTutorStudentPagination(totalPages) {
  const table = document.getElementById("studentTable");
  if (!table) {
    return;
  }
  const wrapper = table.closest(".table-container") || table.closest("table");
  if (!wrapper) {
    return;
  }
  const hostId = "tutorStudentPagination";
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
    <button type="button" id="tutorStudentPrevPageBtn" class="px-3 py-1 rounded border border-gray-300 bg-white text-sm">Prev</button>
    <span class="text-sm text-gray-600">Page ${tutorStudentState.page} / ${totalPages}</span>
    <button type="button" id="tutorStudentNextPageBtn" class="px-3 py-1 rounded border border-gray-300 bg-white text-sm">Next</button>
  `;
  const prev = document.getElementById("tutorStudentPrevPageBtn");
  const next = document.getElementById("tutorStudentNextPageBtn");
  if (prev) {
    prev.disabled = tutorStudentState.page <= 1;
    prev.addEventListener("click", function () {
      if (tutorStudentState.page <= 1) {
        return;
      }
      tutorStudentState.page -= 1;
      renderStudents();
    });
  }
  if (next) {
    next.disabled = tutorStudentState.page >= totalPages;
    next.addEventListener("click", function () {
      if (tutorStudentState.page >= totalPages) {
        return;
      }
      tutorStudentState.page += 1;
      renderStudents();
    });
  }
}

function setStatus(message, isError) {
  const status = document.getElementById("studentListStatus");
  if (!status) {
    return;
  }

  status.textContent = message;
  status.className = `text-sm mt-2 ${isError ? "text-red-500" : "text-green-600"}`;
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
