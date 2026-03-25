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
  students: []
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
    searchInput.addEventListener("input", renderStudents);
  }
  if (filterSelect) {
    filterSelect.addEventListener("change", renderStudents);
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
    const response = await window.ApiClient.get("allocation", "assignedStudents");
    const rows = Array.isArray(response.data) ? response.data : [];

    tutorStudentState.students = rows.map(function (row) {
      return {
        name: row.student_full_name || row.student_user_name || "Student",
        phone: row.student_contact_number || "N/A",
        program: row.student_programme || "N/A",
        email: row.student_email || "N/A",
        image: row.student_profile_photo
          ? resolveAssetUrl(row.student_profile_photo)
          : getAvatarFromName(row.student_full_name || row.student_user_name || "Student")
      };
    });

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
    return;
  }

  table.innerHTML = filtered.map(function (student) {
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
