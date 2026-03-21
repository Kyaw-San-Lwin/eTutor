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
  selectedStudentId: 0
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
}

function bindAllocationActions() {
  const searchInput = document.getElementById("searchInput");
  const filterSelect = document.getElementById("filterProgram");
  const cancelBtn = document.getElementById("closeAssignModalBtn");
  const confirmBtn = document.getElementById("confirmAssignBtn");
  const modal = document.getElementById("assignModal");

  if (searchInput) {
    searchInput.addEventListener("input", renderStudents);
  }
  if (filterSelect) {
    filterSelect.addEventListener("change", renderStudents);
  }

  if (cancelBtn) {
    cancelBtn.addEventListener("click", closeModal);
  }
  if (confirmBtn) {
    confirmBtn.addEventListener("click", confirmAssign);
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
    table.addEventListener("click", function (event) {
      const assignBtn = event.target.closest("[data-assign-student-id]");
      if (!assignBtn) {
        return;
      }
      const studentId = Number(assignBtn.dataset.assignStudentId || 0);
      if (!studentId) {
        return;
      }
      openModal(studentId);
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
    target.textContent = formatDate(response.data?.last_login) || "N/A";
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
      window.ApiClient.get("user", "", { limit: 500, offset: 0 }),
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
    renderStudents();
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

  const filtered = allocationState.students.filter(function (student) {
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

  if (!filtered.length) {
    table.innerHTML = '<tr><td colspan="4">No students found.</td></tr>';
    return;
  }

  table.innerHTML = filtered.map(function (student, index) {
    const studentId = Number(student.student_id || 0);
    const activeTutorId = allocationState.activeByStudentId.get(studentId) || 0;
    const tutor = allocationState.tutors.find(function (item) {
      return Number(item.tutor_id) === activeTutorId;
    });
    const tutorName = tutor ? (tutor.full_name || tutor.user_name || "Assigned Tutor") : "Not assigned";

    return `
      <tr>
        <td class="student-name">
          <img src="${index % 2 === 0 ? "../../Images/profile.jpg" : "../../Images/profile 2.jpg"}" class="student-avatar" alt="Avatar">
          ${escapeHtml(student.full_name || student.user_name || "Student")}
        </td>
        <td>${escapeHtml(student.programme || "N/A")}</td>
        <td>-</td>
        <td>
          <button class="assign-btn" type="button" data-assign-student-id="${studentId}">
            ${activeTutorId ? `Reassign (${escapeHtml(tutorName)})` : "Assign"}
          </button>
        </td>
      </tr>
    `;
  }).join("");
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

function openModal(studentId) {
  const modal = document.getElementById("assignModal");
  const student = allocationState.students.find(function (item) {
    return Number(item.student_id) === studentId;
  });
  if (!modal || !student) {
    return;
  }

  allocationState.selectedStudentId = studentId;
  document.getElementById("modalName").textContent = student.full_name || student.user_name || "Student";
  document.getElementById("modalProgram").textContent = student.programme || "N/A";

  const activeTutorId = allocationState.activeByStudentId.get(studentId) || 0;
  document.getElementById("tutorSelect").value = activeTutorId ? String(activeTutorId) : "";
  modal.classList.remove("hidden");
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
  const studentId = Number(allocationState.selectedStudentId || 0);

  if (!studentId) {
    setStatus("Please select a student first.", true);
    return;
  }
  if (!selectedTutorId) {
    setStatus("Please select a tutor.", true);
    return;
  }

  const currentTutorId = allocationState.activeByStudentId.get(studentId) || 0;
  if (currentTutorId === selectedTutorId) {
    setStatus("Selected tutor is already assigned to this student.", true);
    return;
  }

  const confirmBtn = document.getElementById("confirmAssignBtn");
  if (confirmBtn) {
    confirmBtn.disabled = true;
  }

  try {
    if (currentTutorId) {
      await window.ApiClient.post("allocation", "reallocate", {
        student_id: studentId,
        new_tutor_id: selectedTutorId
      });
      setStatus("Tutor reallocated successfully.", false);
    } else {
      await window.ApiClient.post("allocation", "", {
        student_id: studentId,
        tutor_id: selectedTutorId,
        status: "active"
      });
      setStatus("Tutor allocated successfully.", false);
    }

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
