document.addEventListener("DOMContentLoaded", async function () {
  const role = document.body.dataset.documentRole || "";
  if (!role) {
    return;
  }

  const user = window.Auth.requireAuth([role]);
  if (!user) {
    return;
  }

  bindLogout();
  bindSearchAndFilter();
  bindTableActions();

  await Promise.allSettled([
    loadLastLogin(),
    initializePage(role)
  ]);
});

const documentState = {
  role: "",
  documents: [],
  viewedDocumentIds: new Set(),
  studentMap: new Map(),
  selectedStudentId: 0
};

async function initializePage(role) {
  documentState.role = role;

  if (role === "student") {
    bindStudentUpload();
    await loadStudentDocuments();
    return;
  }

  if (role === "tutor") {
    bindTutorProfilePanel();
    await Promise.allSettled([
      loadAssignedStudents(),
      loadTutorDocuments()
    ]);
  }
}

function bindLogout() {
  const logoutLink = document.querySelector(".logout");
  if (logoutLink) {
    logoutLink.addEventListener("click", function (event) {
      event.preventDefault();
      window.Auth.logout();
    });
  }
}

function bindSearchAndFilter() {
  const searchInput = document.getElementById("searchInput");
  const filterSelect = document.getElementById("filter");

  if (searchInput) {
    searchInput.addEventListener("input", renderDocuments);
  }
  if (filterSelect) {
    filterSelect.addEventListener("change", renderDocuments);
  }
}

function bindTableActions() {
  const table = document.getElementById("fileTable");
  if (!table) {
    return;
  }

  table.addEventListener("click", function (event) {
    const fileLink = event.target.closest("[data-view-document-id]");
    if (fileLink) {
      const documentId = Number(fileLink.dataset.viewDocumentId || 0);
      if (documentId > 0) {
        documentState.viewedDocumentIds.add(documentId);
        renderDocuments();
      }
      return;
    }

    if (documentState.role !== "tutor") {
      return;
    }

    const studentButton = event.target.closest("[data-student-id]");
    if (!studentButton) {
      return;
    }

    const studentId = Number(studentButton.dataset.studentId || 0);
    if (studentId <= 0) {
      return;
    }
    openStudentPanel(studentId);
  });
}

function bindStudentUpload() {
  const uploadButton = document.getElementById("uploadBtn");
  if (!uploadButton) {
    return;
  }

  uploadButton.addEventListener("click", uploadStudentDocument);
}

function bindTutorProfilePanel() {
  const panel = document.getElementById("studentProfile");
  const messageButton = document.getElementById("studentMessageBtn");

  if (messageButton) {
    messageButton.addEventListener("click", function () {
      window.location.href = "./Tutor_Messaging.html";
    });
  }

  if (!panel) {
    return;
  }

  document.addEventListener("click", function (event) {
    const withinPanel = panel.contains(event.target);
    const isStudentCell = Boolean(event.target.closest("[data-student-id]"));
    if (!withinPanel && !isStudentCell) {
      panel.classList.remove("show");
    }
  });
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

async function loadStudentDocuments() {
  const table = document.getElementById("fileTable");
  if (table) {
    table.innerHTML = `<tr><td colspan="4">Loading documents...</td></tr>`;
  }

  try {
    const response = await window.ApiClient.get("document", "", { limit: 200, offset: 0 });
    documentState.documents = Array.isArray(response.data) ? response.data : [];
    renderDocuments();
    setStatus("", false);
  } catch (error) {
    if (table) {
      table.innerHTML = `<tr><td colspan="4">${escapeHtml(error.message || "Unable to load documents.")}</td></tr>`;
    }
    setStatus(error.message || "Unable to load documents.", true);
  }
}

async function loadAssignedStudents() {
  try {
    const response = await window.ApiClient.get("allocation", "assignedStudents");
    const rows = Array.isArray(response.data) ? response.data : [];
    const map = new Map();

    rows.forEach(function (row) {
      map.set(Number(row.student_id), {
        student_id: Number(row.student_id),
        name: row.student_full_name || row.student_user_name || `Student ${row.student_id}`,
        email: row.student_email || "",
        programme: row.student_programme || "",
        photo: row.student_profile_photo || ""
      });
    });

    documentState.studentMap = map;
  } catch (error) {
    documentState.studentMap = new Map();
  }
}

async function loadTutorDocuments() {
  const table = document.getElementById("fileTable");
  if (table) {
    table.innerHTML = `<tr><td colspan="5">Loading documents...</td></tr>`;
  }

  try {
    const response = await window.ApiClient.get("document", "", { limit: 200, offset: 0 });
    documentState.documents = Array.isArray(response.data) ? response.data : [];
    renderDocuments();
  } catch (error) {
    if (table) {
      table.innerHTML = `<tr><td colspan="5">${escapeHtml(error.message || "Unable to load documents.")}</td></tr>`;
    }
  }
}

async function uploadStudentDocument() {
  const fileInput = document.getElementById("fileUpload");
  const uploadButton = document.getElementById("uploadBtn");
  const file = fileInput && fileInput.files ? fileInput.files[0] : null;

  if (!file) {
    setStatus("Please select a file first.", true);
    return;
  }

  if (uploadButton) {
    uploadButton.disabled = true;
  }

  const formData = new FormData();
  formData.append("file", file);

  try {
    const response = await window.ApiClient.request({
      controller: "document",
      method: "POST",
      body: formData
    });

    setStatus(response.message || "Document uploaded successfully.", false);
    if (fileInput) {
      fileInput.value = "";
    }
    setInputValue("fileName", "");
    await loadStudentDocuments();
  } catch (error) {
    setStatus(error.message || "Unable to upload document.", true);
  } finally {
    if (uploadButton) {
      uploadButton.disabled = false;
    }
  }
}

function renderDocuments() {
  const table = document.getElementById("fileTable");
  if (!table) {
    return;
  }

  const search = getInputValue("searchInput").toLowerCase();
  const filter = getFilterValue();
  const filteredDocuments = documentState.documents.filter(function (doc) {
    const name = getFileName(doc.file_path).toLowerCase();
    const dateLabel = formatDate(doc.uploaded_at).toLowerCase();
    const student = getStudentName(doc.student_id).toLowerCase();

    const matchesSearch = !search
      || name.includes(search)
      || dateLabel.includes(search)
      || student.includes(search);
    if (!matchesSearch) {
      return false;
    }

    if (filter === "all") {
      return true;
    }

    const extension = getFileExtension(name);
    if (filter === "doc") {
      return extension === "doc" || extension === "docx";
    }
    if (filter === "ppt") {
      return extension === "ppt" || extension === "pptx";
    }
    return extension === filter;
  });

  if (!filteredDocuments.length) {
    const colspan = documentState.role === "tutor" ? 5 : 4;
    table.innerHTML = `<tr><td colspan="${colspan}">No documents found.</td></tr>`;
    return;
  }

  if (documentState.role === "tutor") {
    table.innerHTML = filteredDocuments.map(renderTutorDocumentRow).join("");
    return;
  }

  table.innerHTML = filteredDocuments.map(renderStudentDocumentRow).join("");
}

function renderStudentDocumentRow(doc) {
  const documentId = Number(doc.document_id || 0);
  const fileName = getFileName(doc.file_path);
  const fileUrl = resolveAssetUrl(doc.file_path);
  const viewed = documentState.viewedDocumentIds.has(documentId);

  return `
    <tr>
      <td>
        <a href="${escapeHtml(fileUrl)}" target="_blank" rel="noopener" class="file-name" data-view-document-id="${documentId}">
          ${escapeHtml(fileName)}
        </a>
      </td>
      <td>${escapeHtml(formatDate(doc.uploaded_at))}</td>
      <td><a href="${escapeHtml(fileUrl)}" target="_blank" rel="noopener" download="${escapeHtml(fileName)}" class="download-btn">Download</a></td>
      <td>${viewed ? '<i class="bi bi-eye"></i>' : ''}</td>
    </tr>
  `;
}

function renderTutorDocumentRow(doc) {
  const documentId = Number(doc.document_id || 0);
  const studentId = Number(doc.student_id || 0);
  const fileName = getFileName(doc.file_path);
  const fileUrl = resolveAssetUrl(doc.file_path);
  const viewed = documentState.viewedDocumentIds.has(documentId);
  const studentName = getStudentName(studentId);

  return `
    <tr>
      <td>
        <button type="button" class="student-name" data-student-id="${studentId}">
          ${escapeHtml(studentName)}
        </button>
      </td>
      <td>
        <a href="${escapeHtml(fileUrl)}" target="_blank" rel="noopener" class="file-name" data-view-document-id="${documentId}">
          ${escapeHtml(fileName)}
        </a>
      </td>
      <td>${escapeHtml(formatDate(doc.uploaded_at))}</td>
      <td><a href="${escapeHtml(fileUrl)}" target="_blank" rel="noopener" download="${escapeHtml(fileName)}" class="download-btn">Download</a></td>
      <td>${viewed ? '<i class="bi bi-eye"></i>' : ''}</td>
    </tr>
  `;
}

function openStudentPanel(studentId) {
  const panel = document.getElementById("studentProfile");
  if (!panel) {
    return;
  }

  const student = documentState.studentMap.get(studentId) || {
    name: `Student ${studentId}`,
    email: "",
    programme: "",
    photo: ""
  };

  documentState.selectedStudentId = studentId;
  setText("studentName", student.name);
  setText("studentMeta", [student.programme, student.email].filter(Boolean).join(" | "));

  const image = document.getElementById("studentProfileImage");
  if (image) {
    image.src = student.photo ? resolveAssetUrl(student.photo) : getDefaultAvatar();
  }

  panel.classList.add("show");
}

function getStudentName(studentId) {
  const student = documentState.studentMap.get(Number(studentId));
  if (!student) {
    return `Student ${studentId}`;
  }
  return student.name;
}

function getInputValue(id) {
  const element = document.getElementById(id);
  return element ? String(element.value || "") : "";
}

function setInputValue(id, value) {
  const element = document.getElementById(id);
  if (element) {
    element.value = value;
  }
}

function setText(id, value) {
  const element = document.getElementById(id);
  if (element) {
    element.textContent = value || "";
  }
}

function getFilterValue() {
  const select = document.getElementById("filter");
  if (!select) {
    return "all";
  }
  return String(select.value || "all").toLowerCase();
}

function setStatus(message, isError) {
  const status = document.getElementById("documentStatus");
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

function getDefaultAvatar() {
  return `${window.AppConfig.frontendBase}/Images/profile.jpg`;
}

function getFileName(filePath) {
  if (!filePath) {
    return "Unknown file";
  }

  const normalized = String(filePath).split("?")[0];
  const parts = normalized.split("/");
  return parts[parts.length - 1] || normalized;
}

function getFileExtension(fileName) {
  const match = fileName.toLowerCase().match(/\.([a-z0-9]+)$/);
  return match ? match[1] : "";
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
