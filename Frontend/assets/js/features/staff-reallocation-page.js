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
  activeAllocations: []
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

function bindReallocationActions() {
  const searchInput = document.getElementById("searchInput");
  if (searchInput) {
    searchInput.addEventListener("input", renderReallocationTable);
  }

  const table = document.getElementById("reallocationTable");
  if (table) {
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
        await loadReallocationData();
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
    target.textContent = formatDate(response.data?.last_login) || "N/A";
  } catch (error) {
    target.textContent = "N/A";
  }
}

async function loadReallocationData() {
  const table = document.getElementById("reallocationTable");
  if (table) {
    table.innerHTML = '<tr><td colspan="5">Loading...</td></tr>';
  }

  try {
    const [usersResponse, allocationsResponse] = await Promise.all([
      window.ApiClient.get("user", "", { limit: 500, offset: 0 }),
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

    renderReallocationTable();
  } catch (error) {
    if (table) {
      table.innerHTML = `<tr><td colspan="5">${escapeHtml(error.message || "Unable to load active allocations.")}</td></tr>`;
    }
    setStatus(error.message || "Unable to load reallocation data.", true);
  }
}

function renderReallocationTable() {
  const table = document.getElementById("reallocationTable");
  if (!table) {
    return;
  }

  const search = String(document.getElementById("searchInput")?.value || "").trim().toLowerCase();

  const rows = reallocationState.activeAllocations.filter(function (allocation) {
    const student = getStudentByStudentId(Number(allocation.student_id));
    const tutor = getTutorByTutorId(Number(allocation.tutor_id));
    const studentName = (student?.full_name || student?.user_name || "").toLowerCase();
    const tutorName = (tutor?.full_name || tutor?.user_name || "").toLowerCase();
    return !search || studentName.includes(search) || tutorName.includes(search);
  });

  if (!rows.length) {
    table.innerHTML = '<tr><td colspan="5">No active allocations found.</td></tr>';
    return;
  }

  table.innerHTML = rows.map(function (allocation, index) {
    const studentId = Number(allocation.student_id || 0);
    const currentTutorId = Number(allocation.tutor_id || 0);
    const student = getStudentByStudentId(studentId);
    const tutor = getTutorByTutorId(currentTutorId);
    const availableTutors = reallocationState.tutors.filter(function (item) {
      return Number(item.tutor_id) !== currentTutorId;
    });

    return `
      <tr>
        <td class="student-name">
          <img src="${index % 2 === 0 ? "../../Images/profile.jpg" : "../../Images/profile 2.jpg"}" class="student-avatar" alt="Avatar">
          ${escapeHtml(student?.full_name || student?.user_name || `Student ${studentId}`)}
        </td>
        <td>${escapeHtml(student?.programme || "N/A")}</td>
        <td>${escapeHtml(tutor?.full_name || tutor?.user_name || `Tutor ${currentTutorId}`)}</td>
        <td>
          <select id="newTutor-${studentId}" class="w-full p-2 border rounded">
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
