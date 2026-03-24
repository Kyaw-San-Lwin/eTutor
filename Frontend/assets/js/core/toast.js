(function () {
  const CONTAINER_ID = "etutor-toast-container";

  function ensureContainer() {
    let container = document.getElementById(CONTAINER_ID);
    if (container) {
      return container;
    }

    container = document.createElement("div");
    container.id = CONTAINER_ID;
    container.style.position = "fixed";
    container.style.top = "16px";
    container.style.right = "16px";
    container.style.zIndex = "9999";
    container.style.display = "flex";
    container.style.flexDirection = "column";
    container.style.gap = "8px";
    document.body.appendChild(container);
    return container;
  }

  function colors(type) {
    if (type === "error") {
      return { bg: "#fee2e2", border: "#fca5a5", text: "#991b1b" };
    }
    if (type === "warning") {
      return { bg: "#fef3c7", border: "#fcd34d", text: "#92400e" };
    }
    return { bg: "#dcfce7", border: "#86efac", text: "#166534" };
  }

  function show(message, type = "success", ms = 2600) {
    if (!message) {
      return;
    }

    const container = ensureContainer();
    const tone = colors(type);
    const item = document.createElement("div");
    item.textContent = String(message);
    item.style.minWidth = "240px";
    item.style.maxWidth = "420px";
    item.style.padding = "10px 14px";
    item.style.borderRadius = "10px";
    item.style.border = `1px solid ${tone.border}`;
    item.style.background = tone.bg;
    item.style.color = tone.text;
    item.style.boxShadow = "0 8px 20px rgba(0,0,0,0.08)";
    item.style.fontSize = "14px";
    item.style.transition = "opacity .2s ease";
    container.appendChild(item);

    setTimeout(function () {
      item.style.opacity = "0";
      setTimeout(function () {
        item.remove();
      }, 220);
    }, ms);
  }

  window.Toast = { show };
})();

