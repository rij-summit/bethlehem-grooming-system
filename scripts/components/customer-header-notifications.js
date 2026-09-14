// Shared customer header notification dropdown.
// Depends on: api.js and matching notifBell/notifDropdown markup.

const notificationBell = document.getElementById("notifBellBtn");
const notificationBadge = document.getElementById("notifBadge");
const notificationDropdown = document.getElementById("notifDropdown");
const notificationList = document.getElementById("notifList");
const markAllNotificationsRead = document.getElementById("notifMarkAllRead");

const escapeNotificationHtml = (value) => String(value ?? "")
  .replaceAll("&", "&amp;")
  .replaceAll("<", "&lt;")
  .replaceAll(">", "&gt;")
  .replaceAll('"', "&quot;")
  .replaceAll("'", "&#039;");

function closeNotificationDropdown() {
  if (!notificationBell || !notificationDropdown) return;

  notificationDropdown.style.display = "none";
  notificationBell.setAttribute("aria-expanded", "false");
}

function positionNotificationDropdown() {
  if (!notificationBell || !notificationDropdown) return;

  const bellBounds = notificationBell.getBoundingClientRect();
  const margin = 12;
  const width = Math.min(320, window.innerWidth - (margin * 2));

  notificationDropdown.style.top = `${bellBounds.bottom + 8}px`;
  notificationDropdown.style.right = `${Math.max(margin, window.innerWidth - bellBounds.right)}px`;
  notificationDropdown.style.left = "auto";
  notificationDropdown.style.width = `${width}px`;
}

async function loadHeaderNotifications() {
  if (!notificationBadge || !notificationList) return;

  try {
    const data = await API.getCustomerNotifications();
    const notifications = Array.isArray(data.notifications) ? data.notifications : [];
    const unreadCount = Number(data.unread_count || 0);

    notificationBadge.textContent = unreadCount > 9 ? "9+" : String(unreadCount);
    notificationBadge.classList.toggle("hidden", unreadCount === 0);
    notificationBadge.classList.toggle("inline-flex", unreadCount > 0);

    if (notifications.length === 0) {
      notificationList.innerHTML = '<p class="px-4 py-6 text-center text-sm text-portal-muted">No notifications yet.</p>';
      return;
    }

    notificationList.innerHTML = notifications.map((notification, index) => {
      const surfaceClass = notification.is_read ? "bg-white" : "bg-portal-active";
      const dotClass = notification.is_read ? "bg-transparent" : "bg-portal-primary";
      const message = escapeNotificationHtml(
        notification.display_message || notification.message || "Notification",
      );
      const createdAt = notification.created_at
        ? new Date(notification.created_at).toLocaleString("en-PH", {
            month: "short",
            day: "numeric",
            hour: "numeric",
            minute: "2-digit",
          })
        : "";

      return `
        <button type="button" data-header-notification-index="${index}"
          class="flex w-full items-start gap-3 px-4 py-3 text-left transition hover:bg-portal-surface-soft ${surfaceClass}">
          <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full ${dotClass}"></span>
          <span class="min-w-0 flex-1">
            <span class="block text-sm leading-snug text-portal-text">${message}</span>
            <span class="mt-1 block text-xs text-portal-muted">${escapeNotificationHtml(createdAt)}</span>
          </span>
        </button>`;
    }).join("");

    notificationList.querySelectorAll("[data-header-notification-index]").forEach((button) => {
      button.addEventListener("click", async () => {
        const notification = notifications[Number(button.dataset.headerNotificationIndex)];

        try {
          await API.markCustomerNotificationRead(notification.id);
          if (notification.destination) {
            window.location.href = notification.destination;
            return;
          }
          await loadHeaderNotifications();
        } catch {
          // Notification state does not block the current customer page.
        }
      });
    });
  } catch {
    notificationList.innerHTML = '<p class="px-4 py-6 text-center text-sm text-portal-muted">Notifications are unavailable.</p>';
  }
}

notificationBell?.addEventListener("click", () => {
  if (!notificationDropdown) return;

  if (notificationDropdown.style.display === "none") {
    positionNotificationDropdown();
    notificationDropdown.style.display = "flex";
    notificationBell.setAttribute("aria-expanded", "true");
    return;
  }

  closeNotificationDropdown();
});

markAllNotificationsRead?.addEventListener("click", async () => {
  try {
    await API.markAllCustomerNotificationsRead();
    await loadHeaderNotifications();
  } catch {
    // Notification state does not block the current customer page.
  }
});

document.addEventListener("click", (event) => {
  if (
    notificationDropdown?.style.display !== "none"
    && !notificationDropdown.contains(event.target)
    && !notificationBell?.contains(event.target)
  ) {
    closeNotificationDropdown();
  }
});

window.addEventListener("resize", () => {
  if (notificationDropdown?.style.display !== "none") {
    positionNotificationDropdown();
  }
});

loadHeaderNotifications();
