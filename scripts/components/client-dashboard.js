// Connected to pages/client/dashboard.html
// Depends on: api.js (loaded before this script)

// Client dashboard shell: mobile sidebar, profile name, and logout.
// Icons use the local Phosphor regular SVG sprite (no hydration required).
// Connected to the sidebar/profile controls in pages/client/dashboard.html.
function scheduleCustomerDashboardIdleTask(task) {
  if (typeof window.requestIdleCallback === "function") {
    window.requestIdleCallback(task, { timeout: 1200 });
    return;
  }

  setTimeout(task, 200);
}

function scheduleCustomerDashboardAfterPaint(task) {
  const schedule = () => scheduleCustomerDashboardIdleTask(task);
  if (typeof window.requestAnimationFrame === "function") {
    window.requestAnimationFrame(schedule);
    return;
  }

  schedule();
}

(function () {
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
  const navigationLinks = sidebar.querySelectorAll(".portal-nav-item");

  function setActiveNavigation(activeLink) {
    navigationLinks.forEach((link) => {
      if (link === activeLink) link.setAttribute("aria-current", "page");
      else link.removeAttribute("aria-current");
    });
  }

  function syncActiveNavigation() {
    const activeLink = Array.from(navigationLinks).find((link) =>
      new URL(link.href, window.location.href).pathname === window.location.pathname
    );
    if (activeLink) setActiveNavigation(activeLink);
  }

  navigationLinks.forEach((link) => {
    link.addEventListener("click", (event) => {
      if (!event.defaultPrevented && !event.ctrlKey && !event.metaKey && !event.shiftKey && !event.altKey) {
        setActiveNavigation(link);
      }
    });
  });
  syncActiveNavigation();
  window.addEventListener("pageshow", syncActiveNavigation);

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
  const loadDashboardProfile = async () => {
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
    } catch (error) {
      if (API.isAuthenticationError?.(error) || !API.hasAuthenticatedSession?.("customer")) {
        API.redirectToSignIn?.();
        return;
      }

      // A temporary API outage must not bounce between dashboard and sign-in.
      console.error("Unable to load the customer profile.", error);
    }
  };
  scheduleCustomerDashboardAfterPaint(loadDashboardProfile);

  // Logout section: end the customer session and return to sign in.
  if (logoutBtn) {
    logoutBtn.addEventListener("click", async () => {
      try {
        await API.logout("customer");
      } finally {
        API.redirectToSignIn({ replace: true });
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
  let notificationsLoading = false;
  let shownNotifications = [];
  const currentTab = window.ClientNotificationUI.ensureDropdownControls(dropdown, () => renderNotifList(shownNotifications));

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
    void loadNotifications();
    positionDropdown();
    dropdown.style.display = "flex";
    bellBtn.setAttribute("aria-expanded", "true");
  }

  function closeDropdown() {
    dropdown.style.display = "none";
    bellBtn.setAttribute("aria-expanded", "false");
  }

  function positionDropdown() {
    const rect      = bellBtn.getBoundingClientRect();
    const gap       = 8;
    const margin    = 12;
    const dropWidth = Math.min(384, window.innerWidth - margin * 2);

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
    if (notificationsLoading) return;
    notificationsLoading = true;

    try {
      const data = await API.getCustomerNotifications();

      // Badge
      const count = data.unread_count || 0;
      window.ClientNotificationUI.setUnreadCount(dropdown, Number(count));
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
      }
    } catch { /* silent — non-critical */ }
    finally { notificationsLoading = false; }
  }

  function renderNotifList(notifications) {
    shownNotifications = notifications;
    window.ClientNotificationUI.render(list, notifications, currentTab(), true, formatNotificationMessage);

    // Mark single notification as read on click
    list.querySelectorAll("[data-client-notification-index]").forEach((el) => {
      el.addEventListener("click", async () => {
        const notification = notifications[Number(el.dataset.clientNotificationIndex)];
        const id = notification?.id;
        if (!id) return;
        try {
          await API.markCustomerNotificationRead(id);

          if (
            ["pet_information_updated"].includes(
              notification?.type,
            )
            && notification.destination
          ) {
            window.location.href = notification.destination;
            return;
          }

          await loadNotifications();
        } catch { /* silent */ }
      });
    });

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

  // Keep the notification request behind the first dashboard paint.
  scheduleCustomerDashboardAfterPaint(loadNotifications);
  setInterval(() => {
    if (document.visibilityState === "visible") void loadNotifications();
  }, 30000);
})();

// ── Appointments, Grooming Tracker & History ─────────────────────────────────
(function () {
  const scheduleDashboardDataIdle = typeof scheduleCustomerDashboardIdleTask === "function"
    ? scheduleCustomerDashboardIdleTask
    : (task) => setTimeout(task, 0);
  const scheduleDashboardDataAfterPaint = typeof scheduleCustomerDashboardAfterPaint === "function"
    ? scheduleCustomerDashboardAfterPaint
    : scheduleDashboardDataIdle;
  const appointmentsList         = document.getElementById("appointmentsList");
  const groomingTrackerEl        = document.getElementById("groomingTracker");
  const groomingLiveBadgeEl      = document.getElementById("groomingLiveBadge");
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
  const rescheduleAvailabilityIndicator = document.getElementById("rescheduleAvailabilityIndicator");
  const rescheduleMessage        = document.getElementById("rescheduleMessage");
  const submitRescheduleBtn      = document.getElementById("submitRescheduleBtn");
  const cancelModal              = document.getElementById("cancelModal");
  const closeCancelModalBtn      = document.getElementById("closeCancelModal");
  const cancelBookingRefEl       = document.getElementById("cancelBookingRef");
  const confirmCancelBtn         = document.getElementById("confirmCancelBtn");
  const keepBookingBtn           = document.getElementById("keepBookingBtn");
  const cancelMessageEl          = document.getElementById("cancelMessage");

  let activeBookingId     = null;
  let activeRescheduleBooking = null;
  let selectedWindowId    = null;
  let cancelTargetBooking = null;
  let rescheduleLoadId    = 0;
  let dashboardPetsLoadState = "idle";
  let appointmentsLoading = false;
  let groomingCapacityLoading = false;
  let rescheduleClinicStatus = {
    stoppedToday: false,
    blockedDates: [],
    groomingAvailability: null,
  };

  const UPCOMING_APPOINTMENT_STATUSES = new Set(["waiting_to_arrive", "waiting"]);
  const RESCHEDULE_MAX_DAYS_AHEAD = 2;

  document.addEventListener("DOMContentLoaded", () => {
    setRescheduleDateRange(getRescheduleTodayKey());
    void loadAppointments();

    scheduleDashboardDataAfterPaint(async () => {
      await loadDashboardPets();
      scheduleDashboardDataIdle(async () => {
        await loadGroomingCapacity();
        await Promise.resolve(window.AppClock?.load?.()).catch(() => {});
        setRescheduleDateRange(getRescheduleTodayKey());
      });
    });
  });

  // ── Load & route data ──────────────────────────────────────────────────────

  async function loadDashboardPets() {
    if (dashboardPetsLoadState === "loading" || dashboardPetsLoadState === "loaded") return;
    dashboardPetsLoadState = "loading";

    try {
      const data = await API.getUserPets({ archived: 0 });
      renderMyPetsSummary(data.pets || []);
      dashboardPetsLoadState = "loaded";
    } catch {
      renderMyPetsSummary([]);
      dashboardPetsLoadState = "error";
    }
  }

  async function loadAppointments() {
    if (appointmentsLoading) return;
    appointmentsLoading = true;
    let data;

    try {
      data = await API.getBookingHistory({ historyLimit: 6 });
    } catch (error) {
      renderDashboardPanelError(appointmentsList, "Failed to load schedule. Please try again.");
      renderDashboardPanelError(groomingTrackerEl, "Failed to load grooming status. Please try again.");
      renderDashboardPanelError(groomingHistoryEl, "Failed to load grooming history. Please try again.");
      renderUpcomingAppointmentsSummary([]);
      renderPastGroomingSummary([], 0);
      return;
    } finally {
      appointmentsLoading = false;
    }

    const active = Array.isArray(data?.bookings) ? data.bookings : [];
    const history = Array.isArray(data?.history) ? data.history : [];
    const historyTotal = Number.isFinite(Number(data?.history_total))
      ? Number(data.history_total)
      : history.length;
    const scheduled = active.filter(isUpcomingAppointment);
    const atClinic = active.filter(b =>
      ["checked_in", "in_progress", "for_payment", "released"].includes(b?.status)
    ).filter(b => b.show_grooming_tracker !== false);

    renderDashboardPanel(appointmentsList, "schedule", () => {
      renderAppointments(scheduled);
      renderUpcomingAppointmentsSummary(scheduled);
    });
    renderDashboardPanel(groomingTrackerEl, "grooming status", () => {
      renderGroomingTracker(atClinic);
    });
    renderDashboardPanel(groomingHistoryEl, "grooming history", () => {
      renderGroomingHistory(history);
      renderPastGroomingSummary(history, historyTotal);
    });
  }

  function renderDashboardPanel(target, label, render) {
    try {
      render();
    } catch (error) {
      console.error(`Failed to render customer ${label}.`, error);
      renderDashboardPanelError(target, `Failed to display ${label}. Please refresh.`);
    }
  }

  function renderDashboardPanelError(target, message) {
    if (!target) return;
    if (target === groomingTrackerEl) groomingLiveBadgeEl?.classList.add("hidden");

    target.innerHTML = `
      <div class="flex min-h-[140px] flex-col items-center justify-center py-[22px] text-center" role="alert">
        <svg class="ph-icon w-9 h-9 mx-auto text-portal-muted-icon" viewBox="0 0 256 256" aria-hidden="true" focusable="false"><use href="../../assets/icons/phosphor.svg#warning-circle"></use></svg>
        <p class="mt-3 text-sm text-portal-danger">${escapeDashboardHtml(message)}</p>
      </div>`;
  }

  // ── Appointments section ───────────────────────────────────────────────────

  async function loadGroomingCapacity() {
    if (groomingCapacityLoading) return;
    groomingCapacityLoading = true;

    try {
      const data = await API.getGroomingCapacity();
      renderGroomingCapacity(data);
    } catch {
      renderGroomingCapacity(null);
    } finally {
      groomingCapacityLoading = false;
    }
  }

  function getRescheduleTodayKey() {
    const appClockDate = window.AppClock?.todayKey?.();
    if (appClockDate) return appClockDate;

    return new Intl.DateTimeFormat("en-CA", {
      timeZone: "Asia/Manila",
      year: "numeric",
      month: "2-digit",
      day: "2-digit",
    }).format(new Date());
  }

  function addDaysToDateKey(dateKey, days) {
    const [year, month, day] = String(dateKey).split("-").map(Number);
    const date = new Date(year, month - 1, day);
    date.setDate(date.getDate() + days);

    return [
      date.getFullYear(),
      String(date.getMonth() + 1).padStart(2, "0"),
      String(date.getDate()).padStart(2, "0"),
    ].join("-");
  }

  function setRescheduleDateRange(todayKey) {
    if (!rescheduleDate || !todayKey) return;

    rescheduleDate.min = todayKey;
    rescheduleDate.max = addDaysToDateKey(todayKey, RESCHEDULE_MAX_DAYS_AHEAD);
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
        <div class="flex min-h-[140px] flex-col items-center justify-center py-[22px] text-center">
          <svg class="ph-icon w-10 h-10 mx-auto text-portal-muted-icon" viewBox="0 0 256 256" aria-hidden="true" focusable="false"><use href="../../assets/icons/phosphor.svg#calendar-x"></use></svg>
          <p class="mt-3 text-portal-muted text-sm">No upcoming schedule.</p>
        </div>`;
      return;
    }

    appointmentsList.innerHTML = bookings.map(b => buildBookingCard(b)).join("");

    bookings.forEach(b => {
      const rescheduleBtn = document.getElementById(`reschedule-${b.booking_id}`);
      const cancelBtn     = document.getElementById(`cancel-${b.booking_id}`);
      if (rescheduleBtn) rescheduleBtn.addEventListener("click", () => openRescheduleModal(b));
      if (cancelBtn)     cancelBtn.addEventListener("click",     () => handleCancel(b));
    });

  }

  function buildBookingCard(b) {
    const statusConfig  = getStatusConfig(b.status);
    const timeLabel     = b.time_window?.window_label ?? "—";
    const isActionable  = b.status === "waiting_to_arrive";
    const rescheduleAttrs = `id="reschedule-${b.booking_id}" class="flex-1 rounded-xl border border-[#315b7e] px-3 py-2 text-xs font-semibold text-portal-primary hover:bg-[#315b7e] hover:text-white transition"`;
    const cancelAttrs     = `id="cancel-${b.booking_id}" class="flex-1 rounded-xl border border-red-300 px-3 py-2 text-xs font-semibold text-portal-danger hover:bg-red-50 transition"`;
    const rescheduleLabel = "Reschedule";
    const cancelLabel     = "Cancel";

    const buttons = isActionable
      ? `<div class="flex gap-2 mt-3">
           <button type="button" ${rescheduleAttrs}>${rescheduleLabel}</button>
           <button type="button" ${cancelAttrs}>${cancelLabel}</button>
         </div>`
      : "";

    return `
      <div class="rounded-[13px] border border-portal-border bg-portal-record p-4 [overflow-wrap:anywhere] [&>.flex]:flex-wrap last:mb-0 mb-4">
        <div class="flex items-start justify-between gap-2 mb-1">
          <div>
            <p class="text-sm font-semibold text-portal-text">${b.booking_reference}</p>
            <p class="text-xs text-portal-muted mt-0.5">${formatDate(b.booking_date)} &middot; ${timeLabel}</p>
          </div>
          <span class="rounded-full px-2.5 py-1 text-[11px] font-semibold whitespace-nowrap ${statusConfig.classes}">
            ${statusConfig.label}
          </span>
        </div>
        <p class="text-xs text-portal-muted">${b.number_of_pets} pet${b.number_of_pets > 1 ? "s" : ""}</p>
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
    const active = toNumber(queue.active ?? (queued + inProgress));
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

    if (groomingQueueCountEl) groomingQueueCountEl.textContent = String(active);
    if (groomingQueueSummaryEl) {
      groomingQueueSummaryEl.textContent = `${inProgress} in progress`;
      groomingQueueSummaryEl.className = "text-sm text-portal-muted mt-1";
    }
    if (groomingCapacityTextEl) {
      groomingCapacityTextEl.textContent = isFull
        ? `${used} / ${max} daily capacity - max reached`
        : `${used} / ${max} daily capacity - ${remaining} left`;
    }
    if (groomingCapacityBarEl) {
      groomingCapacityBarEl.style.width = `${percent}%`;
      groomingCapacityBarEl.className = "h-full rounded-full bg-portal-accent transition-all duration-300";
    }
    if (groomingCapacityBadgeEl) {
      groomingCapacityBadgeEl.textContent = isFull ? "Full" : isBusy ? "Busy" : "Open";
      groomingCapacityBadgeEl.className = isFull
        ? "rounded-full bg-portal-danger-soft px-2.5 py-1 text-[11px] font-semibold text-portal-danger"
        : isBusy
          ? "rounded-full bg-portal-warning-soft px-2.5 py-1 text-[11px] font-semibold text-portal-warning"
          : "rounded-full bg-portal-success-soft px-2.5 py-1 text-[11px] font-semibold text-portal-success";
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
        ? "text-sm text-portal-muted mt-1"
        : "text-sm text-portal-muted mt-1";
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
      groomingLiveBadgeEl?.classList.add("hidden");
      groomingTrackerEl.innerHTML = `
        <div class="flex min-h-[140px] flex-col items-center justify-center py-[22px] text-center">
          <svg class="ph-icon w-10 h-10 mx-auto text-portal-muted-icon" viewBox="0 0 256 256" aria-hidden="true" focusable="false"><use href="../../assets/icons/phosphor.svg#scissors"></use></svg>
          <p class="mt-3 text-portal-muted text-sm">No pets at the clinic right now.</p>
        </div>`;
      return;
    }

    groomingTrackerEl.innerHTML = bookings.map(b => buildTrackerCard(b)).join("");
    groomingLiveBadgeEl?.classList.toggle("hidden", !bookings.some(hasQueuedOrGroomingPet));
  }

  function hasQueuedOrGroomingPet(booking) {
    const pets = Array.isArray(booking?.pets) ? booking.pets : [];
    return pets.some(pet =>
      pet.clinic_referred !== true
      && pet.active_in_grooming !== false
      && ["checked_in", "in_progress"].includes(
        String(pet.grooming_status ?? booking.status ?? "").toLowerCase(),
      )
    );
  }

  function buildTrackerCard(b) {
    const timeLabel  = b.time_window?.window_label ?? "—";
    const trackerPets = (b.pets || []).filter(p => p.clinic_referred !== true);
    const petNames   = trackerPets.map(p => p.pet_name).filter(Boolean).join(", ") || "—";
    const petCount   = trackerPets.length;
    const prePaid    = b.paid
      ? `<span class="ml-2 rounded-full bg-portal-success-soft px-2 py-0.5 text-[11px] font-semibold text-portal-success">Pre-Paid ✓</span>`
      : "";

    const steps = [
      { key: "checked_in",  label: "Checked In"       },
      { key: "in_progress", label: "Being Groomed"     },
      { key: "for_payment", label: "Ready for Pickup"  },
    ];
    const stepOrder  = { checked_in: 0, in_progress: 1, for_payment: 2, released: 2 };
    const current    = stepOrder[b.status] ?? 0;
    const stepThemes = {
      checked_in:  { color: "var(--portal-warning, #806a40)" },
      in_progress: { color: "var(--portal-primary, #486780)" },
      for_payment: { color: "var(--portal-success, #476857)" },
      released:    { color: "var(--portal-success, #476857)" },
    };

    const stepCircles = steps.map((step, i) => {
      const active = i === current;
      const stepTheme = stepThemes[step.key];
      const circleClass = active
        ? "text-white"
        : "bg-white text-portal-muted border-slate-300";
      const circleStyle = active
        ? `style="background-color: ${stepTheme.color}; border-color: ${stepTheme.color};"`
        : "";
      const labelClass  = active
        ? "font-semibold"
        : "text-portal-muted";
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
      <div class="rounded-[13px] border border-portal-border bg-portal-record p-4 [overflow-wrap:anywhere] [&>.flex]:flex-wrap last:mb-0 mb-4">
        <div class="flex items-start justify-between gap-2 mb-1">
          <div>
            <p class="text-sm font-semibold text-portal-text">${b.booking_reference}${prePaid}</p>
            <p class="text-xs text-portal-muted mt-0.5">${formatDate(b.booking_date)} &middot; ${timeLabel}</p>
          </div>
        </div>
        <p class="text-xs text-portal-muted mb-5">${petNames} &middot; ${petCount} pet${petCount > 1 ? "s" : ""}</p>
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
        <div class="flex min-h-[140px] flex-col items-center justify-center py-[22px] text-center">
          <svg class="ph-icon w-10 h-10 mx-auto text-portal-muted-icon" viewBox="0 0 256 256" aria-hidden="true" focusable="false"><use href="../../assets/icons/phosphor.svg#clock-counter-clockwise"></use></svg>
          <p class="mt-3 text-portal-muted text-sm">No grooming history yet.</p>
        </div>`;
      return;
    }

    groomingHistoryEl.innerHTML = `
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
        ${history.map(b => buildHistoryCard(b)).join("")}
      </div>`;
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
    const timeLabel = b?.time_window?.window_label ?? "—";
    const pets = Array.isArray(b?.pets) ? b.pets : [];
    const petNames = pets.map(p => p?.pet_name).filter(Boolean).join(", ") || "—";
    const walkInBadge = b?.booking_type === "walk_in"
      ? `<span class="rounded-full bg-portal-active px-2 py-0.5 text-[11px] font-semibold text-portal-primary">Walk-in</span>`
      : "";
    const paidBadge = b?.paid
      ? `<span class="rounded-full bg-portal-success-soft px-2 py-0.5 text-[11px] font-semibold text-portal-success">Paid ✓</span>`
      : "";

    return `
      <div class="rounded-[13px] border border-portal-border bg-portal-record p-4 [overflow-wrap:anywhere] [&>.flex]:flex-wrap last:mb-0">
        <div class="flex items-start justify-between gap-2 mb-1">
          <p class="text-sm font-semibold text-portal-text">${escapeDashboardHtml(b?.booking_reference || "Booking")}</p>
          <div class="flex flex-wrap justify-end gap-1.5">${walkInBadge}${paidBadge}</div>
        </div>
        <p class="text-xs text-portal-muted mb-1">${formatDate(b?.booking_date)} &middot; ${escapeDashboardHtml(timeLabel)}</p>
        <p class="text-xs text-portal-muted">${escapeDashboardHtml(petNames)}</p>
      </div>`;
  }

  function escapeDashboardHtml(value) {
    return String(value ?? "").replace(/[&<>"']/g, (character) => ({
      "&": "&amp;",
      "<": "&lt;",
      ">": "&gt;",
      '"': "&quot;",
      "'": "&#039;",
    })[character]);
  }

  // ── Status config ──────────────────────────────────────────────────────────

  function getStatusConfig(status) {
    const map = {
      waiting_to_arrive: { label: "Waiting",           classes: "bg-portal-active text-portal-primary" },
      checked_in:        { label: "Checked In",         classes: "bg-portal-warning-soft text-portal-warning" },
      in_progress:       { label: "In Progress",        classes: "bg-portal-active text-portal-primary" },
      for_payment:       { label: "Ready for Pickup",   classes: "bg-portal-success-soft text-portal-success" },
      released:          { label: "Ready for Pickup",   classes: "bg-portal-success-soft text-portal-success" },
      cancelled:         { label: "Cancelled",          classes: "bg-portal-danger-soft text-portal-danger" },
      no_show:           { label: "No Show",            classes: "bg-portal-warning-soft text-portal-warning" },
      archived:          { label: "Completed",          classes: "bg-portal-success-soft text-portal-success" },
    };
    return map[status] || { label: status, classes: "bg-slate-100 text-slate-600" };
  }

  // ── Reschedule modal ───────────────────────────────────────────────────────

  rescheduleDate.addEventListener("change", async function () {
    const requestId = ++rescheduleLoadId;
    selectedWindowId = null;
    hideRescheduleAvailability();
    hideRescheduleMessage();
    enableSubmitIfReady();
    const date = this.value;
    if (!date) return;

    const dateError = getRescheduleDateError(date);
    this.setCustomValidity(dateError || "");
    if (dateError) {
      rescheduleSlotsContainer.innerHTML = '<p class="text-sm text-portal-muted">Choose another date to see available slots.</p>';
      showRescheduleMessage("error", dateError);
      return;
    }

    rescheduleSlotsContainer.innerHTML = '<p class="text-sm text-portal-muted">Loading slots...</p>';
    try {
      const data = await API.getTimeslots(date);
      if (requestId !== rescheduleLoadId) return;

      if (data.cutoff_passed) {
        const cutoffLabel = data.availability?.pre_registration_cutoff_label || "the configured cutoff time";
        rescheduleSlotsContainer.innerHTML = '<p class="text-sm text-portal-muted">Choose another date to see available slots.</p>';
        showRescheduleMessage("error", `Same-day grooming pre-registration closed at ${cutoffLabel}. Please choose another date.`);
        return;
      }

      renderSlots(data);
    } catch (error) {
      if (requestId !== rescheduleLoadId) return;
      rescheduleSlotsContainer.innerHTML = '<p class="text-sm text-portal-danger">Failed to load slots. Try again.</p>';
    }
  });

  submitRescheduleBtn.addEventListener("click", async () => {
    if (!activeBookingId || !selectedWindowId || !rescheduleDate.value) return;

    const dateError = getRescheduleDateError(rescheduleDate.value);
    if (dateError || isCurrentRescheduleSelection(rescheduleDate.value, selectedWindowId)) {
      showRescheduleMessage(
        "error",
        dateError || "Choose a different date or time slot from the current schedule.",
      );
      enableSubmitIfReady();
      return;
    }

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

  async function openRescheduleModal(booking) {
    const requestId = ++rescheduleLoadId;
    activeBookingId  = booking.booking_id;
    activeRescheduleBooking = booking;
    selectedWindowId = null;
    rescheduleBookingRef.textContent =
      `Rescheduling: ${booking.booking_reference} (${booking.reschedule_count ?? 0} of 2 uses)`;
    rescheduleDate.value = "";
    rescheduleDate.setCustomValidity("");
    rescheduleDate.disabled = true;
    rescheduleSlotsContainer.innerHTML = '<p class="text-sm text-portal-muted">Select a date to see available slots.</p>';
    hideRescheduleAvailability();
    hideRescheduleMessage();
    enableSubmitIfReady();
    rescheduleModal.classList.remove("hidden");
    rescheduleModal.classList.add("flex");

    try {
      await window.AppClock?.load?.();
      const data = await API.getClinicStatus();
      if (requestId !== rescheduleLoadId || activeBookingId !== booking.booking_id) return;

      setRescheduleDateRange(getRescheduleTodayKey());
      rescheduleClinicStatus = {
        stoppedToday: Boolean(data.stopped_today),
        blockedDates: Array.isArray(data.blocked_dates) ? data.blocked_dates : [],
        groomingAvailability: data.availability?.grooming || null,
      };
      rescheduleDate.disabled = false;
    } catch {
      if (requestId !== rescheduleLoadId || activeBookingId !== booking.booking_id) return;

      showRescheduleMessage("error", "Could not load the current scheduling rules. Close this window and try again.");
    }
  }

  function closeModal() {
    rescheduleLoadId += 1;
    rescheduleModal.classList.add("hidden");
    rescheduleModal.classList.remove("flex");
    activeBookingId  = null;
    activeRescheduleBooking = null;
    selectedWindowId = null;
    rescheduleDate.disabled = false;
    rescheduleDate.setCustomValidity("");
    hideRescheduleAvailability();
    submitRescheduleBtn.textContent = "Confirm Reschedule";
  }

  function renderSlots(data) {
    const windows = Array.isArray(data.windows) ? data.windows : [];
    const selectedDate = rescheduleDate.value;
    const bookingDate = String(activeRescheduleBooking?.booking_date || "");
    const sameDate = selectedDate === bookingDate;
    const petCount = Math.max(1, Number(activeRescheduleBooking?.number_of_pets) || 1);
    const capacity = Math.max(0, Number(data.capacity) || 0);
    const totalBooked = Math.max(0, Number(data.total_booked) || 0);
    const bookedWithoutCurrent = Math.max(
      0,
      totalBooked - (sameDate ? petCount : 0),
    );
    const remaining = Math.max(0, capacity - bookedWithoutCurrent);
    const bookingFits = capacity === 0 || bookedWithoutCurrent + petCount <= capacity;

    showRescheduleAvailability(remaining);

    const available = windows.filter((window) =>
      bookingFits
      && !window.is_cutoff
      && !isRescheduleSlotPast(window, selectedDate)
      && !isCurrentRescheduleSelection(selectedDate, window.window_id, window.window_label)
    );

    if (!available.length) {
      rescheduleSlotsContainer.innerHTML = `<p class="text-sm text-portal-muted">${
        sameDate
          ? "No other available time slots on this date."
          : "No available time slots on this date."
      }</p>`;
      return;
    }

    rescheduleSlotsContainer.innerHTML = available.map(w => `
      <label class="flex items-center gap-3 rounded-xl border border-slate-200 px-4 py-3 cursor-pointer hover:border-[#315b7e] has-[:checked]:border-[#315b7e] has-[:checked]:bg-[#eaf4fb]">
        <input type="radio" name="rescheduleSlot" value="${w.window_id}" class="accent-[#315b7e]" />
        <span class="text-sm text-portal-text">${escapeRescheduleHtml(w.window_label)}</span>
      </label>`).join("");
    rescheduleSlotsContainer.querySelectorAll('input[name="rescheduleSlot"]').forEach(radio => {
      radio.addEventListener("change", () => {
        selectedWindowId = parseInt(radio.value, 10);
        enableSubmitIfReady();
      });
    });
  }

  function enableSubmitIfReady() {
    const ready = !!rescheduleDate.value
      && !!selectedWindowId
      && !getRescheduleDateError(rescheduleDate.value)
      && !isCurrentRescheduleSelection(rescheduleDate.value, selectedWindowId);
    submitRescheduleBtn.disabled = !ready;
    submitRescheduleBtn.className = ready
      ? "w-full rounded-xl bg-portal-primary px-4 py-3 text-sm font-semibold text-white hover:bg-portal-primary-hover transition"
      : "w-full rounded-xl bg-slate-300 px-4 py-3 text-sm font-semibold text-white cursor-not-allowed transition";
  }

  function showRescheduleMessage(type, text) {
    const styles = { success: "border-green-200 bg-portal-success-soft text-portal-success", error: "border-red-200 bg-portal-danger-soft text-portal-danger" };
    rescheduleMessage.className = `mb-4 rounded-xl border px-4 py-3 text-sm ${styles[type]}`;
    rescheduleMessage.textContent = text;
    rescheduleMessage.classList.remove("hidden");
  }

  function hideRescheduleMessage() {
    rescheduleMessage.classList.add("hidden");
    rescheduleMessage.textContent = "";
  }

  function getRescheduleDateError(dateKey) {
    if (!dateKey) return "";

    const today = rescheduleDate.min || getRescheduleTodayKey();
    const lastAvailableDate = rescheduleDate.max
      || addDaysToDateKey(today, RESCHEDULE_MAX_DAYS_AHEAD);

    if (dateKey < today) {
      return "Past dates are not available for rescheduling.";
    }

    if (dateKey > lastAvailableDate) {
      return "Grooming can be pre-registered up to three days in advance.";
    }

    if (rescheduleClinicStatus.stoppedToday && dateKey === today) {
      return "The clinic is not accepting grooming pre-registrations today.";
    }

    const closure = rescheduleClinicStatus.blockedDates.find((block) =>
      dateKey >= block.start_date && dateKey <= block.end_date
    );
    if (closure) {
      return closure.reason || "The clinic is not accepting grooming pre-registrations on this date.";
    }

    const cutoff = rescheduleClinicStatus.groomingAvailability?.pre_registration_cutoff_time;
    if (dateKey === today && cutoff && isRescheduleCutoffPassed(cutoff)) {
      const cutoffLabel = rescheduleClinicStatus.groomingAvailability?.pre_registration_cutoff_label
        || "the configured cutoff time";
      return `Same-day grooming pre-registration closed at ${cutoffLabel}. Please choose another date.`;
    }

    return "";
  }

  function isRescheduleCutoffPassed(cutoff) {
    const [hours, minutes] = String(cutoff).split(":").map(Number);
    if (!Number.isFinite(hours) || !Number.isFinite(minutes)) return false;

    const currentMinutes = window.AppClock?.currentMinutes?.();
    if (!Number.isFinite(currentMinutes)) return false;

    return currentMinutes > (hours * 60 + minutes);
  }

  function isRescheduleSlotPast(windowData, dateKey) {
    if (windowData.is_past) return true;
    if (dateKey !== getRescheduleTodayKey()) return false;

    const [hours, minutes] = String(windowData.start_time || "").split(":").map(Number);
    if (!Number.isFinite(hours) || !Number.isFinite(minutes)) return false;

    const currentMinutes = window.AppClock?.currentMinutes?.();
    return Number.isFinite(currentMinutes)
      && currentMinutes >= (hours * 60 + minutes);
  }

  function isCurrentRescheduleSelection(dateKey, windowId, windowLabel = null) {
    if (!activeRescheduleBooking || dateKey !== String(activeRescheduleBooking.booking_date || "")) {
      return false;
    }

    const currentWindowId = activeRescheduleBooking.time_window?.window_id;
    if (currentWindowId !== null && currentWindowId !== undefined) {
      return Number(currentWindowId) === Number(windowId);
    }

    return Boolean(windowLabel)
      && String(activeRescheduleBooking.time_window?.window_label || "") === String(windowLabel);
  }

  function showRescheduleAvailability(remaining) {
    rescheduleAvailabilityIndicator.textContent =
      `${remaining} slot${remaining === 1 ? "" : "s"} left`;
    rescheduleAvailabilityIndicator.classList.remove("hidden");
  }

  function hideRescheduleAvailability() {
    rescheduleAvailabilityIndicator.textContent = "";
    rescheduleAvailabilityIndicator.classList.add("hidden");
  }

  function escapeRescheduleHtml(value) {
    return String(value || "").replace(/[&<>"']/g, (character) => ({
      "&": "&amp;",
      "<": "&lt;",
      ">": "&gt;",
      '"': "&quot;",
      "'": "&#039;",
    })[character]);
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
  }

  function closeCancelModal() {
    cancelModal.classList.add("hidden");
    cancelModal.classList.remove("flex");
    cancelTargetBooking = null;
  }

  function showCancelMessage(type, text) {
    const styles = { error: "border-red-200 bg-portal-danger-soft text-portal-danger" };
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
  setInterval(() => {
    if (document.visibilityState === "visible") void loadAppointments();
  }, 15000);
  setInterval(() => {
    if (document.visibilityState === "visible") void loadGroomingCapacity();
  }, 15000);
})();
