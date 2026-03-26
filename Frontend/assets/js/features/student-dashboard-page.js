document.addEventListener("DOMContentLoaded", async function () {
  const viewContext = getViewContext("student");
  const user = window.Auth.requireAuth(["student", "staff"]);
  if (!user) {
    return;
  }

  const isStaffScopedPage = window.location.pathname.toLowerCase().includes("/pages/staff/");
  if (isStaffScopedPage && user.role !== "staff") {
    window.location.replace("../Student/Student_Dashboard.html");
    return;
  }

  if (user.role === "staff" && !viewContext.enabled) {
    window.location.replace("../Staff/Staff_Dashboard.html");
    return;
  }

  const logoutLink = document.querySelector(".logout");
  if (logoutLink) {
    logoutLink.addEventListener("click", function (event) {
      event.preventDefault();
      window.Auth.logout();
    });
  }

  await Promise.allSettled([
    loadDashboardMetrics(viewContext),
    loadLastLogin()
  ]);
  await Promise.allSettled([
    loadRecentMeetings(),
    loadRecentComments()
  ]);
});

const studentDashboardState = {
  data: null
};

async function loadDashboardMetrics(viewContext) {
  try {
    const response = viewContext.enabled
      ? await window.ApiClient.get("dashboard", "userDashboard", { user_id: viewContext.userId })
      : await window.ApiClient.get("dashboard");
    const data = response.data || {};
    studentDashboardState.data = data;
    const metrics = data.metrics || {};
    const summary = data.summary || {};

    const scheduledMeetings = Number(summary.scheduled_meetings ?? metrics.scheduled_meetings ?? 0);
    const unreadMessages = Number(summary.unread_messages ?? metrics.unread_messages ?? 0);
    const myDocuments = Number(summary.documents_uploaded ?? metrics.my_documents ?? 0);

    setText("meetingCount", scheduledMeetings);
    setText(
      "meetingSummary",
      scheduledMeetings === 1
        ? "You currently have 1 scheduled meeting"
        : `You currently have ${scheduledMeetings} scheduled meetings`
    );

    setText(
      "unreadMessageCount",
      unreadMessages === 1 ? "1 unread message" : `${unreadMessages} unread messages`
    );

    setText("documentCount", myDocuments);
    setText(
      "documentSummary",
      myDocuments === 1 ? "1 document uploaded" : `${myDocuments} documents uploaded`
    );

    const chatAvatar = document.getElementById("chatPreviewAvatar");
    const tutorPhoto = data.personal_tutor?.profile_photo || "";
    const tutorName = data.personal_tutor?.full_name || "Tutor";
    if (chatAvatar) {
      chatAvatar.src = tutorPhoto ? resolveAssetUrl(tutorPhoto) : getAvatarFromName(tutorName);
    }

    const titleNode = document.getElementById("staffViewTitle");
    if (titleNode && viewContext.enabled) {
      const studentName = data.student?.full_name || "Student";
      titleNode.textContent = `Student Dashboard (Staff View): ${studentName}`;
    }
  } catch (error) {
    setText("meetingSummary", "Unable to load dashboard metrics.");
    setText("unreadMessageCount", "Unable to load unread message count.");
    setText("documentSummary", "Unable to load document count.");
  }
}

async function loadLastLogin() {
  try {
    const response = await window.ApiClient.get("dashboard", "lastLogin");
    const lastLogin = response.data?.last_login || null;
    setText("lastLoginValue", formatDateTime(lastLogin) || "N/A");
  } catch (error) {
    setText("lastLoginValue", "N/A");
  }
}

async function loadRecentMeetings() {
  const container = document.getElementById("upcomingMeetings");

  if (!container) {
    return;
  }

  try {
    let meetings = [];
    const fromDashboard = studentDashboardState.data?.upcoming_meetings;
    if (Array.isArray(fromDashboard)) {
      meetings = fromDashboard.slice(0, 2);
    } else {
      const response = await window.ApiClient.get("meeting");
      meetings = Array.isArray(response.data) ? response.data.slice(0, 2) : [];
    }

    if (meetings.length === 0) {
      container.innerHTML = '<div class="meeting-card"><div class="meeting-info"><h3>No meetings</h3><p>No upcoming or recorded meetings yet.</p></div></div>';
      return;
    }

    container.innerHTML = meetings
      .map(function (meeting) {
        const meta = parseMeetingMeta(meeting.outcome || "");
        const title = escapeHtml(meta.title || (meeting.meeting_type || "Meeting").toUpperCase());
        const timeLabel = formatTime(meeting.meeting_time);
        const dateLabel = formatDate(meeting.meeting_date);
        const statusLabel = escapeHtml(meeting.status || "");
        const buttonLabel = meeting.meeting_link ? "Open Link" : "View Meeting";
        const linkAttr = meeting.meeting_link
          ? ` data-meeting-link="${escapeAttribute(meeting.meeting_link)}"`
          : "";

        return `
          <div class="meeting-card">
            <img src="../../Images/meeting example pic.jpg" alt="Meeting image">
            <div class="meeting-info">
              <h3>${title}</h3>
              <p>${timeLabel}</p>
              <p class="date">${dateLabel}${statusLabel ? ` • ${statusLabel}` : ""}</p>
              <button class="remind-btn"${linkAttr}>${buttonLabel}</button>
            </div>
          </div>
        `;
      })
      .join("");

    container.querySelectorAll("[data-meeting-link]").forEach(function (button) {
      button.addEventListener("click", function () {
        const meetingLink = button.getAttribute("data-meeting-link");
        if (meetingLink) {
          window.open(meetingLink, "_blank", "noopener");
        }
      });
    });
  } catch (error) {
    container.innerHTML = '<div class="meeting-card"><div class="meeting-info"><h3>Meetings unavailable</h3><p>Unable to load meetings right now.</p></div></div>';
  }
}

async function loadRecentComments() {
  const container = document.getElementById("recentComments");

  if (!container) {
    return;
  }

  try {
    const feedback = Array.isArray(studentDashboardState.data?.recent_document_feedback)
      ? studentDashboardState.data.recent_document_feedback.slice(0, 4)
      : [];
    const comments = feedback.length ? feedback : null;

    if (!comments) {
      const response = await window.ApiClient.get("blog_comment");
      const fallback = Array.isArray(response.data) ? response.data.slice(0, 4) : [];
      renderCommentRows(fallback, false, container);
      return;
    }

    renderCommentRows(comments, true, container);
  } catch (error) {
    container.innerHTML = '<div class="comment-item"><div class="flex items-center gap-3"><p>Unable to load comments right now.</p></div></div>';
  }
}

function renderCommentRows(comments, isTutorFeedback, container) {
  if (!container) {
    return;
  }
  if (!comments.length) {
    container.innerHTML = '<div class="comment-item"><div class="flex items-center gap-3"><p>No comments available yet.</p></div></div>';
    return;
  }

  container.innerHTML = comments
    .map(function (comment) {
      const displayName = isTutorFeedback
        ? (comment.tutor_full_name || "Tutor")
        : (comment.full_name || comment.display_name || comment.user_name || "Unknown user");
      const avatarPath = isTutorFeedback ? (comment.tutor_profile_photo || "") : (comment.profile_photo || "");
      const avatar = avatarPath ? resolveAssetUrl(avatarPath) : getAvatarFromName(displayName);
      const preview = isTutorFeedback
        ? (comment.comment || "")
        : "";
      return `
        <div class="comment-item">
          <div class="flex items-center gap-3">
            <img src="${avatar}" class="w-10 h-10 rounded-full" alt="User avatar">
            <div>
              <p>${escapeHtml(displayName)}</p>
              ${preview ? `<p class="text-xs text-gray-500">${escapeHtml(preview.slice(0, 60))}${preview.length > 60 ? "..." : ""}</p>` : ""}
            </div>
          </div>
          <div class="flex items-center gap-3 text-gray-500">
            <i class="bi bi-chat"></i>
            <div class="w-px h-5 bg-gray-300"></div>
            <i class="bi bi-three-dots-vertical"></i>
          </div>
        </div>
      `;
    })
    .join("");
}

function setText(id, value) {
  const element = document.getElementById(id);
  if (element) {
    element.textContent = value;
  }
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

function formatTime(value) {
  if (!value) {
    return "Time not set";
  }

  const parts = String(value).split(":");
  if (parts.length < 2) {
    return value;
  }

  const date = new Date();
  date.setHours(Number(parts[0]), Number(parts[1]), 0, 0);

  return date.toLocaleTimeString("en-US", {
    hour: "numeric",
    minute: "2-digit"
  });
}

function escapeHtml(value) {
  return String(value)
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#39;");
}

function escapeAttribute(value) {
  return String(value)
    .replace(/&/g, "&amp;")
    .replace(/"/g, "&quot;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;");
}

function getAvatarFromName(name) {
  const safeName = String(name || "User").trim() || "User";
  const initials = safeName
    .split(/\s+/)
    .slice(0, 2)
    .map(function (part) { return part.charAt(0).toUpperCase(); })
    .join("") || "U";
  const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="48" height="48"><rect width="100%" height="100%" fill="#1d4ed8"/><text x="50%" y="52%" dominant-baseline="middle" text-anchor="middle" font-family="Arial" font-size="18" fill="#ffffff">${initials}</text></svg>`;
  return `data:image/svg+xml;utf8,${encodeURIComponent(svg)}`;
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

function parseMeetingMeta(outcome) {
  const text = String(outcome || "");
  const titleMatch = text.match(/\[title:([^\]]+)\]/i);
  return {
    title: titleMatch ? titleMatch[1].trim() : ""
  };
}

function getViewContext(expectedRole) {
  const params = new URLSearchParams(window.location.search || "");
  const viewUserId = Number(params.get("view_user_id") || 0);
  const viewRole = String(params.get("view_role") || "").toLowerCase();
  const enabled = Number.isFinite(viewUserId) && viewUserId > 0 && viewRole === expectedRole;

  return {
    enabled: enabled,
    userId: enabled ? viewUserId : 0
  };
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
