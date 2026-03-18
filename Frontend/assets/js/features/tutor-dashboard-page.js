document.addEventListener("DOMContentLoaded", async function () {
  const user = window.Auth.requireAuth(["tutor"]);
  if (!user) {
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
    loadDashboardMetrics(),
    loadLastLogin(),
    loadRecentMeetings(),
    loadRecentComments()
  ]);
});

async function loadDashboardMetrics() {
  try {
    const response = await window.ApiClient.get("dashboard");
    const data = response.data || {};
    const metrics = data.metrics || {};

    const scheduledMeetings = Number(metrics.scheduled_meetings || 0);
    const unreadMessages = Number(metrics.unread_messages || 0);
    const assignedStudents = Number(metrics.active_assigned_students || 0);

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

    setText("assignedStudentCount", assignedStudents);
    setText(
      "assignedStudentSummary",
      assignedStudents === 1 ? "1 active student assigned" : `${assignedStudents} active students assigned`
    );
  } catch (error) {
    setText("meetingSummary", "Unable to load dashboard metrics.");
    setText("unreadMessageCount", "Unable to load unread message count.");
    setText("assignedStudentSummary", "Unable to load assigned students.");
  }
}

async function loadLastLogin() {
  try {
    const response = await window.ApiClient.get("dashboard", "lastLogin");
    const lastLogin = response.data?.last_login || null;
    setText("lastLoginValue", formatDate(lastLogin) || "N/A");
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
    const response = await window.ApiClient.get("meeting");
    const meetings = Array.isArray(response.data) ? response.data.slice(0, 2) : [];

    if (meetings.length === 0) {
      container.innerHTML = '<div class="meeting-card"><div class="meeting-info"><h3>No meetings</h3><p>No upcoming or recorded meetings yet.</p></div></div>';
      return;
    }

    container.innerHTML = meetings
      .map(function (meeting) {
        const title = escapeHtml((meeting.meeting_type || "Meeting").toUpperCase());
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
    const response = await window.ApiClient.get("blog_comment");
    const comments = Array.isArray(response.data) ? response.data.slice(0, 4) : [];

    if (comments.length === 0) {
      container.innerHTML = '<div class="comment-item"><div class="flex items-center gap-3"><p>No comments available yet.</p></div></div>';
      return;
    }

    container.innerHTML = comments
      .map(function (comment, index) {
        const avatar = index % 2 === 0 ? "../../Images/profile.jpg" : "../../Images/profile 2.jpg";
        return `
          <div class="comment-item">
            <div class="flex items-center gap-3">
              <img src="${avatar}" class="w-10 h-10 rounded-full" alt="User avatar">
              <p>${escapeHtml(comment.user_name || "Unknown user")}</p>
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
  } catch (error) {
    container.innerHTML = '<div class="comment-item"><div class="flex items-center gap-3"><p>Unable to load comments right now.</p></div></div>';
  }
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
