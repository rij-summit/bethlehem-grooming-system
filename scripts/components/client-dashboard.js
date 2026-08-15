// Connected to pages/client/dashboard.html
// Depends on: api.js (loaded before this script)

// Client dashboard shell: Lucide icons, mobile sidebar, profile name, and logout.
// Connected to the sidebar/profile controls in pages/client/dashboard.html.
(function () {
  function createIconsWhenReady() {
    if (window.lucide) {
      window.lucide.createIcons();
      return;
    }

    window.addEventListener("DOMContentLoaded", () => {
      window.lucide?.createIcons();
    }, { once: true });

    window.addEventListener("load", () => {
      window.lucide?.createIcons();
    }, { once: true });
  }

  createIconsWhenReady();

  const mobileSidebarQuery = window.matchMedia("(max-width: 1180px)");
  const sidebarToggle = document.getElementById("clientSidebarToggle");
  const sidebarClose = document.getElementById("clientSidebarClose");
  const sidebarBackdrop = document.getElementById("clientSidebarBackdrop");
  const sidebar = document.getElementById("clientSidebar");
  const profileName = document.getElementById("clientProfileName");
  const profileInitials = document.getElementById("clientProfileInitials");
  const logoutBtn = document.getElementById("clientLogoutBtn");

  if (!sidebarToggle || !sidebarClose || !sidebarBackdrop || !sidebar) return;

  const sidebarLinks = sidebar.querySelectorAll("a");

  // Sidebar navigation section: open/close behavior for tablet and mobile.
  function setSidebarState(isOpen) {
    document.body.classList.toggle("client-sidebar-open", isOpen);
    sidebarToggle.setAttribute("aria-expanded", String(isOpen));
    sidebarToggle.setAttribute(
      "aria-label",
      isOpen ? "Close navigation menu" : "Open navigation menu",
    );
  }

  function closeSidebar() {
    setSidebarState(false);
  }

  function toggleSidebar() {
    if (!mobileSidebarQuery.matches) return;
    const isOpen = document.body.classList.contains("client-sidebar-open");
    setSidebarState(!isOpen);
  }

  setSidebarState(false);

  sidebarToggle.addEventListener("click", toggleSidebar);
  sidebarClose.addEventListener("click", closeSidebar);
  sidebarBackdrop.addEventListener("click", closeSidebar);

  sidebarLinks.forEach((link) => {
    link.addEventListener("click", closeSidebar);
  });

  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape") {
      closeSidebar();
    }
  });

  mobileSidebarQuery.addEventListener("change", (event) => {
    if (!event.matches) {
      closeSidebar();
    }
  });

  // Client profile section: load the logged-in customer's name and initials.
  (async () => {
    try {
      const { user } = await API.getMe("customer");
      const firstName = user.first_name || "";
      const lastName = user.last_name || "";

      if (profileName) {
        profileName.textContent = `${firstName} ${lastName}`.trim() || "Customer";
      }

      if (profileInitials) {
        profileInitials.textContent =
          ((firstName[0] || "") + (lastName[0] || "")).toUpperCase() || "--";
      }
    } catch {
      // Authentication section: token is missing or expired, so return to login.
      window.location.href = "./sign-in.html";
    }
  })();

  // Logout section: end the customer session and return to sign in.
  if (logoutBtn) {
    logoutBtn.addEventListener("click", async () => {
      try {
        await API.logout("customer");
      } finally {
        window.location.href = "./sign-in.html?logout=1";
      }
    });
  }
})();

// ── Customer Notification Bell ────────────────────────────────────────────────
(function () {
  const bellBtn        = document.getElementById("notifBellBtn");
  const badge          = document.getElementById("notifBadge");
  const dropdown       = document.getElementById("notifDropdown");
  const list           = document.getElementById("notifList");
  const markAllBtn     = document.getElementById("notifMarkAllRead");
  const pickupPopup    = document.getElementById("pickupPopup");
  const pickupMessage  = document.getElementById("pickupMessage");
  const pickupDismiss  = document.getElementById("pickupDismissBtn");

  const SHOWN_PICKUPS_KEY = "shownPickupNotifs";
  const PICKUP_READY_STATUSES = new Set([
    "for_payment",
    "for_pickup",
    "for-pickup",
    "ready_for_pickup",
  ]);

  function getShownPickups() {
    try { return JSON.parse(localStorage.getItem(SHOWN_PICKUPS_KEY) || "[]"); }
    catch { return []; }
  }

  function markPickupShown(id) {
    const shown = getShownPickups();
    if (!shown.includes(id)) {
      shown.push(id);
      localStorage.setItem(SHOWN_PICKUPS_KEY, JSON.stringify(shown));
    }
  }

  // ── Toggle dropdown ────────────────────────────────────────────────────────
  bellBtn.addEventListener("click", () => {
    dropdown.style.display === "none" ? openDropdown() : closeDropdown();
  });

  document.addEventListener("click", (e) => {
    if (dropdown.style.display === "none") return;
    const path = e.composedPath();
    if (!path.includes(dropdown) && !path.includes(bellBtn)) {
      closeDropdown();
    }
  });

  window.addEventListener("resize", () => {
    if (dropdown.style.display !== "none") positionDropdown();
  });

  function openDropdown() {
    positionDropdown();
    dropdown.style.display = "flex";
  }

  function closeDropdown() {
    dropdown.style.display = "none";
  }

  function positionDropdown() {
    const rect      = bellBtn.getBoundingClientRect();
    const gap       = 8;
    const margin    = 12;
    const dropWidth = Math.min(320, window.innerWidth - margin * 2);

    // Right-align to bell, clamped so it never clips the left edge
    let right = window.innerWidth - rect.right;
    right = Math.max(margin, right);

    dropdown.style.top   = (rect.bottom + gap) + "px";
    dropdown.style.right = right + "px";
    dropdown.style.left  = "auto";
    dropdown.style.width = dropWidth + "px";
  }

  // ── Mark all read ──────────────────────────────────────────────────────────
  markAllBtn.addEventListener("click", async () => {
    try {
      await API.markAllCustomerNotificationsRead();
      await loadNotifications();
    } catch { /* silent */ }
  });

  // ── Pickup popup dismiss ───────────────────────────────────────────────────
  pickupDismiss.addEventListener("click", async () => {
    const notifId = pickupPopup.dataset.notifId;
    pickupPopup.classList.add("hidden");
    if (notifId) {
      markPickupShown(notifId);
      await loadNotifications();
    }
  });

  // ── Load notifications ─────────────────────────────────────────────────────
  async function loadNotifications() {
    try {
      const data = await API.getCustomerNotifications();

      // Badge
      const count = data.unread_count || 0;
      if (count > 0) {
        badge.textContent = count > 9 ? "9+" : count;
        badge.classList.remove("hidden");
        badge.classList.add("inline-flex");
      } else {
        badge.classList.add("hidden");
        badge.classList.remove("inline-flex");
      }

      // List
      renderNotifList(data.notifications || []);

      // Pickup alert popup — refresh the tracker first so the booking shows
      // its updated status before the popup fires.
      const pickup = data.pickup_alert;
      if (pickup && !getShownPickups().map(String).includes(String(pickup.id))) {
        await window._refreshAppointments?.();
        pickupMessage.innerHTML = await buildPickupMessage(pickup);
        pickupPopup.dataset.notifId = pickup.id;
        pickupPopup.classList.remove("hidden");
        if (window.lucide) lucide.createIcons();
      }
    } catch { /* silent — non-critical */ }
  }

  function renderNotifList(notifications) {
    if (!notifications.length) {
      list.innerHTML = '<p class="px-4 py-6 text-center text-sm text-slate-400">No notifications yet.</p>';
      return;
    }

    list.innerHTML = notifications.map((n) => {
      const icon = notifIcon(n);
      const message = formatNotificationMessage(n);
      const bg   = n.is_read ? "bg-white" : "bg-[#eaf4fb]";
      const dot  = n.is_read ? "bg-transparent" : "bg-[#355c84]";
      const time = formatNotifTime(n.created_at);
      return `
        <div class="flex cursor-pointer items-start gap-3 px-4 py-3 transition hover:bg-slate-50 ${bg}"
             data-notif-id="${n.id}">
          <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full ${dot}"></span>
          <div class="min-w-0 flex-1">
            <div class="flex items-start gap-2">
              <span class="text-base">${icon}</span>
              <p class="text-sm text-slate-700 leading-snug">${message}</p>
            </div>
            <p class="mt-1 text-xs text-slate-400">${time}</p>
          </div>
        </div>`;
    }).join("");

    // Mark single notification as read on click
    list.querySelectorAll("[data-notif-id]").forEach((el) => {
      el.addEventListener("click", async () => {
        const id = el.dataset.notifId;
        try {
          await API.markCustomerNotificationRead(id);
          await loadNotifications();
        } catch { /* silent */ }
      });
    });

    if (window.lucide) lucide.createIcons();
  }

  function notifIcon(notification) {
    const type = typeof notification === "object" && notification
      ? notification.type
      : notification;

    if (type === "grooming_finished") {
      return groomingFinishedIcon(notification);
    }

    const icons = {
      reminder_24h:    "📅",
      reminder_3h:     "⏰",
      grooming_started:"✂️",
      ready_for_pickup:"🐾",
      pickup_reminder: "⏳",
      picked_up:       "🏠",
    };
    return icons[type] || "🔔";
  }

  function groomingFinishedIcon(notification) {
    const petTypes = notificationPetTypes(notification);

    if (petTypes.includes("cat")) {
      return "🐱";
    }

    if (petTypes.includes("dog")) {
      return "🐶";
    }

    return "🐾";
  }

  function formatNotificationMessage(notification) {
    if (!notification || typeof notification !== "object") {
      return boldImportantTerms(escapeHtml(stripDecorativePaws(notification)));
    }

    if (notification.type === "grooming_started") {
      return formatPetStatusMessage(notification, "Started Grooming");
    }

    if (notification.type === "grooming_finished") {
      return formatPetStatusMessage(notification, "Finished");
    }

    if (notification.type === "ready_for_pickup") {
      return formatReadyForPickupMessage(notification);
    }

    return boldImportantTerms(
      escapeHtml(stripDecorativePaws(notification.display_message || notification.message))
    );
  }

  function formatPetStatusMessage(notification, statusLabel) {
    const petNames = notificationPetNames(notification);
    const message = escapeHtml(stripDecorativePaws(notification.display_message || notification.message));

    return boldImportantTerms(boldPetNames(message, petNames));
  }

  function formatReadyForPickupMessage(notification) {
    const petCount = notificationPetNames(notification).length;
    const subject = petCount > 1 ? "pets are" : "pet is";

    return `Your ${subject} now <strong class="client-notification-emphasis">Ready for Pickup</strong> and looking fabulous! Please come to the clinic to pick them up.`;
  }

  function notificationPetNames(notification) {
    return Array.isArray(notification?.pet_names)
      ? notification.pet_names.map((name) => String(name).trim()).filter(Boolean)
      : [];
  }

  function notificationPetTypes(notification) {
    return Array.isArray(notification?.pet_types)
      ? notification.pet_types.map((type) => String(type).trim().toLowerCase()).filter(Boolean)
      : [];
  }

  function boldPetNames(escapedMessage, petNames) {
    return petNames.reduce((message, petName) => {
      const escapedName = escapeHtml(String(petName).trim());

      if (!escapedName) return message;

      const pattern = new RegExp(
        `(^|[^\\p{L}\\p{N}])(${escapeRegExp(escapedName)})(?=$|[^\\p{L}\\p{N}])`,
        "gu"
      );

      return message.replace(pattern, '$1<strong class="client-notification-emphasis">$2</strong>');
    }, String(escapedMessage || ""));
  }

  function escapeRegExp(value) {
    return String(value).replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
  }

  function boldImportantTerms(escapedMessage) {
    return String(escapedMessage || "")
      .replace(/\bStarted Grooming\b/g, '<strong class="client-notification-emphasis">Started Grooming</strong>')
      .replace(/\bReady for Pickup\b/g, '<strong class="client-notification-emphasis">Ready for Pickup</strong>')
      .replace(/\bFinished\b/g, '<strong class="client-notification-emphasis">Finished</strong>');
  }

  function stripDecorativePaws(message) {
    return String(message || "")
      .replace(/\s*🐾\s*/g, " ")
      .replace(/\s{2,}/g, " ")
      .trim();
  }

  function escapeHtml(value) {
    return String(value || "").replace(/[&<>"']/g, (char) => ({
      "&": "&amp;",
      "<": "&lt;",
      ">": "&gt;",
      '"': "&quot;",
      "'": "&#039;",
    })[char]);
  }

  function formatNotifTime(dateStr) {
    if (!dateStr) return "";
    return new Date(dateStr).toLocaleString("en-PH", {
      month: "short", day: "numeric",
      hour: "numeric", minute: "2-digit",
    });
  }

  async function buildPickupMessage(pickup) {
    const providedPetNames = Array.isArray(pickup?.pet_names)
      ? pickup.pet_names.map((name) => String(name).trim()).filter(Boolean)
      : [];

    if (providedPetNames.length > 0) {
      return formatReadyForPickupMessage({ pet_names: providedPetNames });
    }

    try {
      const booking = await findPickupBooking(pickup);
      const petNames = getBookingPetNames(booking);

      if (petNames.length > 0) {
        return formatReadyForPickupMessage({ pet_names: petNames });
      }
    } catch { /* use API-provided message below */ }

    return formatReadyForPickupMessage({ pet_names: [] });
  }

  async function findPickupBooking(pickup) {
    const data = await API.getBookingHistory({ historyLimit: 0 });
    const activeBookings = Array.isArray(data?.bookings) ? data.bookings : [];
    const pickupBookingId = getPickupBookingId(pickup);

    if (pickupBookingId) {
      const matchedBooking = activeBookings.find((booking) =>
        String(getBookingId(booking)) === String(pickupBookingId)
      );
      if (matchedBooking) return matchedBooking;
    }

    const readyBookings = activeBookings.filter((booking) =>
      PICKUP_READY_STATUSES.has(String(booking?.status || "").toLowerCase())
    );

    return readyBookings.length === 1 ? readyBookings[0] : null;
  }

  function getPickupBookingId(pickup) {
    return (
      pickup?.booking_id ??
      pickup?.bookingId ??
      pickup?.booking?.booking_id ??
      pickup?.booking?.id ??
      null
    );
  }

  function getBookingId(booking) {
    return booking?.booking_id ?? booking?.bookingId ?? booking?.id ?? null;
  }

  function getBookingPetNames(booking) {
    if (!booking) return [];

    const names = [];
    if (Array.isArray(booking.pet_names)) names.push(...booking.pet_names);
    if (Array.isArray(booking.petNames)) names.push(...booking.petNames);
    if (Array.isArray(booking.pets)) {
      booking.pets.forEach((pet) => {
        names.push(pet?.pet_name ?? pet?.petName ?? pet?.name ?? "");
      });
    }

    names.push(booking.pet_name ?? booking.petName ?? "");

    return [...new Set(names.map((name) => String(name).trim()).filter(Boolean))];
  }

  // Initial load + poll every 30 seconds
  loadNotifications();
  setInterval(loadNotifications, 30000);
})();

// ── Appointments, Grooming Tracker & History ─────────────────────────────────
(function () {
  const appointmentsList         = document.getElementById("appointmentsList");
  const groomingTrackerEl        = document.getElementById("groomingTracker");
  const groomingHistoryEl        = document.getElementById("groomingHistory");
  const myPetsCountEl            = document.getElementById("myPetsCount");
  const myPetsSummaryEl          = document.getElementById("myPetsSummary");
  const upcomingAppointmentsCountEl   = document.getElementById("upcomingAppointmentsCount");
  const upcomingAppointmentsSummaryEl = document.getElementById("upcomingAppointmentsSummary");
  const groomingQueueCountEl          = document.getElementById("groomingQueueCount");
  const groomingQueueSummaryEl        = document.getElementById("groomingQueueSummary");
  const groomingCapacityBadgeEl       = document.getElementById("groomingCapacityBadge");
  const groomingCapacityBarEl         = document.getElementById("groomingCapacityBar");
  const groomingCapacityTextEl        = document.getElementById("groomingCapacityText");
  const upcomingReminderKickerEl      = document.getElementById("upcomingReminderKicker");
  const upcomingReminderTitleEl       = document.getElementById("upcomingReminderTitle");
  const upcomingReminderTextEl        = document.getElementById("upcomingReminderText");
  const pastGroomingCountEl      = document.getElementById("pastGroomingCount");
  const pastGroomingSummaryEl    = document.getElementById("pastGroomingSummary");
  const rescheduleModal          = document.getElementById("rescheduleModal");
  const closeRescheduleModal     = document.getElementById("closeRescheduleModal");
  const rescheduleBookingRef     = document.getElementById("rescheduleBookingRef");
  const rescheduleDate           = document.getElementById("rescheduleDate");
  const rescheduleSlotsContainer = document.getElementById("rescheduleSlotsContainer");
  const rescheduleMessage        = document.getElementById("rescheduleMessage");
  const submitRescheduleBtn      = document.getElementById("submitRescheduleBtn");
  const cancelModal              = document.getElementById("cancelModal");
  const closeCancelModalBtn      = document.getElementById("closeCancelModal");
  const cancelBookingRefEl       = document.getElementById("cancelBookingRef");
  const confirmCancelBtn         = document.getElementById("confirmCancelBtn");
  const keepBookingBtn           = document.getElementById("keepBookingBtn");
  const cancelMessageEl          = document.getElementById("cancelMessage");

  let activeBookingId     = null;
  let selectedWindowId    = null;
  let cancelTargetBooking = null;

  const UPCOMING_APPOINTMENT_STATUSES = new Set(["waiting_to_arrive", "waiting"]);

  document.addEventListener("DOMContentLoaded", () => {
    setRescheduleMinDate(new Date().toISOString().split("T")[0]);
    loadDashboardPets();
    loadAppointments();
    loadGroomingCapacity();

    Promise.resolve(window.AppClock?.load?.())
      .then(() => {
        setRescheduleMinDate(window.AppClock?.todayKey?.());
      })
      .catch(() => {
        setRescheduleMinDate(new Date().toISOString().split("T")[0]);
      });
  });

  // ── Load & route data ──────────────────────────────────────────────────────

  async function loadDashboardPets() {
    try {
      const data = await API.getUserPets({ archived: 0 });
      renderMyPetsSummary(data.pets || []);
    } catch {
      renderMyPetsSummary([]);
    }
  }

  async function loadAppointments() {
    try {
      const data    = await API.getBookingHistory({ historyLimit: 6 });
      const active  = data.bookings || [];
      const history = data.history  || [];
      const historyTotal = Number.isFinite(Number(data.history_total))
        ? Number(data.history_total)
        : history.length;

      const scheduled = active.filter(isUpcomingAppointment);
      const atClinic  = active.filter(b =>
        ["checked_in", "in_progress", "for_payment", "released"].includes(b.status)
      );

      renderAppointments(scheduled);
      renderUpcomingAppointmentsSummary(scheduled);
      renderGroomingTracker(atClinic);
      renderGroomingHistory(history);
      renderPastGroomingSummary(history, historyTotal);
    } catch {
      appointmentsList.innerHTML =
        '<div class="text-center py-10"><p class="text-sm text-red-500">Failed to load schedule.</p></div>';
    }
  }

  // ── Appointments section ───────────────────────────────────────────────────

  async function loadGroomingCapacity() {
    try {
      const data = await API.getGroomingCapacity();
      renderGroomingCapacity(data);
    } catch {
      renderGroomingCapacity(null);
    }
  }

  function setRescheduleMinDate(dateKey) {
    if (rescheduleDate && dateKey) {
      rescheduleDate.min = dateKey;
    }
  }

  function renderMyPetsSummary(pets) {
    const count = Array.isArray(pets) ? pets.length : 0;

    if (myPetsCountEl) {
      myPetsCountEl.textContent = String(count);
    }

    if (myPetsSummaryEl) {
      myPetsSummaryEl.textContent = count
        ? `${count} active pet${count === 1 ? "" : "s"}`
        : "No pets yet";
    }
  }

  function renderAppointments(bookings) {
    if (!bookings.length) {
      appointmentsList.innerHTML = `
        <div class="text-center py-10">
          <i data-lucide="calendar-x" class="w-10 h-10 mx-auto text-slate-300"></i>
          <p class="mt-3 text-slate-400 text-sm">No upcoming schedule.</p>
        </div>`;
      if (window.lucide) lucide.createIcons();
      return;
    }

    appointmentsList.innerHTML = bookings.map(b => buildBookingCard(b)).join("");

    bookings.forEach(b => {
      const rescheduleBtn = document.getElementById(`reschedule-${b.booking_id}`);
      const cancelBtn     = document.getElementById(`cancel-${b.booking_id}`);
      if (rescheduleBtn) rescheduleBtn.addEventListener("click", () => openRescheduleModal(b));
      if (cancelBtn)     cancelBtn.addEventListener("click",     () => handleCancel(b));
    });

    if (window.lucide) lucide.createIcons();
  }

  function buildBookingCard(b) {
    const statusConfig  = getStatusConfig(b.status);
    const timeLabel     = b.time_window?.window_label ?? "—";
    const isActionable  = b.status === "waiting_to_arrive";
    const rescheduleAttrs = `id="reschedule-${b.booking_id}" class="flex-1 rounded-xl border border-[#315b7e] px-3 py-2 text-xs font-semibold text-[#315b7e] hover:bg-[#315b7e] hover:text-white transition"`;
    const cancelAttrs     = `id="cancel-${b.booking_id}" class="flex-1 rounded-xl border border-red-300 px-3 py-2 text-xs font-semibold text-red-500 hover:bg-red-50 transition"`;
    const rescheduleLabel = "Reschedule";
    const cancelLabel     = "Cancel";

    const buttons = isActionable
      ? `<div class="flex gap-2 mt-3">
           <button type="button" ${rescheduleAttrs}>${rescheduleLabel}</button>
           <button type="button" ${cancelAttrs}>${cancelLabel}</button>
         </div>`
      : "";

    return `
      <div class="mb-4 rounded-2xl border border-slate-100 bg-slate-50 p-4">
        <div class="flex items-start justify-between gap-2 mb-1">
          <div>
            <p class="text-sm font-semibold text-slate-700">${b.booking_reference}</p>
            <p class="text-xs text-slate-400 mt-0.5">${formatDate(b.booking_date)} &middot; ${timeLabel}</p>
          </div>
          <span class="rounded-full px-2.5 py-1 text-[11px] font-semibold whitespace-nowrap ${statusConfig.classes}">
            ${statusConfig.label}
          </span>
        </div>
        <p class="text-xs text-slate-500">${b.number_of_pets} pet${b.number_of_pets > 1 ? "s" : ""}</p>
        ${buttons}
      </div>`;
  }

  // ── Grooming Tracker section ───────────────────────────────────────────────

  function renderGroomingCapacity(data) {
    const capacity = data?.capacity || {};
    const queue = data?.queue || {};
    const max = toNumber(capacity.max) || 20;
    const used = toNumber(capacity.used);
    const remaining = Math.max(0, toNumber(capacity.remaining ?? (max - used)));
    const percent = Math.max(0, Math.min(100, toNumber(capacity.percent ?? ((used / max) * 100))));
    const queued = toNumber(queue.queued);
    const inProgress = toNumber(queue.in_progress);
    const isFull = Boolean(capacity.is_full) || used >= max;
    const isBusy = !isFull && percent >= 80;

    if (!data) {
      if (groomingQueueCountEl) groomingQueueCountEl.textContent = "--";
      if (groomingQueueSummaryEl) groomingQueueSummaryEl.textContent = "Unable to load live queue";
      if (groomingCapacityTextEl) groomingCapacityTextEl.textContent = "Capacity unavailable";
      if (groomingCapacityBarEl) {
        groomingCapacityBarEl.style.width = "0%";
        groomingCapacityBarEl.className = "h-full rounded-full bg-slate-300 transition-all duration-300";
      }
      if (groomingCapacityBadgeEl) {
        groomingCapacityBadgeEl.textContent = "Offline";
        groomingCapacityBadgeEl.className = "rounded-full bg-slate-100 px-2.5 py-1 text-[11px] font-semibold text-slate-600";
      }
      return;
    }

    if (groomingQueueCountEl) groomingQueueCountEl.textContent = String(queued);
    if (groomingQueueSummaryEl) {
      groomingQueueSummaryEl.textContent = `${inProgress} in progress`;
      groomingQueueSummaryEl.className = "text-sm text-[#315b7e] mt-1";
    }
    if (groomingCapacityTextEl) {
      groomingCapacityTextEl.textContent = isFull
        ? `${used} / ${max} daily capacity - max reached`
        : `${used} / ${max} daily capacity - ${remaining} left`;
    }
    if (groomingCapacityBarEl) {
      groomingCapacityBarEl.style.width = `${percent}%`;
      groomingCapacityBarEl.className = "h-full rounded-full bg-[#315b7e] transition-all duration-300";
    }
    if (groomingCapacityBadgeEl) {
      groomingCapacityBadgeEl.textContent = isFull ? "Full" : isBusy ? "Busy" : "Open";
      groomingCapacityBadgeEl.className = isFull
        ? "rounded-full bg-red-100 px-2.5 py-1 text-[11px] font-semibold text-red-700"
        : isBusy
          ? "rounded-full bg-amber-100 px-2.5 py-1 text-[11px] font-semibold text-amber-700"
          : "rounded-full bg-emerald-100 px-2.5 py-1 text-[11px] font-semibold text-emerald-700";
    }
  }

  function toNumber(value) {
    const number = Number(value);
    return Number.isFinite(number) ? number : 0;
  }

  function renderUpcomingAppointmentsSummary(bookings) {
    const records = Array.isArray(bookings) ? bookings : [];
    const count = records.length;

    if (upcomingAppointmentsCountEl) {
      upcomingAppointmentsCountEl.textContent = String(count);
    }

    if (upcomingAppointmentsSummaryEl) {
      upcomingAppointmentsSummaryEl.textContent = count
        ? `${count} active schedule`
        : "No upcoming schedule";
      upcomingAppointmentsSummaryEl.className = count
        ? "text-sm text-emerald-600 mt-1"
        : "text-sm text-slate-500 mt-1";
    }

    renderUpcomingReminder(records);
  }

  function renderUpcomingReminder(bookings) {
    const nextBooking = getNextUpcomingBooking(bookings);

    if (!nextBooking) {
      if (upcomingReminderKickerEl) upcomingReminderKickerEl.textContent = "UPCOMING SCHEDULE";
      if (upcomingReminderTitleEl) upcomingReminderTitleEl.textContent = "You don't have any scheduled grooming yet";
      if (upcomingReminderTextEl) upcomingReminderTextEl.textContent = "Start by pre-registering your first grooming session for your pet.";
      return;
    }

    const timeLabel = nextBooking.time_window?.window_label ?? "Time to be confirmed";
    const petNames = getPetNamesLabel(nextBooking);

    if (upcomingReminderKickerEl) upcomingReminderKickerEl.textContent = "UPCOMING SCHEDULE";
    if (upcomingReminderTitleEl) {
      upcomingReminderTitleEl.textContent =
        `${nextBooking.booking_reference} on ${formatDate(nextBooking.booking_date)}`;
    }
    if (upcomingReminderTextEl) {
      upcomingReminderTextEl.textContent = `${petNames} - ${timeLabel}`;
    }
  }

  function getNextUpcomingBooking(bookings) {
    return [...bookings]
      .filter(isUpcomingAppointment)
      .sort((a, b) => String(a.booking_date || "").localeCompare(String(b.booking_date || "")))[0] || null;
  }

  function isUpcomingAppointment(booking) {
    return UPCOMING_APPOINTMENT_STATUSES.has(String(booking?.status || "").toLowerCase());
  }

  function getPetNamesLabel(booking) {
    const petNames = (booking.pets || [])
      .map((pet) => pet.pet_name)
      .filter(Boolean)
      .join(", ");

    if (petNames) return petNames;

    const count = booking.number_of_pets || 0;
    return count ? `${count} pet${count === 1 ? "" : "s"}` : "Your pet";
  }

  function renderGroomingTracker(bookings) {
    if (!bookings.length) {
      groomingTrackerEl.innerHTML = `
        <div class="text-center py-10">
          <i data-lucide="scissors" class="w-10 h-10 mx-auto text-slate-300"></i>
          <p class="mt-3 text-slate-400 text-sm">No pets at the clinic right now.</p>
        </div>`;
      if (window.lucide) lucide.createIcons();
      return;
    }

    groomingTrackerEl.innerHTML = bookings.map(b => buildTrackerCard(b)).join("");
    if (window.lucide) lucide.createIcons();
  }

  function buildTrackerCard(b) {
    const timeLabel  = b.time_window?.window_label ?? "—";
    const petNames   = (b.pets || []).map(p => p.pet_name).filter(Boolean).join(", ") || "—";
    const prePaid    = b.paid
      ? `<span class="ml-2 rounded-full bg-green-100 px-2 py-0.5 text-[11px] font-semibold text-green-700">Pre-Paid ✓</span>`
      : "";

    const steps = [
      { key: "checked_in",  label: "Checked In"       },
      { key: "in_progress", label: "Being Groomed"     },
      { key: "for_payment", label: "Ready for Pickup"  },
    ];
    const stepOrder  = { checked_in: 0, in_progress: 1, for_payment: 2, released: 2 };
    const current    = stepOrder[b.status] ?? 0;
    const stepThemes = {
      checked_in:  { color: "#e5a800" },
      in_progress: { color: "#1d4ed8" },
      for_payment: { color: "#16a34a" },
      released:    { color: "#16a34a" },
    };

    const stepCircles = steps.map((step, i) => {
      const active = i === current;
      const stepTheme = stepThemes[step.key];
      const circleClass = active
        ? "text-white"
        : "bg-white text-slate-400 border-slate-300";
      const circleStyle = active
        ? `style="background-color: ${stepTheme.color}; border-color: ${stepTheme.color};"`
        : "";
      const labelClass  = active
        ? "font-semibold"
        : "text-slate-400";
      const labelStyle = active
        ? `style="color: ${stepTheme.color};"`
        : "";
      return `
        <div class="flex flex-col items-center z-10">
          <div class="w-9 h-9 rounded-full border-2 flex items-center justify-center text-sm font-bold ${circleClass}" ${circleStyle}>
            ${i + 1}
          </div>
          <p class="mt-2 text-[11px] text-center ${labelClass} leading-tight max-w-[5rem]" ${labelStyle}>${step.label}</p>
        </div>`;
    }).join("");

    return `
      <div class="mb-4 rounded-2xl border border-slate-100 bg-slate-50 p-4">
        <div class="flex items-start justify-between gap-2 mb-1">
          <div>
            <p class="text-sm font-semibold text-slate-700">${b.booking_reference}${prePaid}</p>
            <p class="text-xs text-slate-400 mt-0.5">${formatDate(b.booking_date)} &middot; ${timeLabel}</p>
          </div>
        </div>
        <p class="text-xs text-slate-500 mb-5">${petNames} &middot; ${b.number_of_pets} pet${b.number_of_pets > 1 ? "s" : ""}</p>
        <div class="relative flex justify-between items-start px-4">
          <!-- background track -->
          <div class="absolute top-[1.0625rem] left-4 right-4 h-1 bg-slate-200 rounded-full"></div>
          ${stepCircles}
        </div>
      </div>`;
  }

  // ── Grooming History section ───────────────────────────────────────────────

  function renderGroomingHistory(history) {
    if (!history.length) {
      groomingHistoryEl.innerHTML = `
        <div class="text-center py-10">
          <i data-lucide="history" class="w-10 h-10 mx-auto text-slate-300"></i>
          <p class="mt-3 text-slate-400 text-sm">No grooming history yet.</p>
        </div>`;
      if (window.lucide) lucide.createIcons();
      return;
    }

    groomingHistoryEl.innerHTML = `
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
        ${history.map(b => buildHistoryCard(b)).join("")}
      </div>`;
    if (window.lucide) lucide.createIcons();
  }

  function renderPastGroomingSummary(history, totalCount = null) {
    const count = Number.isFinite(Number(totalCount))
      ? Number(totalCount)
      : (Array.isArray(history) ? history.length : 0);

    if (pastGroomingCountEl) {
      pastGroomingCountEl.textContent = String(count);
    }

    if (pastGroomingSummaryEl) {
      pastGroomingSummaryEl.textContent = count
        ? `${count} completed session${count === 1 ? "" : "s"}`
        : "No grooming history yet";
    }
  }

  function buildHistoryCard(b) {
    const timeLabel  = b.time_window?.window_label ?? "—";
    const petNames   = (b.pets || []).map(p => p.pet_name).filter(Boolean).join(", ") || "—";
    const paidBadge  = b.paid
      ? `<span class="rounded-full bg-green-100 px-2 py-0.5 text-[11px] font-semibold text-green-700">Paid ✓</span>`
      : "";

    return `
      <div class="rounded-2xl border border-slate-100 bg-slate-50 p-4">
        <div class="flex items-start justify-between gap-2 mb-1">
          <p class="text-sm font-semibold text-slate-700">${b.booking_reference}</p>
          ${paidBadge}
        </div>
        <p class="text-xs text-slate-400 mb-1">${formatDate(b.booking_date)} &middot; ${timeLabel}</p>
        <p class="text-xs text-slate-500">${petNames}</p>
      </div>`;
  }

  // ── Status config ──────────────────────────────────────────────────────────

  function getStatusConfig(status) {
    const map = {
      waiting_to_arrive: { label: "Waiting",           classes: "bg-sky-100 text-sky-700" },
      checked_in:        { label: "Checked In",         classes: "bg-amber-100 text-amber-700" },
      in_progress:       { label: "In Progress",        classes: "bg-violet-100 text-violet-700" },
      for_payment:       { label: "Ready for Pickup",   classes: "bg-emerald-100 text-emerald-700" },
      released:          { label: "Ready for Pickup",   classes: "bg-emerald-100 text-emerald-700" },
      cancelled:         { label: "Cancelled",          classes: "bg-red-100 text-red-600" },
      no_show:           { label: "No Show",            classes: "bg-orange-100 text-orange-600" },
      archived:          { label: "Completed",          classes: "bg-green-100 text-green-700" },
    };
    return map[status] || { label: status, classes: "bg-slate-100 text-slate-600" };
  }

  // ── Reschedule modal ───────────────────────────────────────────────────────

  rescheduleDate.addEventListener("change", async function () {
    selectedWindowId = null;
    enableSubmitIfReady();
    const date = this.value;
    if (!date) return;
    rescheduleSlotsContainer.innerHTML = '<p class="text-sm text-slate-400">Loading slots...</p>';
    try {
      const data = await API.getTimeslots(date);
      renderSlots(data.windows || []);
    } catch {
      rescheduleSlotsContainer.innerHTML = '<p class="text-sm text-red-500">Failed to load slots. Try again.</p>';
    }
  });

  submitRescheduleBtn.addEventListener("click", async () => {
    if (!activeBookingId || !selectedWindowId || !rescheduleDate.value) return;
    submitRescheduleBtn.disabled = true;
    submitRescheduleBtn.textContent = "Rescheduling...";
    try {
      const data = await API.rescheduleBooking(activeBookingId, rescheduleDate.value, selectedWindowId);
      showRescheduleMessage(
        "success",
        `Booking rescheduled to ${formatDate(data.booking.booking_date)} at ${data.booking.window}.`,
      );
      submitRescheduleBtn.textContent = "Confirm Reschedule";
      submitRescheduleBtn.disabled = true;
      setTimeout(async () => { closeModal(); await loadAppointments(); }, 1800);
    } catch (error) {
      showRescheduleMessage("error", error.message || "Reschedule failed. Please try again.");
      submitRescheduleBtn.disabled = false;
      submitRescheduleBtn.textContent = "Confirm Reschedule";
    }
  });

  closeRescheduleModal.addEventListener("click", closeModal);
  rescheduleModal.addEventListener("click", (e) => { if (e.target === rescheduleModal) closeModal(); });

  function openRescheduleModal(booking) {
    activeBookingId  = booking.booking_id;
    selectedWindowId = null;
    rescheduleBookingRef.textContent =
      `Rescheduling: ${booking.booking_reference} (${booking.reschedule_count ?? 0} of 2 uses)`;
    rescheduleDate.value = "";
    rescheduleSlotsContainer.innerHTML = '<p class="text-sm text-slate-400">Select a date to see available slots.</p>';
    hideRescheduleMessage();
    enableSubmitIfReady();
    rescheduleModal.classList.remove("hidden");
    rescheduleModal.classList.add("flex");
  }

  function closeModal() {
    rescheduleModal.classList.add("hidden");
    rescheduleModal.classList.remove("flex");
    activeBookingId  = null;
    selectedWindowId = null;
    submitRescheduleBtn.textContent = "Confirm Reschedule";
  }

  function renderSlots(windows) {
    const available = windows.filter(w => !w.is_full);
    if (!available.length) {
      rescheduleSlotsContainer.innerHTML = '<p class="text-sm text-slate-400">No available slots on this date.</p>';
      return;
    }
    rescheduleSlotsContainer.innerHTML = available.map(w => `
      <label class="flex items-center gap-3 rounded-xl border border-slate-200 px-4 py-3 cursor-pointer hover:border-[#315b7e] has-[:checked]:border-[#315b7e] has-[:checked]:bg-[#eaf4fb]">
        <input type="radio" name="rescheduleSlot" value="${w.window_id}" class="accent-[#315b7e]" />
        <span class="text-sm text-slate-700">${w.window_label}</span>
        <span class="ml-auto text-xs text-slate-400">${w.remaining} slot${w.remaining !== 1 ? "s" : ""} left</span>
      </label>`).join("");
    rescheduleSlotsContainer.querySelectorAll('input[name="rescheduleSlot"]').forEach(radio => {
      radio.addEventListener("change", () => {
        selectedWindowId = parseInt(radio.value, 10);
        enableSubmitIfReady();
      });
    });
  }

  function enableSubmitIfReady() {
    const ready = !!rescheduleDate.value && !!selectedWindowId;
    submitRescheduleBtn.disabled = !ready;
    submitRescheduleBtn.className = ready
      ? "w-full rounded-xl bg-[#315b7e] px-4 py-3 text-sm font-semibold text-white hover:bg-[#274a67] transition"
      : "w-full rounded-xl bg-slate-300 px-4 py-3 text-sm font-semibold text-white cursor-not-allowed transition";
  }

  function showRescheduleMessage(type, text) {
    const styles = { success: "border-green-200 bg-green-50 text-green-700", error: "border-red-200 bg-red-50 text-red-700" };
    rescheduleMessage.className = `mb-4 rounded-xl border px-4 py-3 text-sm ${styles[type]}`;
    rescheduleMessage.textContent = text;
    rescheduleMessage.classList.remove("hidden");
  }

  function hideRescheduleMessage() {
    rescheduleMessage.classList.add("hidden");
    rescheduleMessage.textContent = "";
  }

  function handleCancel(booking) {
    openCancelModal(booking);
  }

  function openCancelModal(booking) {
    cancelTargetBooking = booking;
    cancelBookingRefEl.textContent = booking.booking_reference;
    hideCancelMessage();
    confirmCancelBtn.disabled = false;
    confirmCancelBtn.textContent = "Cancel";
    cancelModal.classList.remove("hidden");
    cancelModal.classList.add("flex");
    if (window.lucide) lucide.createIcons();
  }

  function closeCancelModal() {
    cancelModal.classList.add("hidden");
    cancelModal.classList.remove("flex");
    cancelTargetBooking = null;
  }

  function showCancelMessage(type, text) {
    const styles = { error: "border-red-200 bg-red-50 text-red-700" };
    cancelMessageEl.className = `mb-4 rounded-xl border px-4 py-3 text-sm ${styles[type] || styles.error}`;
    cancelMessageEl.textContent = text;
    cancelMessageEl.classList.remove("hidden");
  }

  function hideCancelMessage() {
    cancelMessageEl.classList.add("hidden");
    cancelMessageEl.textContent = "";
  }

  closeCancelModalBtn.addEventListener("click", closeCancelModal);
  keepBookingBtn.addEventListener("click", closeCancelModal);
  cancelModal.addEventListener("click", (e) => { if (e.target === cancelModal) closeCancelModal(); });

  confirmCancelBtn.addEventListener("click", async () => {
    if (!cancelTargetBooking) return;
    confirmCancelBtn.disabled = true;
    confirmCancelBtn.textContent = "Cancelling...";
    try {
      await API.cancelBooking(cancelTargetBooking.booking_id);
      closeCancelModal();
      await loadAppointments();
    } catch (error) {
      showCancelMessage("error", error.message || "Cancellation failed. Please try again.");
      confirmCancelBtn.disabled = false;
      confirmCancelBtn.textContent = "Cancel";
    }
  });

  function formatDate(dateStr) {
    if (!dateStr) return "—";
    const d = new Date(dateStr + "T00:00:00");
    return d.toLocaleDateString("en-PH", { year: "numeric", month: "long", day: "numeric" });
  }

  // Expose for coordination with the notification poller (pickup popup sequencing).
  window._refreshAppointments = loadAppointments;
  setInterval(loadAppointments, 15000);
  setInterval(loadGroomingCapacity, 15000);
})();
