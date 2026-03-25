document.addEventListener("DOMContentLoaded", function () {
  const user = window.Auth.requireAuth(["staff"]);
  if (!user) {
    return;
  }

  const isAdmin = Boolean(user.is_admin);
  const isAdminPage = document.body.dataset.adminPage === "1";

  if (isAdminPage && !isAdmin) {
    window.location.replace("./Staff_Dashboard.html");
    return;
  }

  document.querySelectorAll("[data-admin-only]").forEach(function (element) {
    if (!isAdmin) {
      element.remove();
    }
  });

  renderAdminReportsMenu(isAdmin);
  applySidebarActiveState();
});

function renderAdminReportsMenu(isAdmin) {
  const placeholder = document.getElementById("admin-reports-placeholder");
  if (!placeholder) {
    return;
  }

  if (!isAdmin) {
    placeholder.innerHTML = "";
    return;
  }

  const wrapper = document.createElement("div");
  wrapper.className = "dropdown";
  wrapper.innerHTML = `
    <div class="nav-item dropdown-toggle">
      <i class="bi bi-file-bar-graph"></i>
      <span class="menu-text">Reports</span>
      <i class="bi bi-chevron-down arrow"></i>
    </div>
    <div class="dropdown-menu">
      <a href="./Exception_Report.html">
        <i class="bi bi-exclamation-triangle"></i>
        <span class="menu-text">Exception Report</span>
      </a>
      <a href="./Statistical_Report.html">
        <i class="bi bi-bar-chart-line"></i>
        <span class="menu-text">Statistical Report</span>
      </a>
      <a href="./Activity_Logs.html">
        <i class="bi bi-clock-history"></i>
        <span class="menu-text">Activity Logs</span>
      </a>
    </div>
  `;

  placeholder.replaceWith(wrapper);
}

function applySidebarActiveState() {
  const currentFile = window.location.pathname.split("/").pop();
  if (!currentFile) {
    return;
  }

  const navItems = document.querySelectorAll(".sidebar .nav-item");
  navItems.forEach(function (node) {
    node.classList.remove("active");
  });

  const dropdowns = document.querySelectorAll(".sidebar .dropdown");
  dropdowns.forEach(function (dropdown) {
    dropdown.classList.remove("active");
  });

  const links = document.querySelectorAll(".sidebar a[href]");
  let activeLink = null;
  links.forEach(function (link) {
    const href = link.getAttribute("href") || "";
    const hrefFile = href.split("/").pop();
    if (hrefFile === currentFile) {
      activeLink = link;
    }
  });

  if (!activeLink) {
    return;
  }

  activeLink.classList.add("active");
  const parentDropdown = activeLink.closest(".dropdown");
  if (parentDropdown) {
    parentDropdown.classList.add("active");
    const toggle = parentDropdown.querySelector(".dropdown-toggle");
    if (toggle) {
      toggle.classList.add("active");
    }
  }
}
