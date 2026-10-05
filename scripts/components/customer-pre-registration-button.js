const buttons = document.querySelectorAll("[data-pre-registration-button]");

function setButtonAccess(button, allowed) {
  button.setAttribute("aria-disabled", String(!allowed));
  button.classList.toggle("cursor-not-allowed", !allowed);
  button.classList.toggle("opacity-60", !allowed);

  if (allowed) {
    button.removeAttribute("tabindex");
    button.removeAttribute("title");
    return;
  }

  button.setAttribute("tabindex", "-1");
  button.setAttribute("title", "Pre-registration ongoing");
}

buttons.forEach((button) => {
  button.addEventListener("click", (event) => {
    if (button.getAttribute("aria-disabled") === "true") {
      event.preventDefault();
    }
  });
});

let accessRequest = null;

async function loadPreRegistrationAccess() {
  if (accessRequest) return accessRequest;

  accessRequest = (async () => {
    try {
      const access = await API.getPreRegistrationAccess();
      buttons.forEach((button) => setButtonAccess(button, access.allowed));
    } catch (error) {
      buttons.forEach((button) => setButtonAccess(button, false));
      console.error("Failed to check pre-registration access:", error);
    } finally {
      accessRequest = null;
    }
  })();

  return accessRequest;
}

function scheduleAccessCheck() {
  const start = () => loadPreRegistrationAccess();

  if (typeof window.requestIdleCallback === "function") {
    window.requestIdleCallback(start, { timeout: 1200 });
    return;
  }

  window.setTimeout(start, 200);
}

if (buttons.length > 0) {
  const cached = API.readCustomerCache("/pre-registration/access");
  if (cached?.fresh) buttons.forEach((button) => setButtonAccess(button, cached.data.allowed));
  window.requestAnimationFrame(scheduleAccessCheck);
  window.setInterval(() => {
    if (document.visibilityState === "visible") void loadPreRegistrationAccess();
  }, 15000);
  document.addEventListener("visibilitychange", () => {
    if (document.visibilityState === "visible") void loadPreRegistrationAccess();
  });
}
