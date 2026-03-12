async function loadComponent(targetId, path) {
  const target = document.getElementById(targetId);
  if (!target) return;
  const res = await fetch(path);
  if (!res.ok) return;
  target.innerHTML = await res.text();
  return true;
}

function loadSidebar(role) {
  const file = role === "staff" ? "components/sidebar-staff.html" : "components/sidebar-student.html";
  loadComponent("sidebar", file).then((loaded) => {
    if (!loaded) return;

    const current = (window.location.pathname || "").split("/").pop();
    document.querySelectorAll("#sidebar .nav-item").forEach((link) => {
      const href = link.getAttribute("href");
      if (href && href === current) {
        link.classList.add("active");
      } else {
        link.classList.remove("active");
      }
    });

    const logout = document.querySelector("#sidebar .logout");
    if (logout) {
      logout.addEventListener("click", (e) => {
        e.preventDefault();
        Storage.clearAuth();
        window.location.href = "Login.html";
      });
    }

    if (typeof loadLastLogin === "function") {
      loadLastLogin().then((res) => {
        if (!res || !res.success) return;
        const value = res.data && res.data.last_login ? res.data.last_login : null;
        const el = document.getElementById("lastLoginValue");
        if (!el) return;
        const d = value ? new Date(value) : null;
        console.log(d && !Number.isNaN(d.getSeconds()) ? d.toLocaleDateString() : (value || "--/--/-- --:--:--"));
        el.textContent = d && !Number.isNaN(d.getHours()) ? d.toLocaleDateString() : (value || "--/--/-- --:--:--");
      });
    }
  });
}

function loadNavbar() {
  loadComponent("navbar", "components/navbar.html");
}

function loadFooter() {
  loadComponent("footer", "components/footer.html");
}
