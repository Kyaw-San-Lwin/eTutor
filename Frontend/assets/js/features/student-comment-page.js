document.addEventListener("DOMContentLoaded", async function () {
  const user = window.Auth.requireAuth(["student"]);
  if (!user) {
    return;
  }

  bindLogout();
  await Promise.allSettled([
    loadLastLogin(),
    loadDocumentComments()
  ]);
});

const commentState = {
  comments: [],
  documentNameMap: new Map(),
  page: 1,
  pageSize: 5
};

function bindLogout() {
  const logoutLink = document.querySelector(".logout");
  if (logoutLink) {
    logoutLink.addEventListener("click", function (event) {
      event.preventDefault();
      window.Auth.logout();
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

async function loadDocumentComments() {
  const container = document.getElementById("commentList");
  if (!container) {
    return;
  }

  container.innerHTML = '<div class="meeting-card"><p class="message-text">Loading comments...</p></div>';

  try {
    const [commentsResponse, documentsResponse] = await Promise.all([
      window.ApiClient.get("document_comment"),
      window.ApiClient.get("document", "", { limit: 100, offset: 0 })
    ]);

    const comments = Array.isArray(commentsResponse.data) ? commentsResponse.data : [];
    const documents = Array.isArray(documentsResponse.data) ? documentsResponse.data : [];
    const documentNameMap = new Map();
    documents.forEach(function (doc) {
      documentNameMap.set(Number(doc.document_id), getFileName(doc.file_path));
    });

    commentState.comments = comments;
    commentState.documentNameMap = documentNameMap;
    commentState.page = 1;
    renderCommentPage();
  } catch (error) {
    container.innerHTML = `<div class="meeting-card"><p class="message-text">${escapeHtml(error.message || "Unable to load comments.")}</p></div>`;
  }
}

function renderCommentPage() {
  const total = commentState.comments.length;
  const totalPages = Math.max(1, Math.ceil(total / commentState.pageSize));
  if (commentState.page > totalPages) {
    commentState.page = totalPages;
  }

  const start = (commentState.page - 1) * commentState.pageSize;
  const end = start + commentState.pageSize;
  const pageItems = commentState.comments.slice(start, end);

  renderComments(pageItems, commentState.documentNameMap);
  renderPagination(totalPages, total);
}

function renderComments(comments, documentNameMap) {
  const container = document.getElementById("commentList");
  if (!container) {
    return;
  }

  if (!comments.length) {
    container.innerHTML = '<div class="meeting-card"><p class="message-text">No document comments yet.</p></div>';
    return;
  }

  container.innerHTML = comments.map(function (comment) {
    const tutor = comment.tutor_full_name || comment.tutor_user_name || "Tutor";
    const createdAt = formatDateTime(comment.created_at);
    const fileName = documentNameMap.get(Number(comment.document_id)) || `Document #${comment.document_id}`;
    const avatar = comment.tutor_profile_photo
      ? resolveAssetUrl(comment.tutor_profile_photo)
      : `${window.AppConfig.frontendBase}/Images/profile 2.jpg`;

    return `
      <div class="meeting-card">
        <div class="meeting-header">
          <div class="profile-info">
            <img src="${avatar}" alt="Tutor Profile" class="profile-pic">
            <div class="name-time">
              <span class="tutor-name">${escapeHtml(tutor)}</span>
              <span class="dot">.</span>
              <span class="message-time">${escapeHtml(createdAt)}</span>
              <div class="meeting-tabs">
                <button class="tab-btn active">${escapeHtml(fileName)}</button>
              </div>
            </div>
          </div>
        </div>
        <div class="message-section">
          <p class="message-text">${escapeHtml(comment.comment || "")}</p>
        </div>
      </div>
    `;
  }).join("");
}

function renderPagination(totalPages, totalItems) {
  const container = document.getElementById("commentList");
  if (!container) {
    return;
  }

  const wrapper = document.createElement("div");
  wrapper.style.display = "flex";
  wrapper.style.justifyContent = "space-between";
  wrapper.style.alignItems = "center";
  wrapper.style.marginTop = "16px";
  wrapper.style.gap = "12px";

  const info = document.createElement("div");
  info.className = "message-text";
  info.textContent = `Page ${commentState.page} / ${Math.max(1, totalPages)} (${totalItems} comments)`;

  const controls = document.createElement("div");
  controls.style.display = "flex";
  controls.style.gap = "8px";

  const prev = document.createElement("button");
  prev.type = "button";
  prev.textContent = "Prev";
  prev.className = "tab-btn";
  prev.disabled = commentState.page <= 1;
  prev.style.opacity = prev.disabled ? "0.5" : "1";
  prev.style.cursor = prev.disabled ? "not-allowed" : "pointer";
  prev.addEventListener("click", function () {
    if (commentState.page > 1) {
      commentState.page -= 1;
      renderCommentPage();
    }
  });

  const next = document.createElement("button");
  next.type = "button";
  next.textContent = "Next";
  next.className = "tab-btn";
  next.disabled = commentState.page >= totalPages;
  next.style.opacity = next.disabled ? "0.5" : "1";
  next.style.cursor = next.disabled ? "not-allowed" : "pointer";
  next.addEventListener("click", function () {
    if (commentState.page < totalPages) {
      commentState.page += 1;
      renderCommentPage();
    }
  });

  controls.appendChild(prev);
  controls.appendChild(next);
  wrapper.appendChild(info);
  wrapper.appendChild(controls);
  container.appendChild(wrapper);
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

function getFileName(filePath) {
  if (!filePath) {
    return "Document";
  }

  const normalized = String(filePath).split("?")[0];
  const parts = normalized.split("/");
  return parts[parts.length - 1] || normalized;
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

function escapeHtml(value) {
  return String(value ?? "")
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#39;");
}
