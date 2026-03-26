document.addEventListener("DOMContentLoaded", async function () {
  const user = window.Auth.requireAuth(["staff"]);
  if (!user) {
    return;
  }

  bindStaffShell();
  bindReallocationActions();

  await Promise.allSettled([
    loadLastLogin(),
    loadReallocationData()
  ]);
});

const reallocationState = {
  students: [],
  tutors: [],
  activeAllocations: [],
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

function bindReallocationActions() {
  const searchInput = document.getElementById("searchInput");
  const filterSelect = document.getElementById("filterProgram");
  const openBulkBtn = document.getElementById("openReassignModalBtn");
  const selectAll = document.getElementById("selectAllStudents");
  const cancelBtn = document.getElementById("closeReassignModalBtn");
  const confirmBtn = document.getElementById("confirmReassignBtn");
  const modal = document.getElementById("reassignModal");

  if (searchInput) {
    searchInput.addEventListener("input", function () {
      reallocationState.page = 1;
      renderReallocationTable();
    });
  }
  if (filterSelect) {
    filterSelect.addEventListener("change", function () {
      reallocationState.page = 1;
      renderReallocationTable();
    });
  }
  if (openBulkBtn) {
    openBulkBtn.addEventListener("click", function () {
      if (!reallocationState.selectedStudentIds.length) {
        setStatus("Please select at least one student.", true);
        return;
      }
      openModal(0, reallocationState.selectedStudentIds.slice());
    });
  }
  if (selectAll) {
    selectAll.addEventListener("change", function () {
      const checked = !!selectAll.checked;
      const visibleStudentIds = getVisibleAllocations().map(function (allocation) {
        return Number(allocation.student_id || 0);
      }).filter(Boolean);

      reallocationState.selectedStudentIds = checked ? visibleStudentIds.slice(0, MAX_BULK_SELECTION) : [];
      if (checked && visibleStudentIds.length > MAX_BULK_SELECTION) {
        setStatus(`Bulk reallocation is limited to ${MAX_BULK_SELECTION} students at one time.`, true);
      }
      renderReallocationTable();
    });
  }
  if (cancelBtn) {
    cancelBtn.addEventListener("click", closeModal);
  }
  if (confirmBtn) {
    confirmBtn.addEventListener("click", confirmBulkReallocation);
  }
  if (modal) {
    modal.addEventListener("click", function (event) {
      if (event.target === modal) {
        closeModal();
      }
    });
  }

  const table = document.getElementById("reallocationTable");
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
        if (reallocationState.selectedStudentIds.length >= MAX_BULK_SELECTION) {
          checkbox.checked = false;
          setStatus(`You can select maximum ${MAX_BULK_SELECTION} students for bulk reallocation.`, true);
          return;
        }
        if (!reallocationState.selectedStudentIds.includes(studentId)) {
          reallocationState.selectedStudentIds.push(studentId);
        }
      } else {
        reallocationState.selectedStudentIds = reallocationState.selectedStudentIds.filter(function (id) {
          return id !== studentId;
        });
      }

      updateBulkButtonVisibility();
      syncSelectAllCheckbox();
    });

    table.addEventListener("click", async function (event) {
      const button = event.target.closest("[data-reallocate-student-id]");
      if (!button) {
        return;
      }

      const studentId = Number(button.dataset.reallocateStudentId || 0);
      if (!studentId) {
        return;
      }

      const select = document.getElementById(`newTutor-${studentId}`);
      const newTutorId = Number(select?.value || 0);
      if (!newTutorId) {
        setStatus("Please select a new tutor first.", true);
        return;
      }

      button.disabled = true;
      try {
        await window.ApiClient.post("allocation", "reallocate", {
          student_id: studentId,
          new_tutor_id: newTutorId
        });

        setStatus("Tutor reallocated successfully.", false);
        // Refresh in background so UI responds faster.
        loadReallocationData().catch(function () {
          // Keep success state even if refresh fails temporarily.
        });
      } catch (error) {
        setStatus(error.message || "Unable to reallocate tutor.", true);
      } finally {
        button.disabled = false;
      }
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

async function loadReallocationData() {
  const table = document.getElementById("reallocationTable");
  if (table) {
    table.innerHTML = '<tr><td colspan="6">Loading...</td></tr>';
  }

  try {
    const [usersResponse, allocationsResponse] = await Promise.all([
      window.ApiClient.get("user", "", { limit: 100, offset: 0 }),
      window.ApiClient.get("allocation")
    ]);

    const users = Array.isArray(usersResponse.data) ? usersResponse.data : [];
    const allocations = Array.isArray(allocationsResponse.data) ? allocationsResponse.data : [];

    reallocationState.students = users.filter(function (user) {
      return String(user.role_name || "").toLowerCase() === "student" && Number(user.student_id || 0) > 0;
    });
    reallocationState.tutors = users.filter(function (user) {
      return String(user.role_name || "").toLowerCase() === "tutor" && Number(user.tutor_id || 0) > 0;
    });
    reallocationState.activeAllocations = allocations.filter(function (item) {
      return String(item.status || "").toLowerCase() === "active";
    });

    const visibleIds = new Set(getVisibleAllocations().map(function (allocation) {
      return Number(allocation.student_id || 0);
    }).filter(Boolean));
    reallocationState.selectedStudentIds = reallocationState.selectedStudentIds.filter(function (studentId) {
      return visibleIds.has(studentId);
    });

    populateBulkTutorSelect();
    reallocationState.page = 1;
    renderReallocationTable();
    updateBulkButtonVisibility();
    syncSelectAllCheckbox();
  } catch (error) {
    if (table) {
      table.innerHTML = `<tr><td colspan="6">${escapeHtml(error.message || "Unable to load active allocations.")}</td></tr>`;
    }
    setStatus(error.message || "Unable to load reallocation data.", true);
  }
}

function renderReallocationTable() {
  const table = document.getElementById("reallocationTable");
  if (!table) {
    return;
  }

  const rows = getVisibleAllocations();

  if (!rows.length) {
    table.innerHTML = '<tr><td colspan="6">No active allocations found.</td></tr>';
    renderReallocationPagination(0);
    updateBulkButtonVisibility();
    syncSelectAllCheckbox();
    return;
  }

  const totalPages = Math.max(1, Math.ceil(rows.length / reallocationState.pageSize));
  if (reallocationState.page > totalPages) {
    reallocationState.page = totalPages;
  }
  const start = (reallocationState.page - 1) * reallocationState.pageSize;
  const pageRows = rows.slice(start, start + reallocationState.pageSize);

  table.innerHTML = pageRows.map(function (allocation, index) {
    const studentId = Number(allocation.student_id || 0);
    const currentTutorId = Number(allocation.tutor_id || 0);
    const student = getStudentByStudentId(studentId);
    const tutor = getTutorByTutorId(currentTutorId);
    const availableTutors = reallocationState.tutors.filter(function (item) {
      return Number(item.tutor_id) !== currentTutorId;
    });
    const isChecked = reallocationState.selectedStudentIds.includes(studentId);

    return `
      <tr>
        <td>
          <input type="checkbox" data-select-student-id="${studentId}" ${isChecked ? "checked" : ""} aria-label="Select student ${escapeHtml(student?.full_name || student?.user_name || "Student")}">
        </td>
        <td class="student-name">
          <img src="${student?.profile_photo ? resolveAssetUrl(student.profile_photo) : getAvatarFromName(student?.full_name || student?.user_name || `Student ${studentId}`)}" class="student-avatar" alt="Avatar">
          ${escapeHtml(student?.full_name || student?.user_name || `Student ${studentId}`)}
        </td>
        <td>${escapeHtml(student?.programme || "N/A")}</td>
        <td>${escapeHtml(tutor?.full_name || tutor?.user_name || `Tutor ${currentTutorId}`)}</td>
        <td>
          <select id="newTutor-${studentId}" class="w-full max-w-[115px] sm:max-w-[145px] lg:max-w-[170px] p-1 text-xs sm:text-sm border rounded" title="Select new tutor">
            <option value="">-- Select Tutor --</option>
            ${availableTutors.map(function (newTutor) {
              return `<option value="${Number(newTutor.tutor_id)}">${escapeHtml(newTutor.full_name || newTutor.user_name || `Tutor ${newTutor.tutor_id}`)}</option>`;
            }).join("")}
          </select>
        </td>
        <td>
          <button type="button" class="assign-btn" data-reallocate-student-id="${studentId}">Reallocate</button>
        </td>
      </tr>
    `;
  }).join("");
  renderReallocationPagination(totalPages);

  updateBulkButtonVisibility();
  syncSelectAllCheckbox();
}

function renderReallocationPagination(totalPages) {
  const table = document.getElementById("reallocationTable");
  if (!table) {
    return;
  }
  const wrapper = table.closest(".table-container") || table.closest("table");
  if (!wrapper) {
    return;
  }
  const hostId = "reallocationPagination";
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
    <button type="button" id="reallocationPrevPageBtn" class="px-3 py-1 rounded border border-gray-300 bg-white text-sm">Prev</button>
    <span class="text-sm text-gray-600">Page ${reallocationState.page} / ${totalPages}</span>
    <button type="button" id="reallocationNextPageBtn" class="px-3 py-1 rounded border border-gray-300 bg-white text-sm">Next</button>
  `;
  const prev = document.getElementById("reallocationPrevPageBtn");
  const next = document.getElementById("reallocationNextPageBtn");
  if (prev) {
    prev.disabled = reallocationState.page <= 1;
    prev.addEventListener("click", function () {
      if (reallocationState.page <= 1) {
        return;
      }
      reallocationState.page -= 1;
      renderReallocationTable();
    });
  }
  if (next) {
    next.disabled = reallocationState.page >= totalPages;
    next.addEventListener("click", function () {
      if (reallocationState.page >= totalPages) {
        return;
      }
      reallocationState.page += 1;
      renderReallocationTable();
    });
  }
}

function getVisibleAllocations() {
  const search = String(document.getElementById("searchInput")?.value || "").trim().toLowerCase();
  const filter = String(document.getElementById("filterProgram")?.value || "all").toLowerCase();

  return reallocationState.activeAllocations.filter(function (allocation) {
    const student = getStudentByStudentId(Number(allocation.student_id));
    const tutor = getTutorByTutorId(Number(allocation.tutor_id));
    const studentName = String(student?.full_name || student?.user_name || "").toLowerCase();
    const tutorName = String(tutor?.full_name || tutor?.user_name || "").toLowerCase();
    const programme = String(student?.programme || "").toLowerCase();

    if (search && !studentName.includes(search) && !tutorName.includes(search) && !programme.includes(search)) {
      return false;
    }
    if (filter !== "all" && programme !== filter) {
      return false;
    }
    return true;
  });
}

function updateBulkButtonVisibility() {
  const button = document.getElementById("openReassignModalBtn");
  if (!button) {
    return;
  }

  if (reallocationState.selectedStudentIds.length > 0) {
    button.classList.remove("hidden");
    button.textContent = `Change Selected (${reallocationState.selectedStudentIds.length}/${MAX_BULK_SELECTION})`;
  } else {
    button.classList.add("hidden");
    button.textContent = "Change Selected";
  }
}

function syncSelectAllCheckbox() {
  const selectAll = document.getElementById("selectAllStudents");
  if (!selectAll) {
    return;
  }

  const visibleStudentIds = getVisibleAllocations().map(function (allocation) {
    return Number(allocation.student_id || 0);
  }).filter(Boolean);

  if (!visibleStudentIds.length) {
    selectAll.checked = false;
    return;
  }

  selectAll.checked = visibleStudentIds.every(function (studentId) {
    return reallocationState.selectedStudentIds.includes(studentId);
  });
}

function populateBulkTutorSelect(excludedTutorIds) {
  const select = document.getElementById("bulkTutorSelect");
  if (!select) {
    return;
  }

  const excluded = Array.isArray(excludedTutorIds)
    ? new Set(excludedTutorIds.map(function (id) { return Number(id || 0); }).filter(Boolean))
    : new Set();

  select.innerHTML = '<option value="">-- Select Tutor --</option>';
  reallocationState.tutors.forEach(function (tutor) {
    if (excluded.has(Number(tutor.tutor_id || 0))) {
      return;
    }
    const option = document.createElement("option");
    option.value = String(tutor.tutor_id);
    option.textContent = tutor.full_name || tutor.user_name || `Tutor ${tutor.tutor_id}`;
    select.appendChild(option);
  });
}

function openModal(studentId, studentIds) {
  const modal = document.getElementById("reassignModal");
  if (!modal) {
    return;
  }

  const selectedIds = Array.isArray(studentIds) && studentIds.length
    ? studentIds.filter(Boolean)
    : [Number(studentId || 0)].filter(Boolean);
  if (!selectedIds.length) {
    return;
  }

  reallocationState.selectedStudentIds = selectedIds.slice();
  reallocationState.selectedStudentId = selectedIds.length === 1 ? selectedIds[0] : 0;

  const modalName = document.getElementById("modalName");
  const modalProgram = document.getElementById("modalProgram");
  const selectedStudentsList = document.getElementById("selectedStudentsList");
  const currentTutorIds = selectedIds.map(function (id) {
    const allocation = reallocationState.activeAllocations.find(function (item) {
      return Number(item.student_id) === id;
    });
    return Number(allocation?.tutor_id || 0);
  }).filter(Boolean);

  if (selectedIds.length === 1) {
    const student = getStudentByStudentId(selectedIds[0]);
    if (modalName) {
      modalName.textContent = student?.full_name || student?.user_name || "Student";
    }
    if (modalProgram) {
      modalProgram.textContent = student?.programme || "N/A";
    }
    if (selectedStudentsList) {
      selectedStudentsList.classList.add("hidden");
      selectedStudentsList.innerHTML = "";
    }
    populateBulkTutorSelect(currentTutorIds);
  } else {
    if (modalName) {
      modalName.textContent = `${selectedIds.length} students selected`;
    }
    if (modalProgram) {
      modalProgram.textContent = "Multiple programmes";
    }
    if (selectedStudentsList) {
      selectedStudentsList.classList.remove("hidden");
      selectedStudentsList.innerHTML = selectedIds.map(function (id) {
        const student = getStudentByStudentId(id);
        return `<div>${escapeHtml(student?.full_name || student?.user_name || `Student ${id}`)}</div>`;
      }).join("");
    }
    populateBulkTutorSelect();
  }

  const bulkSelect = document.getElementById("bulkTutorSelect");
  if (bulkSelect) {
    bulkSelect.value = "";
  }

  modal.classList.remove("hidden");
  updateBulkButtonVisibility();
  syncSelectAllCheckbox();
}

function closeModal() {
  const modal = document.getElementById("reassignModal");
  if (modal) {
    modal.classList.add("hidden");
  }
}

async function confirmBulkReallocation() {
  const selectedTutorId = Number(document.getElementById("bulkTutorSelect")?.value || 0);
  const selectedIds = reallocationState.selectedStudentIds.length
    ? reallocationState.selectedStudentIds.slice()
    : [Number(reallocationState.selectedStudentId || 0)].filter(Boolean);

  if (!selectedIds.length) {
    setStatus("Please select at least one student first.", true);
    return;
  }
  if (selectedIds.length > MAX_BULK_SELECTION) {
    setStatus(`Bulk reallocation supports maximum ${MAX_BULK_SELECTION} students at one time.`, true);
    return;
  }
  if (!selectedTutorId) {
    setStatus("Please select a new tutor.", true);
    return;
  }

  const confirmBtn = document.getElementById("confirmReassignBtn");
  if (confirmBtn) {
    confirmBtn.disabled = true;
  }

  try {
    let changed = 0;
    let skipped = 0;
    let failed = 0;
    const jobs = [];

    for (const studentId of selectedIds) {
      const allocation = reallocationState.activeAllocations.find(function (item) {
        return Number(item.student_id) === studentId;
      });
      if (Number(allocation?.tutor_id || 0) === selectedTutorId) {
        skipped += 1;
        continue;
      }
      jobs.push(
        window.ApiClient.post("allocation", "reallocate", {
          student_id: studentId,
          new_tutor_id: selectedTutorId
        })
      );
    }

    if (jobs.length) {
      const results = await Promise.allSettled(jobs);
      results.forEach(function (result) {
        if (result.status === "fulfilled") {
          changed += 1;
        } else {
          failed += 1;
        }
      });
    }

    const ok = failed === 0;
    setStatus(
      `Done. Reallocated: ${changed}, Skipped (same tutor): ${skipped}${failed ? `, Failed: ${failed}` : ""}.`,
      !ok
    );
    reallocationState.selectedStudentIds = [];
    reallocationState.selectedStudentId = 0;
    closeModal();
    // Refresh in background so completion feedback is immediate.
    loadReallocationData().catch(function () {
      // Keep completion message if refresh fails.
    });
  } catch (error) {
    setStatus(error.message || "Unable to reallocate tutor.", true);
  } finally {
    if (confirmBtn) {
      confirmBtn.disabled = false;
    }
  }
}

function getStudentByStudentId(studentId) {
  return reallocationState.students.find(function (student) {
    return Number(student.student_id) === studentId;
  }) || null;
}

function getTutorByTutorId(tutorId) {
  return reallocationState.tutors.find(function (tutor) {
    return Number(tutor.tutor_id) === tutorId;
  }) || null;
}

function setStatus(message, isError) {
  const node = document.getElementById("reallocationStatus");
  if (!node) {
    return;
  }

  node.textContent = message;
  node.className = `text-sm mt-2 ${isError ? "text-red-500" : "text-green-600"}`;
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
