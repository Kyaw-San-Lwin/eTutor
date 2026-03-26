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
  selectedStudentId: 0,
  selectedDocumentId: 0,
  commentsByDocumentId: new Map(),
  previewGuardActive: false
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
    await loadAssignedStudents();
    await Promise.allSettled([
      loadTutorDocuments(),
      loadTutorDocumentComments()
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
      event.preventDefault();
      const documentId = Number(fileLink.dataset.viewDocumentId || 0);
      const readOnlyPreview = fileLink.dataset.readonlyPreview === "1";
      const previewable = fileLink.dataset.previewable !== "0";
      if (documentId > 0) {
        documentState.viewedDocumentIds.add(documentId);
        renderDocuments();
        if (documentState.role === "tutor") {
          const doc = findDocumentById(documentId);
          if (doc) {
            openStudentPanel(Number(doc.student_id || 0), documentId);
          }
        }
        if (readOnlyPreview) {
          if (!previewable) {
            setStatus("This file type is download-only in browser preview mode.", true);
            return;
          }
          openDocumentPreviewModal(documentId);
        } else {
          openDocumentForView(documentId);
        }
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
    const documentId = Number(studentButton.dataset.documentId || 0);
    if (studentId <= 0) {
      return;
    }
    openStudentPanel(studentId, documentId);
  });
}

async function openDocumentForView(documentId) {
  const did = Number(documentId || 0);
  if (did <= 0) {
    return;
  }

  try {
    const token = window.AuthStorage.getAccessToken();
    const url = `${window.AppConfig.apiBaseUrl}?controller=document&action=view&id=${encodeURIComponent(did)}&preview=1`;
    const response = await fetch(url, {
      method: "GET",
      headers: token ? { Authorization: `Bearer ${token}` } : {}
    });

    if (!response.ok) {
      let message = "Unable to open document.";
      try {
        const payload = await response.json();
        message = payload?.message || message;
      } catch (e) {
        // keep fallback message
      }
      setStatus(message, true);
      return;
    }

    const blob = await response.blob();
    const blobUrl = URL.createObjectURL(blob);
    const opened = window.open(blobUrl, "_blank", "noopener");
    if (!opened) {
      window.location.href = blobUrl;
    }
    setTimeout(function () {
      URL.revokeObjectURL(blobUrl);
    }, 60000);
  } catch (error) {
    setStatus(error.message || "Unable to open document.", true);
  }
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
  const sendCommentButton = document.getElementById("sendCommentBtn");

  if (messageButton) {
    messageButton.addEventListener("click", function () {
      window.location.href = "./Tutor_Messaging.html";
    });
  }
  if (sendCommentButton) {
    sendCommentButton.addEventListener("click", submitTutorComment);
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
    const response = await window.ApiClient.get("document", "", { limit: 100, offset: 0 });
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
    const response = await window.ApiClient.get("document", "", { limit: 100, offset: 0 });
    documentState.documents = Array.isArray(response.data) ? response.data : [];
    renderDocuments();
  } catch (error) {
    if (table) {
      table.innerHTML = `<tr><td colspan="5">${escapeHtml(error.message || "Unable to load documents.")}</td></tr>`;
    }
  }
}

async function loadTutorDocumentComments() {
  try {
    const response = await window.ApiClient.get("document_comment");
    const comments = Array.isArray(response.data) ? response.data : [];
    const map = new Map();
    comments.forEach(function (row) {
      const documentId = Number(row.document_id || 0);
      if (documentId > 0) {
        map.set(documentId, row);
      }
    });
    documentState.commentsByDocumentId = map;
  } catch (error) {
    documentState.commentsByDocumentId = new Map();
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
  const extension = getFileExtension(fileName);
  const previewable = isPreviewableExtension(extension);
  const badgeClass = previewable ? "preview-badge preview-ok" : "preview-badge preview-download-only";
  const badgeText = previewable ? "Preview" : "Download only";

  return `
    <tr>
      <td>
        <a href="${escapeHtml(fileUrl)}" target="_blank" rel="noopener" class="file-name" data-view-document-id="${documentId}">
          ${escapeHtml(fileName)}
        </a>
        <span class="${badgeClass}" style="margin-left:8px;font-size:11px;padding:2px 8px;border-radius:999px;">${badgeText}</span>
      </td>
      <td>${escapeHtml(formatDate(doc.uploaded_at))}</td>
      <td><a href="${escapeHtml(fileUrl)}" target="_blank" rel="noopener" download="${escapeHtml(fileName)}" class="download-btn">Download</a></td>
      <td>
        <a href="${escapeHtml(fileUrl)}" target="_blank" rel="noopener" data-view-document-id="${documentId}" data-readonly-preview="1" data-previewable="${previewable ? '1' : '0'}" aria-label="View document">
          <i class="bi ${viewed ? 'bi-eye-fill' : 'bi-eye'}"></i>
        </a>
      </td>
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
  const extension = getFileExtension(fileName);
  const previewable = isPreviewableExtension(extension);
  const badgeClass = previewable ? "preview-badge preview-ok" : "preview-badge preview-download-only";
  const badgeText = previewable ? "Preview" : "Download only";

  return `
    <tr>
      <td>
        <button type="button" class="student-name" data-student-id="${studentId}" data-document-id="${documentId}">
          ${escapeHtml(studentName)}
        </button>
      </td>
      <td>
        <a href="${escapeHtml(fileUrl)}" target="_blank" rel="noopener" class="file-name" data-view-document-id="${documentId}">
          ${escapeHtml(fileName)}
        </a>
        <span class="${badgeClass}" style="margin-left:8px;font-size:11px;padding:2px 8px;border-radius:999px;">${badgeText}</span>
      </td>
      <td>${escapeHtml(formatDate(doc.uploaded_at))}</td>
      <td><a href="${escapeHtml(fileUrl)}" target="_blank" rel="noopener" download="${escapeHtml(fileName)}" class="download-btn">Download</a></td>
      <td>
        <a href="${escapeHtml(fileUrl)}" target="_blank" rel="noopener" data-view-document-id="${documentId}" data-readonly-preview="1" data-previewable="${previewable ? '1' : '0'}" aria-label="View document">
          <i class="bi ${viewed ? 'bi-eye-fill' : 'bi-eye'}"></i>
        </a>
      </td>
    </tr>
  `;
}

async function openDocumentPreviewModal(documentId) {
  const did = Number(documentId || 0);
  if (did <= 0) {
    return;
  }

  try {
    const response = await window.ApiClient.get("document", "preview", { id: did, preview: 1 });
    const contentBase64 = response?.data?.content_base64 || "";
    if (!contentBase64) {
      setStatus("Preview payload is empty.", true);
      return;
    }

    let mimeType = String(response?.data?.mime || "").toLowerCase();
    if (!mimeType || mimeType === "application/octet-stream") {
      const doc = findDocumentById(did);
      mimeType = inferMimeTypeFromFilePath(doc?.file_path || "");
    }
    const blob = base64ToBlob(contentBase64, mimeType);
    const blobUrl = URL.createObjectURL(blob);
    showReadOnlyPreview(blobUrl, mimeType);
  } catch (error) {
    setStatus(error.message || "Unable to preview document.", true);
  }
}

function inferMimeTypeFromFilePath(filePath) {
  const ext = getFileExtension(getFileName(filePath || "")).toLowerCase();
  const map = {
    pdf: "application/pdf",
    txt: "text/plain",
    jpg: "image/jpeg",
    jpeg: "image/jpeg",
    png: "image/png",
    doc: "application/msword",
    docx: "application/vnd.openxmlformats-officedocument.wordprocessingml.document",
    ppt: "application/vnd.ms-powerpoint",
    pptx: "application/vnd.openxmlformats-officedocument.presentationml.presentation"
  };
  return map[ext] || "application/octet-stream";
}

function base64ToBlob(base64, mimeType) {
  const binary = atob(base64);
  const len = binary.length;
  const bytes = new Uint8Array(len);
  for (let i = 0; i < len; i += 1) {
    bytes[i] = binary.charCodeAt(i);
  }
  return new Blob([bytes], { type: mimeType || "application/octet-stream" });
}

function showReadOnlyPreview(blobUrl, mimeType) {
  let modal = document.getElementById("readOnlyPreviewModal");
  if (!modal) {
    modal = document.createElement("div");
    modal.id = "readOnlyPreviewModal";
    modal.style.cssText = "position:fixed;inset:0;background:rgba(0,0,0,.6);display:flex;align-items:center;justify-content:center;z-index:9999;padding:20px;";
    modal.innerHTML = `
      <div style="background:#fff;width:min(1100px,100%);height:min(90vh,100%);border-radius:12px;overflow:hidden;display:flex;flex-direction:column;">
        <div style="display:flex;justify-content:space-between;align-items:center;padding:10px 14px;border-bottom:1px solid #e5e7eb;">
          <strong>Document Preview (Read-only)</strong>
          <button type="button" id="closeReadOnlyPreviewBtn" style="border:0;background:transparent;font-size:22px;line-height:1;cursor:pointer;">&times;</button>
        </div>
        <div id="readOnlyPreviewBody" style="flex:1;min-height:0;background:#f8fafc;"></div>
      </div>
    `;
    document.body.appendChild(modal);

    modal.addEventListener("click", function (event) {
      if (event.target === modal) {
        closeReadOnlyPreview();
      }
    });
    modal.querySelector("#closeReadOnlyPreviewBtn").addEventListener("click", closeReadOnlyPreview);
  }

  const body = modal.querySelector("#readOnlyPreviewBody");
  if (!body) {
    return;
  }

  if (mimeType.includes("pdf")) {
    body.innerHTML = `<div id="pdfPreviewHost" style="height:100%;overflow:auto;padding:12px;background:#eef2f7;"></div>`;
    renderPdfPreview(blobUrl, body.querySelector("#pdfPreviewHost"));
  } else if (mimeType.startsWith("image/")) {
    body.innerHTML = `<div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;background:#111827;"><img src="${blobUrl}" alt="Preview" style="max-width:100%;max-height:100%;object-fit:contain;"></div>`;
  } else if (mimeType.startsWith("text/")) {
    fetch(blobUrl).then(function (r) { return r.text(); }).then(function (text) {
      body.innerHTML = `<pre style="margin:0;padding:16px;height:100%;overflow:auto;white-space:pre-wrap;background:#fff;">${escapeHtml(text)}</pre>`;
    }).catch(function () {
      body.innerHTML = `<div style="padding:16px;">Preview not available for this file type. Use Download.</div>`;
    });
  } else {
    body.innerHTML = `
      <iframe src="${blobUrl}" style="width:100%;height:100%;border:0;"></iframe>
      <div style="position:absolute;left:-9999px;">Read-only preview fallback</div>
    `;
  }

  modal.dataset.blobUrl = blobUrl;
  modal.style.display = "flex";
  enablePreviewGuards();
}

async function ensurePdfJsLoaded() {
  if (window.pdfjsLib && window.pdfjsLib.getDocument) {
    return true;
  }

  const scriptId = "pdfjs-cdn-script";
  if (!document.getElementById(scriptId)) {
    const script = document.createElement("script");
    script.id = scriptId;
    script.src = "https://cdnjs.cloudflare.com/ajax/libs/pdf.js/4.6.82/pdf.min.mjs";
    script.type = "module";
    script.onload = function () {};
    script.onerror = function () {};
    document.head.appendChild(script);
  }

  // Fallback to UMD build for simpler global usage in plain script environment.
  if (!window.pdfjsLib) {
    await new Promise(function (resolve) {
      const umdId = "pdfjs-umd-script";
      let umd = document.getElementById(umdId);
      if (umd) {
        const wait = setInterval(function () {
          if (window.pdfjsLib) {
            clearInterval(wait);
            resolve();
          }
        }, 100);
        setTimeout(function () {
          clearInterval(wait);
          resolve();
        }, 4000);
        return;
      }

      umd = document.createElement("script");
      umd.id = umdId;
      umd.src = "https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js";
      umd.onload = function () {
        resolve();
      };
      umd.onerror = function () {
        resolve();
      };
      document.head.appendChild(umd);
    });
  }

  if (window.pdfjsLib && !window.pdfjsLib.GlobalWorkerOptions.workerSrc) {
    window.pdfjsLib.GlobalWorkerOptions.workerSrc = "https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js";
  }
  return Boolean(window.pdfjsLib && window.pdfjsLib.getDocument);
}

async function renderPdfPreview(blobUrl, host) {
  if (!host) {
    return;
  }

  const loaded = await ensurePdfJsLoaded();
  if (!loaded) {
    host.innerHTML = `<iframe src="${blobUrl}#toolbar=0&navpanes=0&scrollbar=1" style="width:100%;height:100%;border:0;"></iframe>`;
    return;
  }

  try {
    const pdf = await window.pdfjsLib.getDocument(blobUrl).promise;
    host.innerHTML = "";

    const scale = 1.15;
    for (let pageNum = 1; pageNum <= pdf.numPages; pageNum += 1) {
      const page = await pdf.getPage(pageNum);
      const viewport = page.getViewport({ scale: scale });
      const canvas = document.createElement("canvas");
      const context = canvas.getContext("2d");
      canvas.width = Math.floor(viewport.width);
      canvas.height = Math.floor(viewport.height);
      canvas.style.width = "100%";
      canvas.style.maxWidth = `${Math.floor(viewport.width)}px`;
      canvas.style.display = "block";
      canvas.style.margin = "0 auto 12px auto";
      canvas.style.background = "#fff";
      canvas.style.boxShadow = "0 2px 8px rgba(0,0,0,.08)";
      await page.render({ canvasContext: context, viewport: viewport }).promise;
      host.appendChild(canvas);
    }
  } catch (error) {
    host.innerHTML = `<iframe src="${blobUrl}#toolbar=0&navpanes=0&scrollbar=1" style="width:100%;height:100%;border:0;"></iframe>`;
  }
}

function closeReadOnlyPreview() {
  const modal = document.getElementById("readOnlyPreviewModal");
  if (!modal) {
    return;
  }
  const blobUrl = modal.dataset.blobUrl || "";
  if (blobUrl) {
    URL.revokeObjectURL(blobUrl);
  }
  modal.dataset.blobUrl = "";
  modal.style.display = "none";
  disablePreviewGuards();
}

function enablePreviewGuards() {
  if (documentState.previewGuardActive) {
    return;
  }
  documentState.previewGuardActive = true;
  document.addEventListener("contextmenu", handlePreviewContextMenu, true);
  document.addEventListener("keydown", handlePreviewKeydown, true);
}

function disablePreviewGuards() {
  if (!documentState.previewGuardActive) {
    return;
  }
  documentState.previewGuardActive = false;
  document.removeEventListener("contextmenu", handlePreviewContextMenu, true);
  document.removeEventListener("keydown", handlePreviewKeydown, true);
}

function isPreviewOpen() {
  const modal = document.getElementById("readOnlyPreviewModal");
  return Boolean(modal && modal.style.display !== "none");
}

function handlePreviewContextMenu(event) {
  if (!isPreviewOpen()) {
    return;
  }
  event.preventDefault();
}

function handlePreviewKeydown(event) {
  if (!isPreviewOpen()) {
    return;
  }

  const key = String(event.key || "").toLowerCase();
  const ctrlOrMeta = event.ctrlKey || event.metaKey;
  const blockedKeys = ["s", "p", "u"];

  if (ctrlOrMeta && blockedKeys.includes(key)) {
    event.preventDefault();
    setStatus("Read-only preview mode is active.", true);
    return;
  }

  if (key === "printscreen") {
    event.preventDefault();
    setStatus("Read-only preview mode is active.", true);
  }
}

function openStudentPanel(studentId, documentId) {
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

  const resolvedDocumentId = resolveDocumentIdForStudent(studentId, documentId);

  documentState.selectedStudentId = studentId;
  documentState.selectedDocumentId = resolvedDocumentId;
  setText("studentName", student.name);
  const selectedDoc = findDocumentById(resolvedDocumentId);
  const selectedDocName = selectedDoc ? getFileName(selectedDoc.file_path) : "";
  setText("studentMeta", [student.programme, student.email, selectedDocName].filter(Boolean).join(" | "));

  const commentInput = document.getElementById("tutorComment");
  const existing = documentState.commentsByDocumentId.get(documentState.selectedDocumentId);
  if (commentInput) {
    commentInput.value = existing?.comment || "";
  }
  setTutorCommentEditState(!existing);

  const image = document.getElementById("studentProfileImage");
  if (image) {
    image.src = student.photo
      ? resolveAssetUrl(student.photo)
      : getDefaultAvatar(student.name || `Student ${studentId}`);
  }

  panel.classList.add("show");
}

function resolveDocumentIdForStudent(studentId, preferredDocumentId) {
  const preferred = Number(preferredDocumentId || 0);
  if (preferred > 0) {
    return preferred;
  }

  const sid = Number(studentId || 0);
  if (sid <= 0) {
    return 0;
  }

  const docs = documentState.documents.filter(function (doc) {
    return Number(doc.student_id || 0) === sid;
  });
  if (!docs.length) {
    return 0;
  }

  docs.sort(function (a, b) {
    const aTime = new Date(a.uploaded_at || 0).getTime();
    const bTime = new Date(b.uploaded_at || 0).getTime();
    return bTime - aTime;
  });

  return Number(docs[0].document_id || 0);
}

async function submitTutorComment() {
  const commentInput = document.getElementById("tutorComment");
  const sendCommentButton = document.getElementById("sendCommentBtn");
  const documentId = Number(documentState.selectedDocumentId || 0);
  const comment = String(commentInput?.value || "").trim();

  if (documentId <= 0) {
    setStatus("Please select a student document first.", true);
    return;
  }
  if (!comment) {
    setStatus("Please write a comment first.", true);
    return;
  }

  const hasExisting = documentState.commentsByDocumentId.has(documentId);
  if (hasExisting) {
    setStatus("Comment already submitted for this document.", true);
    setTutorCommentEditState(false);
    return;
  }

  if (sendCommentButton) {
    sendCommentButton.disabled = true;
  }

  try {
    await window.ApiClient.post("document_comment", "", {
      document_id: documentId,
      comment: comment
    });
    setStatus("Comment sent.", false);
    await loadTutorDocumentComments();

    if (commentInput) {
      commentInput.value = "";
    }
    const panel = document.getElementById("studentProfile");
    if (panel) {
      panel.classList.remove("show");
    }
    documentState.selectedStudentId = 0;
    documentState.selectedDocumentId = 0;
  } catch (error) {
    setStatus(error.message || "Unable to save comment.", true);
  } finally {
    if (sendCommentButton) {
      sendCommentButton.disabled = false;
    }
  }
}

function setTutorCommentEditState(canEdit) {
  const commentInput = document.getElementById("tutorComment");
  const sendButton = document.getElementById("sendCommentBtn");
  if (commentInput) {
    commentInput.readOnly = !canEdit;
    if (!canEdit) {
      commentInput.title = "Comment already submitted for this document.";
    } else {
      commentInput.removeAttribute("title");
    }
  }
  if (sendButton) {
    sendButton.disabled = !canEdit;
  }
}

function getStudentName(studentId) {
  const student = documentState.studentMap.get(Number(studentId));
  if (!student) {
    return `Student ${studentId}`;
  }
  return student.name;
}

function findDocumentById(documentId) {
  const did = Number(documentId || 0);
  if (did <= 0) {
    return null;
  }
  return documentState.documents.find(function (doc) {
    return Number(doc.document_id || 0) === did;
  }) || null;
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

function getDefaultAvatar(name) {
  const safeName = String(name || "User").trim() || "User";
  const initials = safeName
    .split(/\s+/)
    .slice(0, 2)
    .map(function (part) { return part.charAt(0).toUpperCase(); })
    .join("") || "U";
  const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64"><rect width="100%" height="100%" fill="#1d4ed8"/><text x="50%" y="52%" dominant-baseline="middle" text-anchor="middle" font-family="Arial" font-size="24" fill="#ffffff">${initials}</text></svg>`;
  return `data:image/svg+xml;utf8,${encodeURIComponent(svg)}`;
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

function isPreviewableExtension(extension) {
  const ext = String(extension || "").toLowerCase();
  return ["pdf", "txt", "jpg", "jpeg", "png"].includes(ext);
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
