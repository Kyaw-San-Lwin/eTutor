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
  studentMap: new Map()
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
    target.textContent = formatDate(response.data?.last_login) || "N/A";
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
    loadAssignedStudents(),
    loadTutorMeetings()
  ]);
}

function bindTutorForm() {
  const postButton = document.getElementById("postMeetingBtn");
  const typeSelect = document.getElementById("meetingType");

  if (typeSelect) {
    typeSelect.addEventListener("change", toggleMeetingTypeFields);
  }

  if (postButton) {
    postButton.addEventListener("click", createTutorMeeting);
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
  const note = getValue("meetingMessage").trim();
  const meetingPlatform = getValue("meetingPlatform").trim();
  const meetingLink = getValue("meetingLink").trim();
  const meetingLocation = getValue("meetingLocation").trim();

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

  const payload = {
    student_id: studentId,
    meeting_date: meetingDate,
    meeting_time: meetingTime,
    meeting_type: meetingType,
    outcome: note,
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

  if (!meetingState.meetings.length) {
    container.innerHTML = '<div class="meeting-card"><p class="message-text">No meetings available.</p></div>';
    return;
  }

  const tutorName = meetingState.myTutor?.tutor_full_name
    || meetingState.myTutor?.tutor_user_name
    || "Tutor";
  const tutorEmail = meetingState.myTutor?.tutor_email || "";
  const tutorPhoto = meetingState.myTutor?.tutor_profile_photo
    ? resolveAssetUrl(meetingState.myTutor.tutor_profile_photo)
    : `${window.AppConfig.frontendBase}/Images/profile 2.jpg`;

  container.innerHTML = meetingState.meetings.map(function (meeting) {
    return renderMeetingCard({
      displayName: tutorName,
      displayEmail: tutorEmail,
      displayImage: tutorPhoto,
      meeting
    });
  }).join("");
}

function renderTutorMeetings() {
  const container = document.getElementById("meetingContainer");
  if (!container) {
    return;
  }

  if (!meetingState.meetings.length) {
    container.innerHTML = '<div class="meeting-card"><p class="message-text">No meetings available.</p></div>';
    return;
  }

  container.innerHTML = meetingState.meetings.map(function (meeting) {
    const student = meetingState.studentMap.get(Number(meeting.student_id)) || {
      name: `Student ${meeting.student_id}`,
      email: "",
      programme: ""
    };
    const emailOrProgramme = student.email || student.programme || "";

    return renderMeetingCard({
      displayName: student.name,
      displayEmail: emailOrProgramme,
      displayImage: `${window.AppConfig.frontendBase}/Images/profile.jpg`,
      meeting
    });
  }).join("");
}

function renderMeetingCard(data) {
  const meta = parseOutcomeMeta(data.meeting.outcome || "");
  const scheduleLabel = `${formatDate(data.meeting.meeting_date)} ${formatTime(data.meeting.meeting_time)}`.trim();
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

  return `
    <div class="meeting-card">
      <div class="flex items-center gap-4 mb-4">
        <img src="${escapeHtml(data.displayImage)}" alt="Profile" class="profile-pic">
        <div>
          <p class="tutor-name">${escapeHtml(data.displayName)}</p>
          <p class="tutor-email">${escapeHtml(data.displayEmail)}</p>
        </div>
      </div>
      <div class="message-section">
        <p class="message-text">${messageBody}</p>
        <p class="message-time">
          ${escapeHtml(scheduleLabel)} | Status: ${escapeHtml(data.meeting.status || "scheduled")}
        </p>
      </div>
    </div>
  `;
}

function parseOutcomeMeta(outcome) {
  let text = String(outcome || "");
  let platform = "";
  let location = "";

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

  return {
    platform,
    location,
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
