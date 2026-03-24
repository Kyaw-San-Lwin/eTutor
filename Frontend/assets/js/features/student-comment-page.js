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
      window.ApiClient.get("document", "", { limit: 200, offset: 0 })
    ]);

    const comments = Array.isArray(commentsResponse.data) ? commentsResponse.data : [];
    const documents = Array.isArray(documentsResponse.data) ? documentsResponse.data : [];
    const documentNameMap = new Map();
    documents.forEach(function (doc) {
      documentNameMap.set(Number(doc.document_id), getFileName(doc.file_path));
    });

    renderComments(comments, documentNameMap);
  } catch (error) {
    container.innerHTML = `<div class="meeting-card"><p class="message-text">${escapeHtml(error.message || "Unable to load comments.")}</p></div>`;
  }
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
    const tutor = comment.tutor_user_name || "Tutor";
    const createdAt = formatDateTime(comment.created_at);
    const fileName = documentNameMap.get(Number(comment.document_id)) || `Document #${comment.document_id}`;

    return `
      <div class="meeting-card">
        <div class="meeting-header">
          <div class="profile-info">
            <img src="${window.AppConfig.frontendBase}/Images/profile 2.jpg" alt="Tutor Profile" class="profile-pic">
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

function escapeHtml(value) {
  return String(value ?? "")
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#39;");
}
