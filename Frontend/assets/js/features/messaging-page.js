document.addEventListener("DOMContentLoaded", async function () {
  const user = window.Auth.requireAuth(["student", "tutor", "staff"]);
  if (!user) {
    return;
  }

  const state = {
    user: user,
    currentUserId: Number(user.id || user.user_id || 0),
    contacts: [],
    filteredContacts: [],
    selectedContactId: null,
    pollingId: null
  };

  const elements = {
    chatList: document.querySelector(".chat-list"),
    chatContainer: document.querySelector(".chat-container"),
    backBtn: document.querySelector(".back-btn"),
    chatName: document.querySelector(".chat-name"),
    chatStatus: document.querySelector(".chat-status"),
    chatAvatar: document.querySelector(".chat-avatar"),
    chatBody: document.getElementById("chatBody"),
    messageInput: document.getElementById("messageInput"),
    sendBtn: document.getElementById("sendBtn"),
    searchInput: document.getElementById("chatSearch"),
    contactsContainer: document.getElementById("contactList"),
    emptyState: document.getElementById("chatEmptyState"),
    lastLoginValue: document.getElementById("lastLoginValue")
  };

  bindStaticEvents(state, elements);

  await Promise.allSettled([
    loadLastLogin(elements),
    loadMessagingShell(state, elements)
  ]);
});

function bindStaticEvents(state, elements) {
  const logoutLink = document.querySelector(".logout");
  if (logoutLink) {
    logoutLink.addEventListener("click", function (event) {
      event.preventDefault();
      stopPolling(state);
      window.Auth.logout();
    });
  }

  if (elements.searchInput) {
    elements.searchInput.addEventListener("input", function () {
      const query = elements.searchInput.value.trim().toLowerCase();
      state.filteredContacts = state.contacts.filter(function (contact) {
        return contact.name.toLowerCase().includes(query);
      });
      renderContacts(state, elements);
    });
  }

  if (elements.backBtn) {
    elements.backBtn.addEventListener("click", function () {
      if (isMobile()) {
        showContactList(elements);
      }
    });
  }

  if (elements.sendBtn) {
    elements.sendBtn.addEventListener("click", function () {
      sendCurrentMessage(state, elements);
    });
  }

  if (elements.messageInput) {
    elements.messageInput.addEventListener("keypress", function (event) {
      if (event.key === "Enter" && !event.shiftKey) {
        event.preventDefault();
        sendCurrentMessage(state, elements);
      }
    });
  }

  window.addEventListener("resize", function () {
    if (isMobile()) {
      if (state.selectedContactId) {
        showConversation(elements);
      } else {
        showContactList(elements);
      }
    } else {
      if (elements.chatList) {
        elements.chatList.style.display = "block";
      }
      if (elements.chatContainer) {
        elements.chatContainer.style.display = "flex";
      }
      if (elements.backBtn) {
        elements.backBtn.classList.add("hidden");
      }
    }
  });

  window.addEventListener("pagehide", function () {
    stopPolling(state);
  });
}

async function loadLastLogin(elements) {
  if (!elements.lastLoginValue) {
    return;
  }

  try {
    const response = await window.ApiClient.get("dashboard", "lastLogin");
    elements.lastLoginValue.textContent = formatDateTime(response.data?.last_login) || "N/A";
  } catch (error) {
    elements.lastLoginValue.textContent = "N/A";
  }
}

async function loadMessagingShell(state, elements) {
  try {
    const [contacts, messages] = await Promise.all([
      loadBaseContacts(state.user),
      loadMessageSummaries()
    ]);

    state.contacts = mergeContactsWithMessages(state.currentUserId, contacts, messages);
    state.filteredContacts = state.contacts.slice();

    renderContacts(state, elements);

    const firstContact = state.filteredContacts[0] || null;
    if (firstContact) {
      await selectContact(state, elements, firstContact.id);
      if (isMobile()) {
        showContactList(elements);
      }
    } else {
      renderEmptyConversation(elements, "No contacts available yet.");
      if (isMobile()) {
        showContactList(elements);
      }
    }
  } catch (error) {
    renderContactLoadError(elements);
    renderEmptyConversation(elements, "Unable to load messaging right now.");
  }
}

async function loadBaseContacts(user) {
  if (user.role === "student") {
    const response = await window.ApiClient.get("allocation", "myTutor");
    const row = response.data || null;
    if (!row || !row.tutor_user_id) {
      return [];
    }

    return [{
      id: Number(row.tutor_user_id),
      name: row.tutor_full_name || row.tutor_user_name || "Allocated Tutor",
      email: row.tutor_email || "",
      subtitle: row.tutor_department || "Tutor",
      avatar: row.tutor_profile_photo
        ? resolveAssetUrl(row.tutor_profile_photo)
        : getAvatarFromName(row.tutor_full_name || row.tutor_user_name || "Tutor")
    }];
  }

  if (user.role === "tutor") {
    const response = await window.ApiClient.get("allocation", "assignedStudents");
    const rows = Array.isArray(response.data) ? response.data : [];

    return rows.map(function (row, index) {
      return {
        id: Number(row.student_user_id),
        name: row.student_full_name || row.student_user_name || "Assigned Student",
        email: row.student_email || "",
        subtitle: row.student_programme || "Student",
        avatar: row.student_profile_photo
          ? resolveAssetUrl(row.student_profile_photo)
          : getAvatarFromName(row.student_full_name || row.student_user_name || "Student")
      };
    });
  }

  if (user.role === "staff") {
    const response = await window.ApiClient.get("user", "", { limit: 500, offset: 0 });
    const rows = Array.isArray(response.data) ? response.data : [];

    return rows
      .filter(function (row) {
        const rowUserId = Number(row.user_id);
        const rowRole = String(row.role_name || "").toLowerCase();
        return rowUserId > 0 && rowUserId !== Number(user.user_id || user.id || 0)
          && (rowRole === "student" || rowRole === "tutor");
      })
      .map(function (row, index) {
        const roleLabel = String(row.role_name || "").toLowerCase();
        return {
          id: Number(row.user_id),
          name: row.full_name || row.user_name || "User",
          email: row.email || "",
          subtitle: roleLabel === "student"
            ? (row.programme || "Student")
            : (row.department || "Tutor"),
          avatar: row.profile_photo
            ? resolveAssetUrl(row.profile_photo)
            : getAvatarFromName(row.full_name || row.user_name || "User")
        };
      });
  }

  return [];
}

async function loadMessageSummaries() {
  const response = await window.ApiClient.get("message", "", { limit: 200, offset: 0 });
  return Array.isArray(response.data) ? response.data : [];
}

function mergeContactsWithMessages(currentUserId, contacts, messages) {
  const map = new Map();

  contacts.forEach(function (contact) {
    map.set(contact.id, Object.assign({
      lastMessage: "",
      lastSentAt: "",
      unreadCount: 0
    }, contact));
  });

  messages.forEach(function (message, index) {
    const otherUserId = Number(message.sender_id) === currentUserId
      ? Number(message.receiver_id)
      : Number(message.sender_id);

    if (!otherUserId) {
      return;
    }

    if (!map.has(otherUserId)) {
      const contactName = Number(message.sender_id) === currentUserId
        ? (message.receiver_name || "User")
        : (message.sender_name || "User");

      map.set(otherUserId, {
        id: otherUserId,
        name: contactName,
        email: "",
        subtitle: "",
        avatar: getAvatarFromName(contactName),
        lastMessage: "",
        lastSentAt: "",
        unreadCount: 0
      });
    }

    const contact = map.get(otherUserId);
    if (!contact.lastSentAt || new Date(message.sent_at) > new Date(contact.lastSentAt)) {
      contact.lastMessage = message.message || "";
      contact.lastSentAt = message.sent_at || "";
    }

    if (Number(message.receiver_id) === currentUserId && String(message.status) === "sent") {
      contact.unreadCount += 1;
    }
  });

  return Array.from(map.values()).sort(function (a, b) {
    return compareDatesDesc(a.lastSentAt, b.lastSentAt) || a.name.localeCompare(b.name);
  });
}

function renderContacts(state, elements) {
  const container = elements.contactsContainer;
  if (!container) {
    return;
  }

  if (state.filteredContacts.length === 0) {
    container.innerHTML = '<div class="p-4 text-sm text-gray-500">No contacts found.</div>';
    return;
  }

  container.innerHTML = state.filteredContacts
    .map(function (contact) {
      const activeClass = state.selectedContactId === contact.id ? " bg-gray-100" : "";
      const preview = escapeHtml(contact.lastMessage || contact.subtitle || "No messages yet");
      const unreadBadge = contact.unreadCount > 0
        ? `<span class="inline-flex items-center justify-center min-w-6 h-6 px-2 rounded-full bg-blue-700 text-white text-xs">${contact.unreadCount}</span>`
        : "";

      return `
        <button type="button" class="p-4 flex items-center gap-3 w-full text-start border-0 bg-transparent chat-profile${activeClass}" data-contact-id="${contact.id}">
          <img src="${contact.avatar}" class="w-12 h-12 rounded-full object-cover" alt="${escapeAttribute(contact.name)} avatar">
          <div class="flex-1 min-w-0">
            <p class="font-medium truncate">${escapeHtml(contact.name)}</p>
            <p class="text-sm text-gray-500 truncate">${preview}</p>
          </div>
          ${unreadBadge}
        </button>
      `;
    })
    .join("");

  container.querySelectorAll("[data-contact-id]").forEach(function (button) {
    button.addEventListener("click", function () {
      const contactId = Number(button.getAttribute("data-contact-id"));
      selectContact(state, elements, contactId);
    });
  });
}

async function selectContact(state, elements, contactId) {
  state.selectedContactId = Number(contactId);
  renderContacts(state, elements);

  const contact = state.contacts.find(function (item) {
    return item.id === state.selectedContactId;
  });

  if (!contact) {
    renderEmptyConversation(elements, "Conversation not found.");
    return;
  }

  updateHeader(contact, elements);
  showConversation(elements);
  await loadConversation(state, elements);
  startPolling(state, elements);
}

function updateHeader(contact, elements) {
  if (elements.chatName) {
    elements.chatName.textContent = contact.name;
  }
  if (elements.chatStatus) {
    elements.chatStatus.textContent = contact.subtitle || contact.email || "Messaging available";
  }
  if (elements.chatAvatar) {
    elements.chatAvatar.src = contact.avatar;
    elements.chatAvatar.alt = contact.name;
  }
}

async function loadConversation(state, elements) {
  if (!state.selectedContactId || !elements.chatBody) {
    return;
  }

  try {
    const response = await window.ApiClient.get("message", "", {
      with_user_id: state.selectedContactId,
      limit: 200,
      offset: 0
    });

    const messages = (Array.isArray(response.data) ? response.data : []).slice().reverse();
    renderConversation(messages, state.currentUserId, elements.chatBody);
    await markMessagesAsRead(messages, state.currentUserId);
    updateContactAfterRead(state);
    renderContacts(state, elements);
  } catch (error) {
    renderEmptyConversation(elements, "Unable to load conversation right now.");
  }
}

function renderConversation(messages, currentUserId, container) {
  if (!messages.length) {
    container.innerHTML = '<div class="text-center text-gray-500 mt-12">No messages yet. Start the conversation.</div>';
    return;
  }

  container.innerHTML = messages
    .map(function (message) {
      const outgoing = Number(message.sender_id) === currentUserId;
      const wrapperClass = outgoing ? "justify-end" : "justify-start";
      const bubbleClass = outgoing
        ? "bg-blue-800 text-white"
        : "bg-gray-200 text-gray-800";

      return `
        <div class="flex ${wrapperClass}">
          <div class="${bubbleClass} px-6 py-3 rounded-xl max-w-sm">
            <div>${escapeHtml(message.message || "")}</div>
            <div class="text-xs mt-2 ${outgoing ? "text-blue-100" : "text-gray-500"}">${escapeHtml(formatDateTime(message.sent_at))}</div>
          </div>
        </div>
      `;
    })
    .join("");

  container.scrollTop = container.scrollHeight;
}

async function markMessagesAsRead(messages, currentUserId) {
  const unreadIds = messages
    .filter(function (message) {
      return Number(message.receiver_id) === currentUserId && String(message.status) === "sent";
    })
    .map(function (message) {
      return Number(message.message_id);
    });

  if (!unreadIds.length) {
    return;
  }

  await Promise.allSettled(unreadIds.map(function (messageId) {
    return window.ApiClient.put("message", "", {
      id: messageId,
      status: "read"
    });
  }));
}

function updateContactAfterRead(state) {
  state.contacts = state.contacts.map(function (contact) {
    if (contact.id === state.selectedContactId) {
      return Object.assign({}, contact, { unreadCount: 0 });
    }
    return contact;
  });
  state.filteredContacts = state.filteredContacts.map(function (contact) {
    if (contact.id === state.selectedContactId) {
      return Object.assign({}, contact, { unreadCount: 0 });
    }
    return contact;
  });
}

async function sendCurrentMessage(state, elements) {
  if (!state.selectedContactId || !elements.messageInput) {
    return;
  }

  const text = elements.messageInput.value.trim();
  if (!text) {
    return;
  }

  elements.sendBtn.disabled = true;

  try {
    await window.ApiClient.post("message", "", {
      receiver_id: state.selectedContactId,
      message: text
    });

    elements.messageInput.value = "";
    await loadConversation(state, elements);
    await refreshContactSummaries(state, elements);
  } catch (error) {
    alert(error.message || "Failed to send message.");
  } finally {
    elements.sendBtn.disabled = false;
    elements.messageInput.focus();
  }
}

async function refreshContactSummaries(state, elements) {
  try {
    const baseContacts = await loadBaseContacts(state.user);
    const messages = await loadMessageSummaries();
    state.contacts = mergeContactsWithMessages(state.currentUserId, baseContacts, messages);

    const query = elements.searchInput ? elements.searchInput.value.trim().toLowerCase() : "";
    state.filteredContacts = state.contacts.filter(function (contact) {
      return contact.name.toLowerCase().includes(query);
    });

    renderContacts(state, elements);
  } catch (error) {
    // Leave the current UI in place if refresh fails.
  }
}

function startPolling(state, elements) {
  stopPolling(state);
  state.pollingId = window.setInterval(async function () {
    await Promise.allSettled([
      loadConversation(state, elements),
      refreshContactSummaries(state, elements)
    ]);
  }, 3000);
}

function stopPolling(state) {
  if (state.pollingId) {
    window.clearInterval(state.pollingId);
    state.pollingId = null;
  }
}

function renderEmptyConversation(elements, message) {
  if (!elements.chatBody) {
    return;
  }

  elements.chatBody.innerHTML = `<div class="text-center text-gray-500 mt-12">${escapeHtml(message)}</div>`;
}

function renderContactLoadError(elements) {
  if (!elements.contactsContainer) {
    return;
  }

  elements.contactsContainer.innerHTML = '<div class="p-4 text-sm text-gray-500">Unable to load contacts right now.</div>';
}

function showConversation(elements) {
  if (isMobile()) {
    if (elements.chatList) {
      elements.chatList.style.display = "none";
    }
    if (elements.chatContainer) {
      elements.chatContainer.style.display = "flex";
    }
    if (elements.backBtn) {
      elements.backBtn.classList.remove("hidden");
    }
  } else {
    if (elements.chatList) {
      elements.chatList.style.display = "block";
    }
    if (elements.chatContainer) {
      elements.chatContainer.style.display = "flex";
    }
    if (elements.backBtn) {
      elements.backBtn.classList.add("hidden");
    }
  }
}

function showContactList(elements) {
  if (elements.chatList) {
    elements.chatList.style.display = "block";
  }
  if (elements.chatContainer) {
    elements.chatContainer.style.display = "none";
  }
  if (elements.backBtn) {
    elements.backBtn.classList.add("hidden");
  }
}

function isMobile() {
  return window.innerWidth < 768;
}

function compareDatesDesc(left, right) {
  const leftTime = left ? new Date(left).getTime() : 0;
  const rightTime = right ? new Date(right).getTime() : 0;
  return rightTime - leftTime;
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
