document.addEventListener("DOMContentLoaded", async function () {
  const role = document.body.dataset.blogRole || "";
  if (!role) {
    return;
  }

  const user = window.Auth.requireAuth([role]);
  if (!user) {
    return;
  }
  const canManagePosts = role === "student" || role === "tutor";

  bindLogout();
  if (canManagePosts) {
    bindComposerActions();
    bindCommentActions();
    bindDeleteActions();
  }

  await Promise.allSettled([
    loadLastLogin(),
    loadComposerProfile(),
    loadBlogs()
  ]);
});

let blogState = {
  blogs: [],
  commentsByPostId: new Map(),
  currentUserId: Number((window.AuthStorage.getUser() || {}).user_id || (window.AuthStorage.getUser() || {}).id || 0),
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

function bindComposerActions() {
  const postBtn = document.getElementById("postBlogBtn");
  const resetBtn = document.getElementById("resetBlogDraftBtn");
  const textarea = document.getElementById("blogText");

  if (postBtn) {
    postBtn.addEventListener("click", createBlogPost);
  }

  if (resetBtn && textarea) {
    resetBtn.addEventListener("click", function () {
      textarea.value = "";
      setStatus("", false);
      textarea.focus();
    });
  }
}

function bindCommentActions() {
  const posts = document.getElementById("posts");
  if (!posts) {
    return;
  }

  posts.addEventListener("click", async function (event) {
    const button = event.target.closest("[data-comment-post-id]");
    if (!button) {
      return;
    }

    const postId = Number(button.dataset.commentPostId || 0);
    if (postId <= 0) {
      return;
    }

    await createComment(postId, button);
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

async function loadComposerProfile() {
  const nameTarget = document.getElementById("composerName");
  const avatarTarget = document.getElementById("composerAvatar");
  const emailTarget = document.getElementById("composerEmail");

  const defaultUser = window.AuthStorage.getUser() || {};
  if (nameTarget) {
    nameTarget.textContent = defaultUser.full_name || defaultUser.display_name || defaultUser.user_name || "Your account";
  }

  try {
    const response = await window.ApiClient.get("user", "me");
    const data = response.data || {};
    const profile = data.profile || {};

    if (nameTarget) {
      nameTarget.textContent = profile.full_name || profile.display_name || data.full_name || defaultUser.full_name || data.user_name || defaultUser.user_name || "Your account";
    }

    if (emailTarget) {
      emailTarget.textContent = data.email || "";
    }

    if (avatarTarget) {
      avatarTarget.src = profile.profile_photo
        ? resolveAssetUrl(profile.profile_photo)
        : getDefaultAvatar(nameTarget?.textContent || defaultUser.full_name || defaultUser.user_name || "User");
    }
  } catch (error) {
    if (avatarTarget) {
      avatarTarget.src = getDefaultAvatar(nameTarget?.textContent || defaultUser.full_name || defaultUser.user_name || "User");
    }
  }
}

async function loadBlogs() {
  const postsContainer = document.getElementById("posts");
  if (!postsContainer) {
    return;
  }

  postsContainer.innerHTML = '<div class="post-card"><p class="post-text">Loading blog posts...</p></div>';

  try {
    const [blogsResponse, commentsResponse] = await Promise.all([
      window.ApiClient.get("blog", "", { limit: 100, offset: 0 }),
      window.ApiClient.get("blog_comment")
    ]);

    const blogs = Array.isArray(blogsResponse.data) ? blogsResponse.data : [];
    const comments = Array.isArray(commentsResponse.data) ? commentsResponse.data : [];
    blogState.blogs = blogs;
    blogState.commentsByPostId = groupCommentsByPostId(comments);
    if (blogState.page < 1) {
      blogState.page = 1;
    }
    renderBlogs();
  } catch (error) {
    postsContainer.innerHTML = `<div class="post-card"><p class="post-text">${escapeHtml(error.message || "Unable to load blogs.")}</p></div>`;
  }
}

function renderBlogs() {
  const postsContainer = document.getElementById("posts");
  if (!postsContainer) {
    return;
  }

  const totalItems = blogState.blogs.length;
  if (!totalItems) {
    postsContainer.innerHTML = '<div class="post-card"><p class="post-text">No blog posts yet.</p></div>';
    renderBlogPagination(0);
    return;
  }

  const totalPages = Math.max(1, Math.ceil(totalItems / blogState.pageSize));
  if (blogState.page > totalPages) {
    blogState.page = totalPages;
  }
  const start = (blogState.page - 1) * blogState.pageSize;
  const pagedBlogs = blogState.blogs.slice(start, start + blogState.pageSize);

  const role = document.body.dataset.blogRole || "";
  const canManagePosts = role === "student" || role === "tutor";

  postsContainer.innerHTML = pagedBlogs.map(function (blog) {
    const comments = blogState.commentsByPostId.get(Number(blog.blog_id)) || [];
    const commentsHtml = comments.length
      ? comments.map(renderComment).join("")
      : '<div class="comment"><p>No comments yet.</p></div>';
    const canDelete = canManagePosts && Number(blog.user_id || 0) === blogState.currentUserId;

    return `
      <div class="post-card" data-blog-id="${Number(blog.blog_id)}">
        <div class="post-header">
          <img src="${getDefaultAvatar(blog.full_name || blog.display_name || blog.user_name || "User")}" alt="Author avatar">
          <div>
            <h2>${escapeHtml(blog.full_name || blog.display_name || blog.user_name || "Unknown user")}</h2>
            <p>${escapeHtml(blog.email || "")}</p>
          </div>
        </div>

        <p class="post-text">${escapeHtml(blog.content || "")}</p>
        <p class="post-time">${escapeHtml(formatDateTime(blog.created_at))}</p>

        ${canDelete ? `
        <button class="delete-btn" data-delete-id="${Number(blog.blog_id)}">
          <i class="bi bi-trash"></i> Delete
        </button>
        ` : ``}

        <div class="comment-list">
          ${commentsHtml}
        </div>

        ${canManagePosts ? `
        <div class="comment-box">
          <input id="comment-${Number(blog.blog_id)}" placeholder="Write a comment...">
          <button type="button" data-comment-post-id="${Number(blog.blog_id)}">Send</button>
        </div>
        ` : ``}
      </div>
    `;
  }).join("");

  renderBlogPagination(totalPages);
}

function renderBlogPagination(totalPages) {
  const postsContainer = document.getElementById("posts");
  if (!postsContainer) {
    return;
  }

  const hostId = "blogPagination";
  let host = document.getElementById(hostId);
  if (!host) {
    host = document.createElement("div");
    host.id = hostId;
    host.className = "flex items-center justify-end gap-3 mt-4";
    postsContainer.insertAdjacentElement("afterend", host);
  }

  if (totalPages <= 1) {
    host.innerHTML = "";
    return;
  }

  host.innerHTML = `
    <button type="button" id="blogPrevPageBtn" class="px-3 py-1 rounded border border-gray-300 bg-white text-sm">Prev</button>
    <span class="text-sm text-gray-600">Page ${blogState.page} / ${totalPages}</span>
    <button type="button" id="blogNextPageBtn" class="px-3 py-1 rounded border border-gray-300 bg-white text-sm">Next</button>
  `;

  const prev = document.getElementById("blogPrevPageBtn");
  const next = document.getElementById("blogNextPageBtn");
  if (prev) {
    prev.disabled = blogState.page <= 1;
    prev.addEventListener("click", function () {
      if (blogState.page <= 1) {
        return;
      }
      blogState.page -= 1;
      renderBlogs();
    });
  }
  if (next) {
    next.disabled = blogState.page >= totalPages;
    next.addEventListener("click", function () {
      if (blogState.page >= totalPages) {
        return;
      }
      blogState.page += 1;
      renderBlogs();
    });
  }
}

function bindDeleteActions() {
  const posts = document.getElementById("posts");
  if (!posts) return;

  posts.addEventListener("click", async function (event) {
    const btn = event.target.closest("[data-delete-id]");
    if (!btn) return;

    const postId = Number(btn.dataset.deleteId || 0);
    if (!postId) return;

    if (!confirm("Are you sure you want to delete this blog?")) return;

    btn.disabled = true;

    try {
      await window.ApiClient.delete("blog", "", { id: postId });
      setStatus("Blog deleted successfully.", false);
      await loadBlogs(); // refresh list
    } catch (error) {
      setStatus(error.message || "Unable to delete blog.", true);
    } finally {
      btn.disabled = false;
    }
  });
}

function renderComment(comment) {
  return `
    <div class="comment">
      <div class="comment-header">
        <span class="comment-user">${escapeHtml(comment.full_name || comment.display_name || comment.user_name || "Unknown user")}</span>
        <span class="comment-time">${escapeHtml(formatDateTime(comment.created_at))}</span>
      </div>
      <p>${escapeHtml(comment.comment || "")}</p>
    </div>
  `;
}

async function createBlogPost() {
  const textarea = document.getElementById("blogText");
  const postBtn = document.getElementById("postBlogBtn");
  if (!textarea) {
    return;
  }

  const content = textarea.value.trim();
  if (!content) {
    setStatus("Please write something before posting.", true);
    return;
  }

  const title = buildTitleFromContent(content);
  if (postBtn) {
    postBtn.disabled = true;
  }

  try {
    await window.ApiClient.post("blog", "", { title, content });
    textarea.value = "";
    blogState.page = 1;
    setStatus("Blog post created successfully.", false);
    await loadBlogs();
  } catch (error) {
    setStatus(error.message || "Unable to create blog post.", true);
  } finally {
    if (postBtn) {
      postBtn.disabled = false;
    }
  }
}

async function createComment(postId, button) {
  const input = document.getElementById(`comment-${postId}`);
  if (!input) {
    return;
  }

  const comment = input.value.trim();
  if (!comment) {
    setStatus("Please write a comment before sending.", true);
    return;
  }

  button.disabled = true;
  try {
    await window.ApiClient.post("blog_comment", "", { post_id: postId, comment });
    input.value = "";
    blogState.page = 1;
    setStatus("Comment added successfully.", false);
    await loadBlogs();
  } catch (error) {
    setStatus(error.message || "Unable to add comment.", true);
  } finally {
    button.disabled = false;
  }
}

function groupCommentsByPostId(comments) {
  const grouped = new Map();

  comments.forEach(function (comment) {
    const postId = Number(comment.post_id || 0);
    if (!postId) {
      return;
    }

    if (!grouped.has(postId)) {
      grouped.set(postId, []);
    }
    grouped.get(postId).push(comment);
  });

  return grouped;
}

function buildTitleFromContent(content) {
  const normalized = content.replace(/\s+/g, " ").trim();
  if (!normalized) {
    return "Untitled post";
  }

  return normalized.length > 80 ? `${normalized.slice(0, 80)}...` : normalized;
}

function setStatus(message, isError) {
  const status = document.getElementById("blogStatus");
  if (!status) {
    return;
  }

  status.textContent = message;
  status.className = `text-sm mt-3 ${isError ? "text-red-500" : "text-green-600"}`;
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
  return String(value ?? "")
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#39;");
}
