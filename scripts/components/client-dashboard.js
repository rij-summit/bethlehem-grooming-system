// Connected to pages/client/dashboard.html
// Depends on: api.js (loaded before this script)

// Client dashboard shell: Lucide icons, mobile sidebar, profile name, and logout.
// Connected to the sidebar/profile controls in pages/client/dashboard.html.
(function () {
  if (window.lucide) window.lucide.createIcons();

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
      window.location.href = "../../pages/sign-in/sign_in.html";
    }
  })();

  // Logout section: end the customer session and return to sign in.
  if (logoutBtn) {
    logoutBtn.addEventListener("click", async () => {
      try {
        await API.logout("customer");
      } finally {
        window.location.href = "../../pages/sign-in/sign_in.html";
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
      try { await API.markCustomerNotificationRead(notifId); } catch { /* silent */ }
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

      // Pickup alert popup
      const pickup = data.pickup_alert;
      if (pickup && !getShownPickups().includes(pickup.id)) {
        pickupMessage.textContent = pickup.message;
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
      const icon = notifIcon(n.type);
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
              <p class="text-sm text-slate-700 leading-snug">${n.message}</p>
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

  function notifIcon(type) {
    const icons = {
      reminder_24h:    "📅",
      reminder_3h:     "⏰",
      grooming_started:"✂️",
      ready_for_pickup:"🐾",
    };
    return icons[type] || "🔔";
  }

  function formatNotifTime(dateStr) {
    if (!dateStr) return "";
    return new Date(dateStr).toLocaleString("en-PH", {
      month: "short", day: "numeric",
      hour: "numeric", minute: "2-digit",
    });
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
  const rescheduleModal          = document.getElementById("rescheduleModal");
  const closeRescheduleModal     = document.getElementById("closeRescheduleModal");
  const rescheduleBookingRef     = document.getElementById("rescheduleBookingRef");
  const rescheduleDate           = document.getElementById("rescheduleDate");
  const rescheduleSlotsContainer = document.getElementById("rescheduleSlotsContainer");
  const rescheduleMessage        = document.getElementById("rescheduleMessage");
  const submitRescheduleBtn      = document.getElementById("submitRescheduleBtn");

  let activeBookingId  = null;
  let selectedWindowId = null;

  const today = new Date().toISOString().split("T")[0];
  rescheduleDate.min = today;

  document.addEventListener("DOMContentLoaded", loadAppointments);

  // ── Load & route data ──────────────────────────────────────────────────────

  async function loadAppointments() {
    try {
      const data    = await API.getBookingHistory();
      const active  = data.bookings || [];
      const history = data.history  || [];

      const scheduled = active.filter(b =>
        ["waiting_to_arrive", "cancelled", "no_show"].includes(b.status)
      );
      const atClinic  = active.filter(b =>
        ["checked_in", "in_progress", "for_payment"].includes(b.status)
      );

      renderAppointments(scheduled);
      renderGroomingTracker(atClinic);
      renderGroomingHistory(history);
    } catch {
      appointmentsList.innerHTML =
        '<div class="text-center py-10"><p class="text-sm text-red-500">Failed to load appointments.</p></div>';
    }
  }

  // ── Appointments section ───────────────────────────────────────────────────

  function renderAppointments(bookings) {
    if (!bookings.length) {
      appointmentsList.innerHTML = `
        <div class="text-center py-10">
          <i data-lucide="calendar-x" class="w-10 h-10 mx-auto text-slate-300"></i>
          <p class="mt-3 text-slate-400 text-sm">No upcoming appointments.</p>
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
    const canReschedule = isActionable && (b.reschedule_count ?? 0) < 2;
    const canCancel     = isActionable && (b.cancel_count ?? 0) < 2;

    const rescheduleAttrs = canReschedule
      ? `id="reschedule-${b.booking_id}" class="flex-1 rounded-xl border border-[#315b7e] px-3 py-2 text-xs font-semibold text-[#315b7e] hover:bg-[#315b7e] hover:text-white transition"`
      : `disabled class="flex-1 rounded-xl border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-300 cursor-not-allowed"`;

    const cancelAttrs = canCancel
      ? `id="cancel-${b.booking_id}" class="flex-1 rounded-xl border border-red-300 px-3 py-2 text-xs font-semibold text-red-500 hover:bg-red-50 transition"`
      : `disabled class="flex-1 rounded-xl border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-300 cursor-not-allowed"`;

    const rescheduleLabel = canReschedule
      ? "Reschedule"
      : `Reschedule (${b.reschedule_count ?? 0}/2)`;
    const cancelLabel = canCancel
      ? "Cancel"
      : (b.status === "cancelled" ? "Cancelled" : `Cancel (${b.cancel_count ?? 0}/2)`);

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
    const stepOrder  = { checked_in: 0, in_progress: 1, for_payment: 2 };
    const current    = stepOrder[b.status] ?? 0;
    const stepThemes = {
      checked_in:  { color: "#e5a800" },
      in_progress: { color: "#1d4ed8" },
      for_payment: { color: "#16a34a" },
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

  async function handleCancel(booking) {
    const confirmed = window.confirm(
      `Cancel booking ${booking.booking_reference}?\n\nThis action counts as 1 of your 2 allowed cancellations.`,
    );
    if (!confirmed) return;
    try {
      await API.cancelBooking(booking.booking_id);
      await loadAppointments();
    } catch (error) {
      alert(error.message || "Cancellation failed. Please try again.");
    }
  }

  function formatDate(dateStr) {
    if (!dateStr) return "—";
    const d = new Date(dateStr + "T00:00:00");
    return d.toLocaleDateString("en-PH", { year: "numeric", month: "long", day: "numeric" });
  }
})();
