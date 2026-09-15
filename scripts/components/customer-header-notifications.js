// Shared client bell for Settings, Grooming History, and pet details.
// Uses the existing customer notification API and destinations.
const bell = document.getElementById("notifBellBtn");
const badge = document.getElementById("notifBadge");
const dropdown = document.getElementById("notifDropdown");
const list = document.getElementById("notifList");
const markAll = document.getElementById("notifMarkAllRead");
let notifications = [];
const currentTab = window.ClientNotificationUI?.ensureDropdownControls(dropdown, () => draw());

function closeDropdown() {
  if (!bell || !dropdown) return;
  dropdown.style.display = "none";
  bell.setAttribute("aria-expanded", "false");
}

function positionDropdown() {
  if (!bell || !dropdown) return;
  const bounds = bell.getBoundingClientRect();
  const margin = 12;
  dropdown.style.top = `${bounds.bottom + 8}px`;
  dropdown.style.right = `${Math.max(margin, window.innerWidth - bounds.right)}px`;
  dropdown.style.left = "auto";
  dropdown.style.width = `${Math.min(384, window.innerWidth - margin * 2)}px`;
}

function draw() {
  if (!list) return;
  window.ClientNotificationUI.render(list, notifications, currentTab?.() || "all");
}

async function loadNotifications() {
  if (!badge || !list) return;
  try {
    const data = await API.getCustomerNotifications();
    notifications = Array.isArray(data.notifications) ? data.notifications : [];
    const count = Number(data.unread_count || 0);
    badge.textContent = count > 9 ? "9+" : String(count);
    badge.classList.toggle("hidden", count === 0);
    badge.classList.toggle("inline-flex", count > 0);
    window.ClientNotificationUI.setUnreadCount(dropdown, count);
    markAll?.toggleAttribute("disabled", count === 0);
    draw();
  } catch {
    list.innerHTML = '<p class="px-4 py-6 text-center text-sm text-portal-muted">Notifications are unavailable.</p>';
  }
}

bell?.addEventListener("click", () => {
  if (!dropdown) return;
  if (dropdown.style.display !== "none") return closeDropdown();
  positionDropdown();
  dropdown.style.display = "flex";
  bell.setAttribute("aria-expanded", "true");
  void loadNotifications();
});

list?.addEventListener("click", async (event) => {
  const row = event.target.closest("[data-client-notification-index]");
  if (!row) return;
  const notification = notifications[Number(row.dataset.clientNotificationIndex)];
  if (!notification) return;
  try {
    await API.markCustomerNotificationRead(notification.id);
    if (notification.destination) {
      window.location.href = notification.destination;
      return;
    }
    await loadNotifications();
    document.dispatchEvent(new CustomEvent("client-notifications-changed", { detail: { source: "header" } }));
  } catch {
    // Reading notifications does not block the client page.
  }
});

markAll?.addEventListener("click", async () => {
  try {
    await API.markAllCustomerNotificationsRead();
    await loadNotifications();
    document.dispatchEvent(new CustomEvent("client-notifications-changed", { detail: { source: "header" } }));
  } catch {
    // Reading notifications does not block the client page.
  }
});

document.addEventListener("click", (event) => {
  if (dropdown?.style.display !== "none" && !dropdown.contains(event.target) && !bell?.contains(event.target)) closeDropdown();
});
document.addEventListener("keydown", (event) => { if (event.key === "Escape") closeDropdown(); });
window.addEventListener("resize", () => { if (dropdown?.style.display !== "none") positionDropdown(); });
document.addEventListener("client-notifications-changed", (event) => {
  if (event.detail?.source !== "header") void loadNotifications();
});
void loadNotifications();
