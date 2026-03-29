document.addEventListener("DOMContentLoaded", async function () {
  const role = document.body.dataset.meetingRole || "";
  if (!role) {
    return;
  }

  const user = window.Auth.requireAuth([role]);
  if (!user) {
    return;
  }

  bindLogout();
  await loadLastLogin();

  if (role === "student") {
    await initializeStudentMeetings();
    return;
  }

  if (role === "tutor") {
    await initializeTutorMeetings();
  }
});

const meetingState = {
  meetings: [],
  myTutor: null,
  currentTutor: null,
  studentMap: new Map(),
  page: 1,
  pageSize: 6
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

async function initializeStudentMeetings() {
  const container = document.getElementById("meetingContainer");
  if (!container) {
    return;
  }

  container.innerHTML = '<div class="meeting-card"><p class="message-text">Loading meetings...</p></div>';

  try {
    const [meetingsResponse, tutorResponse] = await Promise.allSettled([
      window.ApiClient.get("meeting"),
      window.ApiClient.get("allocation", "myTutor")
    ]);

    meetingState.meetings = meetingsResponse.status === "fulfilled" && Array.isArray(meetingsResponse.value.data)
      ? meetingsResponse.value.data
      : [];
    meetingState.page = 1;

    meetingState.myTutor = tutorResponse.status === "fulfilled"
      ? (tutorResponse.value.data || null)
      : null;

    renderStudentMeetings();
  } catch (error) {
    container.innerHTML = `<div class="meeting-card"><p class="message-text">${escapeHtml(error.message || "Unable to load meetings.")}</p></div>`;
  }
}

async function initializeTutorMeetings() {
  bindTutorForm();
  toggleMeetingTypeFields();

  await Promise.allSettled([
    loadTutorIdentity(),
    loadAssignedStudents(),
    loadTutorMeetings()
  ]);
}

async function loadTutorIdentity() {
  try {
    const response = await window.ApiClient.get("user", "me");
    const data = response.data || {};
    const profile = data.profile || {};

    meetingState.currentTutor = {
      name: profile.full_name || profile.display_name || data.full_name || data.user_name || "Tutor",
      secondary: data.email || profile.department || data.user_name || "",
      image: profile.profile_photo ? resolveAssetUrl(profile.profile_photo) : getAvatarFromName(profile.full_name || data.full_name || data.user_name || "Tutor")
    };
  } catch (error) {
    const fallbackUser = window.AuthStorage?.getUser?.() || {};
    const fallbackName = fallbackUser.full_name || fallbackUser.user_name || "Tutor";
    meetingState.currentTutor = {
      name: fallbackName,
      secondary: fallbackUser.email || fallbackUser.user_name || "",
      image: getAvatarFromName(fallbackName)
    };
  }
}

function bindTutorForm() {
  const postButton = document.getElementById("postMeetingBtn");
  const typeSelect = document.getElementById("meetingType");
  const meetingContainer = document.getElementById("meetingContainer");

  if (typeSelect) {
    typeSelect.addEventListener("change", toggleMeetingTypeFields);
  }

  if (postButton) {
    postButton.addEventListener("click", createTutorMeeting);
  }

  if (meetingContainer) {
    meetingContainer.addEventListener("click", handleMeetingCardAction);
  }
}

function toggleMeetingTypeFields() {
  const type = getValue("meetingType") || "virtual";
  const virtualFields = document.getElementById("virtualFields");
  const physicalFields = document.getElementById("physicalFields");

  if (virtualFields) {
    virtualFields.style.display = type === "virtual" ? "block" : "none";
  }
  if (physicalFields) {
    physicalFields.style.display = type === "physical" ? "block" : "none";
  }
}

async function loadAssignedStudents() {
  const select = document.getElementById("meetingStudent");
  if (!select) {
    return;
  }

  try {
    const response = await window.ApiClient.get("allocation", "assignedStudents");
    const rows = Array.isArray(response.data) ? response.data : [];
    const map = new Map();

    rows.forEach(function (row) {
      const studentId = Number(row.student_id || 0);
      if (!studentId) {
        return;
      }

      map.set(studentId, {
        name: row.student_full_name || row.student_user_name || `Student ${studentId}`,
        email: row.student_email || "",
        programme: row.student_programme || ""
      });
    });

    meetingState.studentMap = map;
    select.innerHTML = "";

    if (!map.size) {
      select.innerHTML = '<option value="">No assigned students</option>';
      return;
    }

    map.forEach(function (student, studentId) {
      const option = document.createElement("option");
      option.value = String(studentId);
      option.textContent = `${student.name} (${studentId})`;
      select.appendChild(option);
    });
  } catch (error) {
    select.innerHTML = '<option value="">Unable to load students</option>';
    setStatus("meetingStatus", error.message || "Unable to load assigned students.", true);
  }
}

async function loadTutorMeetings() {
  const container = document.getElementById("meetingContainer");
  if (!container) {
    return;
  }

  container.innerHTML = '<div class="meeting-card"><p class="message-text">Loading meetings...</p></div>';

  try {
    const response = await window.ApiClient.get("meeting");
    meetingState.meetings = Array.isArray(response.data) ? response.data : [];
    meetingState.page = 1;
    renderTutorMeetings();
  } catch (error) {
    container.innerHTML = `<div class="meeting-card"><p class="message-text">${escapeHtml(error.message || "Unable to load meetings.")}</p></div>`;
  }
}

async function createTutorMeeting() {
  const postButton = document.getElementById("postMeetingBtn");
  const studentId = Number(getValue("meetingStudent"));
  const meetingDate = getValue("meetingDate");
  const meetingTime = getValue("meetingTime");
  const meetingType = getValue("meetingType") || "virtual";
  const meetingName = getValue("meetingName").trim();
  const note = getValue("meetingMessage").trim();
  const meetingPlatform = getValue("meetingPlatform").trim();
  const meetingLink = getValue("meetingLink").trim();
  const meetingLocation = getValue("meetingLocation").trim();
  const selectedStudent = meetingState.studentMap.get(studentId) || null;
  const studentName = selectedStudent?.name || "";

  setStatus("meetingStatus", "", false);

  if (!studentId || !meetingDate || !meetingTime) {
    setStatus("meetingStatus", "Student, date, and time are required.", true);
    return;
  }

  if (meetingType === "virtual" && (!meetingPlatform || !meetingLink)) {
    setStatus("meetingStatus", "Platform and link are required for virtual meetings.", true);
    return;
  }

  if (meetingType === "physical" && !meetingLocation) {
    setStatus("meetingStatus", "Location is required for physical meetings.", true);
    return;
  }

  const encodedOutcome = `${meetingName ? `[title:${meetingName}]` : ""}${studentName ? `${meetingName ? " " : ""}[student:${studentName}]` : ""}${note ? `${meetingName || studentName ? " " : ""}${note}` : ""}`.trim();
  const payload = {
    student_id: studentId,
    meeting_date: meetingDate,
    meeting_time: meetingTime,
    meeting_type: meetingType,
    outcome: encodedOutcome,
    status: "scheduled"
  };

  if (meetingType === "virtual") {
    payload.meeting_platform = meetingPlatform;
    payload.meeting_link = meetingLink;
  } else {
    payload.meeting_location = meetingLocation;
  }

  if (postButton) {
    postButton.disabled = true;
  }

  try {
    const response = await window.ApiClient.post("meeting", "", payload);
    setStatus("meetingStatus", response.message || "Meeting created successfully.", false);
    setValue("meetingName", "");
    setValue("meetingMessage", "");
    if (meetingType === "virtual") {
      setValue("meetingLink", "");
    } else {
      setValue("meetingLocation", "");
    }
    await loadTutorMeetings();
  } catch (error) {
    setStatus("meetingStatus", error.message || "Unable to create meeting.", true);
  } finally {
    if (postButton) {
      postButton.disabled = false;
    }
  }
}

function renderStudentMeetings() {
  const container = document.getElementById("meetingContainer");
  if (!container) {
    return;
  }

  const totalItems = meetingState.meetings.length;
  if (!totalItems) {
    container.innerHTML = '<div class="meeting-card"><p class="message-text">No meetings available.</p></div>';
    renderMeetingPagination(0);
    return;
  }
  const totalPages = Math.max(1, Math.ceil(totalItems / meetingState.pageSize));
  if (meetingState.page > totalPages) {
    meetingState.page = totalPages;
  }
  const start = (meetingState.page - 1) * meetingState.pageSize;
  const pagedMeetings = meetingState.meetings.slice(start, start + meetingState.pageSize);

  const tutorName = meetingState.myTutor?.tutor_full_name
    || meetingState.myTutor?.tutor_user_name
    || "Tutor";
  const tutorEmail = meetingState.myTutor?.tutor_email || "";
  const tutorPhoto = meetingState.myTutor?.tutor_profile_photo
    ? resolveAssetUrl(meetingState.myTutor.tutor_profile_photo)
    : getAvatarFromName(tutorName);

  container.innerHTML = pagedMeetings.map(function (meeting) {
    return renderMeetingCard({
      displayName: tutorName,
      displayEmail: tutorEmail,
      displayImage: tutorPhoto,
      meeting
    });
  }).join("");
  renderMeetingPagination(totalPages);
}

function renderTutorMeetings() {
  const container = document.getElementById("meetingContainer");
  if (!container) {
    return;
  }

  const totalItems = meetingState.meetings.length;
  if (!totalItems) {
    container.innerHTML = '<div class="meeting-card"><p class="message-text">No meetings available.</p></div>';
    renderMeetingPagination(0);
    return;
  }
  const totalPages = Math.max(1, Math.ceil(totalItems / meetingState.pageSize));
  if (meetingState.page > totalPages) {
    meetingState.page = totalPages;
  }
  const start = (meetingState.page - 1) * meetingState.pageSize;
  const pagedMeetings = meetingState.meetings.slice(start, start + meetingState.pageSize);

  const tutorName = meetingState.currentTutor?.name || "Tutor";
  const tutorSecondary = meetingState.currentTutor?.secondary || "";
  const tutorImage = meetingState.currentTutor?.image || getAvatarFromName(tutorName);

  container.innerHTML = pagedMeetings.map(function (meeting) {
    const student = meetingState.studentMap.get(Number(meeting.student_id)) || {
      name: `Student ${meeting.student_id}`,
      email: "",
      programme: ""
    };
    const meta = parseOutcomeMeta(meeting.outcome || "");
    const recipient = meta.student || student.name;

    return renderMeetingCard({
      displayName: tutorName,
      displayEmail: tutorSecondary,
      displayImage: tutorImage,
      targetLine: `To: ${recipient}`,
      meeting
    });
  }).join("");
  renderMeetingPagination(totalPages);
}

function renderMeetingPagination(totalPages) {
  const anchor = document.getElementById("meetingContainer");
  if (!anchor) {
    return;
  }
  const hostId = "meetingPagination";
  let host = document.getElementById(hostId);
  if (!host) {
    host = document.createElement("div");
    host.id = hostId;
    host.className = "flex items-center justify-end gap-3 mt-4";
    anchor.insertAdjacentElement("afterend", host);
  }

  if (totalPages <= 1) {
    host.innerHTML = "";
    return;
  }

  host.innerHTML = `
    <button type="button" id="meetingPrevPageBtn" class="px-3 py-1 rounded border border-gray-300 bg-white text-sm">Prev</button>
    <span class="text-sm text-gray-600">Page ${meetingState.page} / ${totalPages}</span>
    <button type="button" id="meetingNextPageBtn" class="px-3 py-1 rounded border border-gray-300 bg-white text-sm">Next</button>
  `;

  const prev = document.getElementById("meetingPrevPageBtn");
  const next = document.getElementById("meetingNextPageBtn");

  if (prev) {
    prev.disabled = meetingState.page <= 1;
    prev.addEventListener("click", function () {
      if (meetingState.page <= 1) {
        return;
      }
      meetingState.page -= 1;
      if (documentStateFromMeetingRole() === "student") {
        renderStudentMeetings();
      } else {
        renderTutorMeetings();
      }
    });
  }
  if (next) {
    next.disabled = meetingState.page >= totalPages;
    next.addEventListener("click", function () {
      if (meetingState.page >= totalPages) {
        return;
      }
      meetingState.page += 1;
      if (documentStateFromMeetingRole() === "student") {
        renderStudentMeetings();
      } else {
        renderTutorMeetings();
      }
    });
  }
}

function documentStateFromMeetingRole() {
  return String(document.body.dataset.meetingRole || "").toLowerCase();
}

async function handleMeetingCardAction(event) {
  const completeButton = event.target.closest("[data-complete-meeting-id]");
  if (!completeButton) {
    return;
  }

  const meetingId = Number(completeButton.getAttribute("data-complete-meeting-id"));
  const noteInput = document.querySelector(`[data-complete-note="${meetingId}"]`);
  const completionNote = noteInput ? String(noteInput.value || "").trim() : "";

  if (!meetingId) {
    return;
  }

  if (!completionNote) {
    setStatus("meetingStatus", "Please enter a tutor note before completing the meeting.", true);
    if (noteInput) {
      noteInput.focus();
    }
    return;
  }

  const meeting = meetingState.meetings.find(function (item) {
    return Number(item.meeting_id) === meetingId;
  });

  if (!meeting) {
    setStatus("meetingStatus", "Meeting not found.", true);
    return;
  }

  const meta = parseOutcomeMeta(meeting.outcome || "");
  const outcomeParts = [];

  if (meta.platform) {
    outcomeParts.push(`[platform:${meta.platform}]`);
  }
  if (meta.location) {
    outcomeParts.push(`[location:${meta.location}]`);
  }
  if (meta.title) {
    outcomeParts.push(`[title:${meta.title}]`);
  }
  if (meta.student) {
    outcomeParts.push(`[student:${meta.student}]`);
  }
  if (meta.note) {
    outcomeParts.push(meta.note);
  }
  outcomeParts.push(`[completed_note:${completionNote}]`);

  completeButton.disabled = true;

  const payload = {
    id: meetingId,
    meeting_type: meeting.meeting_type,
    meeting_link: meeting.meeting_link || "",
    outcome: outcomeParts.join(" ").trim(),
    status: "completed"
  };

  if (meeting.meeting_type === "virtual") {
    payload.meeting_platform = meta.platform || "Online";
  } else if (meeting.meeting_type === "physical") {
    payload.meeting_location = meta.location || "Location not specified";
  }

  try {
    const response = await window.ApiClient.put("meeting", "", payload);

    setStatus("meetingStatus", response.message || "Meeting marked as completed.", false);
    await loadTutorMeetings();
  } catch (error) {
    setStatus("meetingStatus", error.message || "Unable to complete meeting.", true);
  } finally {
    completeButton.disabled = false;
  }
}

function renderMeetingCard(data) {
  const meta = parseOutcomeMeta(data.meeting.outcome || "");
  const scheduleLabel = `${formatDate(data.meeting.meeting_date)} ${formatTime(data.meeting.meeting_time)}`.trim();
  const displayTitle = meta.title || (data.meeting.meeting_type || "Meeting").toUpperCase();
  const isTutorView = documentStateFromMeetingRole() === "tutor";
  const isCompleted = String(data.meeting.status || "").toLowerCase() === "completed";
  let messageBody = meta.note || "";

  if (data.meeting.meeting_type === "virtual") {
    const platform = meta.platform || "Online";
    const link = data.meeting.meeting_link || "";
    messageBody = link
      ? `${platform} meeting link: <a href="${escapeHtml(link)}" target="_blank" rel="noopener">${escapeHtml(link)}</a>${messageBody ? `<br>${escapeHtml(messageBody)}` : ""}`
      : `${platform} meeting scheduled.${messageBody ? `<br>${escapeHtml(messageBody)}` : ""}`;
  } else if (data.meeting.meeting_type === "physical") {
    const location = meta.location || "Location not specified";
    messageBody = `Physical meeting at ${escapeHtml(location)}.${messageBody ? `<br>${escapeHtml(messageBody)}` : ""}`;
  } else {
    messageBody = escapeHtml(messageBody || "Meeting details available.");
  }

  const completionBlock = meta.completion
    ? `<div class="meeting-completion-note"><p class="meeting-completion-label">Tutor note</p><p class="meeting-completion-text">${escapeHtml(meta.completion)}</p></div>`
    : "";

  const completionEditor = isTutorView && !isCompleted
    ? `
      <div class="meeting-complete-box">
        <textarea class="meeting-complete-input" data-complete-note="${escapeAttribute(data.meeting.meeting_id)}" placeholder="Add completion note..."></textarea>
        <div class="meeting-complete-actions">
          <p class="meeting-complete-hint">Save a tutor note and mark this meeting as completed.</p>
          <button type="button" class="meeting-complete-btn" data-complete-meeting-id="${escapeAttribute(data.meeting.meeting_id)}">Mark as Completed</button>
        </div>
      </div>
    `
    : "";

  return `
    <div class="meeting-card">
      <div class="flex items-center gap-4 mb-4">
        <div>
          <p class="tutor-name">${escapeHtml(data.displayName)}</p>
          <p class="tutor-email">${escapeHtml(data.displayEmail)}</p>
          ${data.targetLine ? `<p class="tutor-email">${escapeHtml(data.targetLine)}</p>` : ""}
          <p class="tutor-email">${escapeHtml(displayTitle)}</p>
        </div>
      </div>
      <div class="message-section">
        <p class="message-text">${messageBody}</p>
        ${completionBlock}
        <p class="message-time">
          ${escapeHtml(scheduleLabel)} | Status: ${escapeHtml(data.meeting.status || "scheduled")}
        </p>
      </div>
      ${completionEditor}
    </div>
  `;
}

function parseOutcomeMeta(outcome) {
  let text = String(outcome || "");
  let platform = "";
  let location = "";
  let title = "";
  let student = "";
  let completion = "";

  const titleMatch = text.match(/\[title:([^\]]+)\]/i);
  if (titleMatch) {
    title = titleMatch[1].trim();
    text = text.replace(titleMatch[0], "").trim();
  }

  const studentMatch = text.match(/\[student:([^\]]+)\]/i);
  if (studentMatch) {
    student = studentMatch[1].trim();
    text = text.replace(studentMatch[0], "").trim();
  }

  const platformMatch = text.match(/\[platform:([^\]]+)\]/i);
  if (platformMatch) {
    platform = platformMatch[1].trim();
    text = text.replace(platformMatch[0], "").trim();
  }

  const locationMatch = text.match(/\[location:([^\]]+)\]/i);
  if (locationMatch) {
    location = locationMatch[1].trim();
    text = text.replace(locationMatch[0], "").trim();
  }

  const completionMatch = text.match(/\[completed_note:([^\]]+)\]/i);
  if (completionMatch) {
    completion = completionMatch[1].trim();
    text = text.replace(completionMatch[0], "").trim();
  }

  return {
    title,
    student,
    platform,
    location,
    completion,
    note: text
  };
}

function getValue(id) {
  const element = document.getElementById(id);
  return element ? String(element.value || "") : "";
}

function setValue(id, value) {
  const element = document.getElementById(id);
  if (element) {
    element.value = value;
  }
}

function setStatus(id, message, isError) {
  const element = document.getElementById(id);
  if (!element) {
    return;
  }

  element.textContent = message;
  element.className = `text-sm mt-2 ${isError ? "text-red-500" : "text-green-600"}`;
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
    return "";
  }

  const normalized = String(value).length === 5 ? `${value}:00` : value;
  const date = new Date(`1970-01-01T${normalized}`);
  if (Number.isNaN(date.getTime())) {
    return value;
  }

  return date.toLocaleTimeString("en-GB", {
    hour: "2-digit",
    minute: "2-digit"
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

function escapeAttribute(value) {
  return escapeHtml(value).replace(/`/g, "&#96;");
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

