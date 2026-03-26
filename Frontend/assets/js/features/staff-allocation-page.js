document.addEventListener("DOMContentLoaded", async function () {
  const user = window.Auth.requireAuth(["staff"]);
  if (!user) {
    return;
  }

  bindStaffShell();
  bindAllocationActions();

  await Promise.allSettled([
    loadLastLogin(),
    loadAllocationData()
  ]);
});

const allocationState = {
  students: [],
  tutors: [],
  activeByStudentId: new Map(),
  selectedStudentId: 0,
  selectedStudentIds: [],
  page: 1,
  pageSize: 10
};
const MAX_BULK_SELECTION = 10;

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

function bindAllocationActions() {
  const searchInput = document.getElementById("searchInput");
  const filterSelect = document.getElementById("filterProgram");
  const cancelBtn = document.getElementById("closeAssignModalBtn");
  const confirmBtn = document.getElementById("confirmAssignBtn");
  const modal = document.getElementById("assignModal");
  const openBulkBtn = document.getElementById("openAssignModalBtn");
  const selectAll = document.getElementById("selectAllStudents");

  if (searchInput) {
    searchInput.addEventListener("input", function () {
      allocationState.page = 1;
      renderStudents();
    });
  }
  if (filterSelect) {
    filterSelect.addEventListener("change", function () {
      allocationState.page = 1;
      renderStudents();
    });
  }

  if (cancelBtn) {
    cancelBtn.addEventListener("click", closeModal);
  }
  if (confirmBtn) {
    confirmBtn.addEventListener("click", confirmAssign);
  }
  if (openBulkBtn) {
    openBulkBtn.addEventListener("click", function () {
      if (!allocationState.selectedStudentIds.length) {
        setStatus("Please select at least one student.", true);
        return;
      }
      openModal(0, allocationState.selectedStudentIds.slice());
    });
  }
  if (selectAll) {
    selectAll.addEventListener("change", function () {
      const checked = !!selectAll.checked;
      const visibleStudentIds = getVisibleStudents().map(function (s) {
        return Number(s.student_id || 0);
      }).filter(Boolean);

      allocationState.selectedStudentIds = checked ? visibleStudentIds.slice(0, MAX_BULK_SELECTION) : [];
      if (checked && visibleStudentIds.length > MAX_BULK_SELECTION) {
        setStatus(`Bulk allocation is limited to ${MAX_BULK_SELECTION} students at one time.`, true);
      }
      renderStudents();
    });
  }

  if (modal) {
    modal.addEventListener("click", function (event) {
      if (event.target === modal) {
        closeModal();
      }
    });
  }

  const table = document.getElementById("studentTable");
  if (table) {
    table.addEventListener("change", function (event) {
      const checkbox = event.target.closest("[data-select-student-id]");
      if (!checkbox) {
        return;
      }
      const studentId = Number(checkbox.dataset.selectStudentId || 0);
      if (!studentId) {
        return;
      }

      if (checkbox.checked) {
        if (allocationState.selectedStudentIds.length >= MAX_BULK_SELECTION) {
          checkbox.checked = false;
          setStatus(`You can select maximum ${MAX_BULK_SELECTION} students for bulk allocation.`, true);
          return;
        }
        if (!allocationState.selectedStudentIds.includes(studentId)) {
          allocationState.selectedStudentIds.push(studentId);
        }
      } else {
        allocationState.selectedStudentIds = allocationState.selectedStudentIds.filter(function (id) {
          return id !== studentId;
        });
      }
      updateBulkButtonVisibility();
      syncSelectAllCheckbox();
    });

    table.addEventListener("click", function (event) {
      const assignBtn = event.target.closest("[data-assign-student-id]");
      if (!assignBtn) {
        return;
      }
      const studentId = Number(assignBtn.dataset.assignStudentId || 0);
      if (!studentId) {
        return;
      }
      openModal(studentId, []);
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

async function loadAllocationData() {
  const table = document.getElementById("studentTable");
  if (table) {
    table.innerHTML = '<tr><td colspan="4">Loading...</td></tr>';
  }

  try {
    const [usersResponse, allocationsResponse] = await Promise.all([
      window.ApiClient.get("user", "", { limit: 100, offset: 0 }),
      window.ApiClient.get("allocation")
    ]);

    const users = Array.isArray(usersResponse.data) ? usersResponse.data : [];
    const allocations = Array.isArray(allocationsResponse.data) ? allocationsResponse.data : [];

    allocationState.students = users.filter(function (user) {
      return String(user.role_name || "").toLowerCase() === "student" && Number(user.student_id || 0) > 0;
    });
    allocationState.tutors = users.filter(function (user) {
      return String(user.role_name || "").toLowerCase() === "tutor" && Number(user.tutor_id || 0) > 0;
    });

    allocationState.activeByStudentId = new Map();
    allocations
      .filter(function (allocation) { return String(allocation.status || "").toLowerCase() === "active"; })
      .forEach(function (allocation) {
        allocationState.activeByStudentId.set(Number(allocation.student_id), Number(allocation.tutor_id));
      });

    populateTutorSelect();
    allocationState.page = 1;
    renderStudents();
    updateBulkButtonVisibility();
    syncSelectAllCheckbox();
  } catch (error) {
    if (table) {
      table.innerHTML = `<tr><td colspan="4">${escapeHtml(error.message || "Unable to load allocations.")}</td></tr>`;
    }
    setStatus(error.message || "Unable to load allocations.", true);
  }
}

function renderStudents() {
  const table = document.getElementById("studentTable");
  if (!table) {
    return;
  }

  const search = String(document.getElementById("searchInput")?.value || "").trim().toLowerCase();
  const filter = String(document.getElementById("filterProgram")?.value || "all").toLowerCase();

  const filtered = getVisibleStudents();

  if (!filtered.length) {
    table.innerHTML = '<tr><td colspan="5">No unallocated students found.</td></tr>';
    renderAllocationPagination(0);
    updateBulkButtonVisibility();
    syncSelectAllCheckbox();
    return;
  }

  const totalPages = Math.max(1, Math.ceil(filtered.length / allocationState.pageSize));
  if (allocationState.page > totalPages) {
    allocationState.page = totalPages;
  }
  const start = (allocationState.page - 1) * allocationState.pageSize;
  const pageRows = filtered.slice(start, start + allocationState.pageSize);

  table.innerHTML = pageRows.map(function (student, index) {
    const studentId = Number(student.student_id || 0);
    const isChecked = allocationState.selectedStudentIds.includes(studentId);
    const activeTutorId = allocationState.activeByStudentId.get(studentId) || 0;
    const tutor = allocationState.tutors.find(function (item) {
      return Number(item.tutor_id) === activeTutorId;
    });
    const tutorName = tutor ? (tutor.full_name || tutor.user_name || "Assigned Tutor") : "Not assigned";

    return `
      <tr>
        <td>
          <input type="checkbox" data-select-student-id="${studentId}" ${isChecked ? "checked" : ""} aria-label="Select student ${escapeHtml(student.full_name || student.user_name || "Student")}">
        </td>
        <td class="student-name">
          <img src="${student.profile_photo ? resolveAssetUrl(student.profile_photo) : getAvatarFromName(student.full_name || student.user_name || "Student")}" class="student-avatar" alt="Avatar">
          ${escapeHtml(student.full_name || student.user_name || "Student")}
        </td>
        <td>${escapeHtml(student.programme || "N/A")}</td>
        <td>${escapeHtml(tutorName)}</td>
        <td>
          <button class="assign-btn" type="button" data-assign-student-id="${studentId}">
            Assign
          </button>
        </td>
      </tr>
    `;
  }).join("");
  renderAllocationPagination(totalPages);

  updateBulkButtonVisibility();
  syncSelectAllCheckbox();
}

function renderAllocationPagination(totalPages) {
  const table = document.getElementById("studentTable");
  if (!table) {
    return;
  }
  const wrapper = table.closest(".table-container") || table.closest("table");
  if (!wrapper) {
    return;
  }
  const hostId = "allocationPagination";
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
    <button type="button" id="allocationPrevPageBtn" class="px-3 py-1 rounded border border-gray-300 bg-white text-sm">Prev</button>
    <span class="text-sm text-gray-600">Page ${allocationState.page} / ${totalPages}</span>
    <button type="button" id="allocationNextPageBtn" class="px-3 py-1 rounded border border-gray-300 bg-white text-sm">Next</button>
  `;
  const prev = document.getElementById("allocationPrevPageBtn");
  const next = document.getElementById("allocationNextPageBtn");
  if (prev) {
    prev.disabled = allocationState.page <= 1;
    prev.addEventListener("click", function () {
      if (allocationState.page <= 1) {
        return;
      }
      allocationState.page -= 1;
      renderStudents();
    });
  }
  if (next) {
    next.disabled = allocationState.page >= totalPages;
    next.addEventListener("click", function () {
      if (allocationState.page >= totalPages) {
        return;
      }
      allocationState.page += 1;
      renderStudents();
    });
  }
}

function getVisibleStudents() {
  const search = String(document.getElementById("searchInput")?.value || "").trim().toLowerCase();
  const filter = String(document.getElementById("filterProgram")?.value || "all").toLowerCase();

  return allocationState.students.filter(function (student) {
    const studentId = Number(student.student_id || 0);
    if (allocationState.activeByStudentId.has(studentId)) {
      return false;
    }

    const name = String(student.full_name || student.user_name || "").toLowerCase();
    const programme = String(student.programme || "").toLowerCase();
    if (search && !name.includes(search) && !programme.includes(search)) {
      return false;
    }
    if (filter !== "all" && programme !== filter) {
      return false;
    }
    return true;
  });
}

function updateBulkButtonVisibility() {
  const openBulkBtn = document.getElementById("openAssignModalBtn");
  if (!openBulkBtn) {
    return;
  }

  if (allocationState.selectedStudentIds.length > 0) {
    openBulkBtn.classList.remove("hidden");
    openBulkBtn.textContent = `Assign Selected (${allocationState.selectedStudentIds.length}/${MAX_BULK_SELECTION})`;
  } else {
    openBulkBtn.classList.add("hidden");
    openBulkBtn.textContent = "Assign Selected";
  }
}

function syncSelectAllCheckbox() {
  const selectAll = document.getElementById("selectAllStudents");
  if (!selectAll) {
    return;
  }
  const visibleStudentIds = getVisibleStudents().map(function (s) {
    return Number(s.student_id || 0);
  }).filter(Boolean);
  if (!visibleStudentIds.length) {
    selectAll.checked = false;
    return;
  }
  selectAll.checked = visibleStudentIds.every(function (id) {
    return allocationState.selectedStudentIds.includes(id);
  });
}

function populateTutorSelect() {
  const select = document.getElementById("tutorSelect");
  if (!select) {
    return;
  }

  select.innerHTML = '<option value="">-- Choose Tutor --</option>';
  allocationState.tutors.forEach(function (tutor) {
    const option = document.createElement("option");
    option.value = String(tutor.tutor_id);
    option.textContent = tutor.full_name || tutor.user_name || `Tutor ${tutor.tutor_id}`;
    select.appendChild(option);
  });
}

function openModal(studentId, studentIds) {
  const modal = document.getElementById("assignModal");
  if (!modal) {
    return;
  }

  const selectedIds = Array.isArray(studentIds) && studentIds.length
    ? studentIds.filter(Boolean)
    : [Number(studentId || 0)].filter(Boolean);
  if (!selectedIds.length) {
    return;
  }

  allocationState.selectedStudentIds = selectedIds.slice();
  allocationState.selectedStudentId = selectedIds.length === 1 ? selectedIds[0] : 0;

  const modalName = document.getElementById("modalName");
  const modalProgram = document.getElementById("modalProgram");
  const selectedStudentsList = document.getElementById("selectedStudentsList");
  const tutorSelect = document.getElementById("tutorSelect");

  if (selectedIds.length === 1) {
    const selectedStudent = allocationState.students.find(function (item) {
      return Number(item.student_id) === selectedIds[0];
    });
    if (!selectedStudent) {
      return;
    }
    if (modalName) modalName.textContent = selectedStudent.full_name || selectedStudent.user_name || "Student";
    if (modalProgram) modalProgram.textContent = selectedStudent.programme || "N/A";
    if (selectedStudentsList) {
      selectedStudentsList.classList.add("hidden");
      selectedStudentsList.innerHTML = "";
    }
    if (tutorSelect) {
      const activeTutorId = allocationState.activeByStudentId.get(selectedIds[0]) || 0;
      tutorSelect.value = activeTutorId ? String(activeTutorId) : "";
    }
  } else {
    if (modalName) modalName.textContent = `${selectedIds.length} students selected`;
    if (modalProgram) modalProgram.textContent = "Multiple programmes";
    if (selectedStudentsList) {
      selectedStudentsList.classList.remove("hidden");
      selectedStudentsList.innerHTML = selectedIds.map(function (id) {
        const student = allocationState.students.find(function (item) {
          return Number(item.student_id) === id;
        });
        return `<div>${escapeHtml(student?.full_name || student?.user_name || `Student ${id}`)}</div>`;
      }).join("");
    }
    if (tutorSelect) {
      tutorSelect.value = "";
    }
  }

  modal.classList.remove("hidden");
  updateBulkButtonVisibility();
  syncSelectAllCheckbox();
}

function closeModal() {
  const modal = document.getElementById("assignModal");
  if (modal) {
    modal.classList.add("hidden");
  }
}

async function confirmAssign() {
  const select = document.getElementById("tutorSelect");
  const selectedTutorId = Number(select?.value || 0);
  const selectedIds = allocationState.selectedStudentIds.length
    ? allocationState.selectedStudentIds.slice()
    : [Number(allocationState.selectedStudentId || 0)].filter(Boolean);

  if (!selectedIds.length) {
    setStatus("Please select at least one student first.", true);
    return;
  }
  if (selectedIds.length > MAX_BULK_SELECTION) {
    setStatus(`Bulk allocation supports maximum ${MAX_BULK_SELECTION} students at one time.`, true);
    return;
  }
  if (!selectedTutorId) {
    setStatus("Please select a tutor.", true);
    return;
  }

  const confirmBtn = document.getElementById("confirmAssignBtn");
  if (confirmBtn) {
    confirmBtn.disabled = true;
  }

  try {
    const createItems = [];
    const alreadyAssigned = [];

    selectedIds.forEach(function (studentId) {
      const currentTutorId = allocationState.activeByStudentId.get(studentId) || 0;
      if (!currentTutorId) {
        createItems.push({ student_id: studentId, tutor_id: selectedTutorId });
      } else {
        alreadyAssigned.push(studentId);
      }
    });

    if (!createItems.length) {
      setStatus("Selected students already have active tutor allocation.", true);
      return;
    }

    let createCount = 0;

    if (createItems.length === 1) {
      await window.ApiClient.post("allocation", "", {
        student_id: createItems[0].student_id,
        tutor_id: createItems[0].tutor_id,
        status: "active"
      });
      createCount += 1;
    } else if (createItems.length > 1) {
      await window.ApiClient.post("allocation", "bulk", {
        status: "active",
        allocations: createItems
      });
      createCount += createItems.length;
    }

    setStatus(`Done. Allocated: ${createCount}, Skipped (already assigned): ${alreadyAssigned.length}.`, false);

    allocationState.selectedStudentIds = [];
    allocationState.selectedStudentId = 0;
    closeModal();
    await loadAllocationData();
  } catch (error) {
    setStatus(error.message || "Unable to save allocation.", true);
  } finally {
    if (confirmBtn) {
      confirmBtn.disabled = false;
    }
  }
}

function setStatus(message, isError) {
  const node = document.getElementById("allocationStatus");
  if (!node) {
    return;
  }

  node.textContent = message;
  node.className = `text-sm mt-2 ${isError ? "text-red-500" : "text-green-600"}`;
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
