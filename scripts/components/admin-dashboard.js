/*
 * Backend integration contract:
 * - Optional preload config: window.ADMIN_DASHBOARD_CONFIG = { bootstrap, endpoints, handlers, ... }
 * - Optional runtime bridge after Alpine init: window.adminDashboardUI.setData(...), setConfig(...), getState()
 * - Action hooks: loadDashboard, walkInBooking, checkIn, startGrooming, markDone, archive, viewDetails
 */
function adminDashboard() {
  return {
    activeTab: "incoming",
    todayCount: 0,
    weekCount: 0,
    currentCapacity: 0,
    maxCapacity: 20,
    notificationCount: 0,
    notificationsOpen: false,
    notifications: [],
    notifTab: "all",
    detailsModalOpen: false,
    detailsBooking: null,
    pendingActions: {},
    _pollFailures: {},
    // Use local date (not UTC) so the calendar defaults to the correct day in PH
    selectedDate: (() => {
      const d = new Date();
      return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;
    })(),
    get todayDate() {
      const d = new Date();
      return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;
    },
    get maxDate() {
      const d = new Date();
      d.setDate(d.getDate() + 3);
      return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;
    },
    noShowList: [],
    forPaymentList: [],
    clinicStopped: false,
    stopModal: { open: false, title: "", message: "" },
    paymentModal: {
      open: false,
      booking: null,
      isEarlyPayment: false,
      finalPrice: "",
      amountPaid: "",
      paymentMethod: "cash",
      notes: "",
      busy: false,
      error: "",
    },
    receiptModal: {
      open: false,
      ownerName: "",
      petName: "",
      serviceLabel: "",
      finalPrice: 0,
      amountPaid: 0,
      change: 0,
      paymentMethod: "",
      paidAt: "",
      isEarlyPayment: false,
    },
    config: {
      bootstrap: null,
      endpoints: {},
      handlers: {},
      walkInBookingUrl: "",
      detailPageUrl: "",
      enableOptimisticUpdates: false,
    },

    /*****************************************************************************************
     * BACKEND INTEGRATION AREA
     *
     * These arrays are intentionally empty by default.
     * Backend should populate them with real data.
     *****************************************************************************************/
    // MARK AS DONE FLOW
    // in_progress -> for_pickup
    //
    // Required backend actions:
    // 1. Update status to "for_pickup"
    // 2. Save completion timestamp
    // 3. Send SMS to client
    // 4. Update client dashboard / notifications
    // 5. Return updated appointment record

    // ARCHIVE FLOW
    // for_pickup -> archived record
    //
    // Required backend actions:
    // 1. Move or copy completed session data into archive storage/table
    // 2. Preserve important session details for history
    // 3. Remove it from active for_pickup list if archive is successful
    // 4. Return success response to frontend

    incomingList: [],
    queuedList: [],
    inProgressList: [],
    forPickupList: [],
    // forPickupList kept for backward-compat; new data comes as forPaymentList

    // Boots the dashboard, loads any backend config/data, and exposes the UI bridge.
    async init() {
      if (this._initialized) return;
      this._initialized = true;

      if (!localStorage.getItem("admin_token")) {
        window.location.href = "../../pages/admin/login.html";
        return;
      }

      this.registerBridge();
      this.loadConfig();

      try {
        await this.bootstrapDashboard();
      } catch (error) {
        console.error("Failed to initialize admin dashboard:", error);
      }

      this.refreshIcons();
      this.dispatchDashboardEvent("admin-dashboard:ready", {
        state: this.getState(),
      });

      // Configure action handlers to call the real API
      this.mergeConfig({
        handlers: {
          checkIn: async ({ booking }) => {
            await API.adminCheckIn(booking.id);
            await this.loadAdminBookings();
            this.setTab("queued");
          },
          startGrooming: async ({ booking }) => {
            await API.adminStartGrooming(booking.id);
            await this.loadAdminBookings();
            this.setTab("in-progress");
          },
          markDone: async ({ booking }) => {
            await API.adminMarkDone(booking.id);
            await this.loadAdminBookings();
            this.setTab("for-payment");
          },
          archive: async ({ booking }) => {
            await API.adminArchiveBooking(booking.id);
            await this.loadAdminBookings();
          },
          lateCheckIn: async ({ booking }) => {
            await API.adminLateCheckIn(booking.id);
            await this.loadAdminBookings();
            await this.loadNoShows();
            this.setTab("queued");
          },
          viewDetails: ({ booking }) => {
            this.detailsBooking   = booking;
            this.detailsModalOpen = true;
            this.$nextTick(() => { if (window.lucide) lucide.createIcons(); });
          },
        },
      });

      // Load bookings on init, then poll every 30 seconds
      await this.loadAdminBookings();
      this._bookingInterval = setInterval(() => this.loadAdminBookings(), 30000);

      // Load notifications immediately, then poll every 30 seconds
      await this.loadNotifications();
      this._notifInterval = setInterval(() => this.loadNotifications(), 30000);

      // Load clinic status and no-show list on init, then poll every 60 seconds
      await this.loadClinicStatus();
      await this.loadNoShows();
      this._clinicInterval = setInterval(async () => {
        await this.loadClinicStatus();
        await this.loadNoShows();
      }, 60000);
    },

    // Stops a poll interval after 3 consecutive server errors.
    _stopPollOnFailure(intervalProp, label, error) {
      this._pollFailures[intervalProp] = (this._pollFailures[intervalProp] || 0) + 1;
      console.error(`${label} failed (${this._pollFailures[intervalProp]}/3):`, error);
      if (this._pollFailures[intervalProp] >= 3) {
        clearInterval(this[intervalProp]);
        this[intervalProp] = null;
        console.warn(`[dashboard] Polling stopped for "${label}" after 3 consecutive failures. Reload the page to retry.`);
      }
    },

    _resetPollFailures(intervalProp) {
      this._pollFailures[intervalProp] = 0;
    },

    // Exposes a small runtime API so backend scripts can update the dashboard safely.
    registerBridge() {
      window.adminDashboardUI = {
        setData: (payload) => this.applyDashboardData(payload),
        setConfig: (config) => this.mergeConfig(config),
        getState: () => this.getState(),
        refreshIcons: () => this.refreshIcons(),
        runAction: (actionName, booking) =>
          this.runBookingAction(actionName, booking),
      };
    },

    // Reads optional preload config from global window variables.
    loadConfig() {
      this.mergeConfig(window.ADMIN_DASHBOARD_CONFIG || {});

      if (window.ADMIN_DASHBOARD_BOOTSTRAP) {
        this.config.bootstrap = window.ADMIN_DASHBOARD_BOOTSTRAP;
      }
    },

    // Merges new config values while preserving existing endpoint and handler maps.
    mergeConfig(nextConfig = {}) {
      const endpoints = {
        ...(this.config.endpoints || {}),
        ...(nextConfig.endpoints || {}),
      };
      const handlers = {
        ...(this.config.handlers || {}),
        ...(nextConfig.handlers || {}),
      };

      this.config = {
        ...this.config,
        ...nextConfig,
        endpoints,
        handlers,
      };
    },

    // Loads initial dashboard data from bootstrap payload, custom handler, or endpoint.
    async bootstrapDashboard() {
      if (this.config.bootstrap) {
        this.applyDashboardData(this.config.bootstrap);
        return;
      }

      const loadHandler = this.config.handlers?.loadDashboard;
      if (typeof loadHandler === "function") {
        const payload = await loadHandler({
          dashboard: this,
          state: this.getState(),
        });
        this.applyDashboardData(payload);
        return;
      }

      const endpointConfig = this.resolveEndpointConfig("loadDashboard");
      if (!endpointConfig) {
        return;
      }

      try {
        const payload = await this.requestEndpoint(
          "loadDashboard",
          null,
          endpointConfig,
        );
        this.applyDashboardData(payload);
      } catch (error) {
        console.error("Failed to load admin dashboard data:", error);
      }
    },

    // Switches the active dashboard tab and refreshes icons for the new view.
    setTab(tabName) {
      this.activeTab = tabName;
      this.refreshIcons();
    },

    // Handles the walk-in booking button using a URL, handler, or fallback event.
    async handleWalkInBooking() {
      try {
        if (this.config.walkInBookingUrl) {
          window.location.href = this.interpolateUrl(
            this.config.walkInBookingUrl,
            null,
          );
          return;
        }

        const handler = this.config.handlers?.walkInBooking;
        if (typeof handler === "function") {
          await handler({
            dashboard: this,
            state: this.getState(),
          });
          return;
        }

        this.dispatchDashboardEvent("admin-dashboard:walk-in-requested", {
          state: this.getState(),
        });
        console.info(
          "Walk-in booking action triggered, but no handler is configured.",
        );
      } catch (error) {
        console.error("Walk-in booking handler failed:", error);
      }
    },

    // Starts the Incoming -> Queued transition for a booking.
    async checkInBooking(booking) {
      await this.runBookingAction("checkIn", booking);
    },

    // Starts the Queued -> In-Progress transition for a booking.
    async startGroomingBooking(booking) {
      await this.runBookingAction("startGrooming", booking);
    },

    // Starts the In-Progress -> For Pickup transition for a booking.
    async markBookingDone(booking) {
      await this.runBookingAction("markDone", booking);
    },

    // Starts the For Pickup -> Archived transition for a booking.
    async archiveBooking(booking) {
      await this.runBookingAction("archive", booking);
    },

    // Opens booking details using a handler, endpoint response, detail URL, or event hook.
    async viewBookingDetails(booking) {
      try {
        const detailHandler = this.config.handlers?.viewDetails;
        if (typeof detailHandler === "function") {
          await detailHandler({
            booking: this.cloneBooking(booking),
            dashboard: this,
            state: this.getState(),
          });
          return;
        }

        const endpointConfig = this.resolveEndpointConfig("viewDetails", booking);
        if (endpointConfig) {
          const response = await this.requestEndpoint(
            "viewDetails",
            booking,
            endpointConfig,
          );
          const redirectUrl =
            response?.redirectUrl ||
            response?.detailsUrl ||
            response?.url ||
            "";

          if (redirectUrl) {
            window.location.href = redirectUrl;
            return;
          }
        }

        if (this.config.detailPageUrl) {
          window.location.href = this.interpolateUrl(
            this.config.detailPageUrl,
            booking,
          );
          return;
        }

        this.dispatchDashboardEvent("admin-dashboard:view-details", {
          booking: this.cloneBooking(booking),
          state: this.getState(),
        });
        console.info("View Details clicked, but no detail handler is configured.");
      } catch (error) {
        console.error("View details handler failed:", error);
      }
    },

    // Runs a booking action with busy-state protection and backend/event integration.
    async runBookingAction(actionName, booking) {
      if (!booking || booking.id === undefined || booking.id === null) {
        console.warn(`Cannot run ${actionName}: missing booking id.`);
        return;
      }

      const actionKey = this.getActionKey(actionName, booking.id);
      if (this.pendingActions[actionKey]) {
        return;
      }

      this.pendingActions = {
        ...this.pendingActions,
        [actionKey]: true,
      };

      try {
        this.dispatchDashboardEvent("admin-dashboard:action-start", {
          action: actionName,
          booking: this.cloneBooking(booking),
          state: this.getState(),
        });

        const handler = this.config.handlers?.[actionName];
        let response = null;

        if (typeof handler === "function") {
          response = await handler({
            booking: this.cloneBooking(booking),
            dashboard: this,
            state: this.getState(),
          });
        } else {
          const endpointConfig = this.resolveEndpointConfig(actionName, booking);
          if (endpointConfig) {
            response = await this.requestEndpoint(
              actionName,
              booking,
              endpointConfig,
            );
          } else {
            this.dispatchDashboardEvent("admin-dashboard:action-requested", {
              action: actionName,
              booking: this.cloneBooking(booking),
              state: this.getState(),
            });
          }
        }

        this.applyActionResponse(actionName, booking, response);

        this.dispatchDashboardEvent("admin-dashboard:action-success", {
          action: actionName,
          booking: this.cloneBooking(booking),
          response,
          state: this.getState(),
        });
      } catch (error) {
        console.error(`Admin dashboard ${actionName} failed:`, error);
        this.dispatchDashboardEvent("admin-dashboard:action-error", {
          action: actionName,
          booking: this.cloneBooking(booking),
          error: error.message,
          state: this.getState(),
        });
      } finally {
        const nextPendingActions = { ...this.pendingActions };
        delete nextPendingActions[actionKey];
        this.pendingActions = nextPendingActions;
      }
    },

    // Applies whatever shape the backend returns after a booking action completes.
    applyActionResponse(actionName, booking, response) {
      if (!response) {
        if (this.config.enableOptimisticUpdates) {
          this.applyOptimisticTransition(actionName, booking);
        }
        this.refreshIcons();
        return;
      }

      const payload =
        response.dashboardData ||
        response.dashboard ||
        response.state ||
        response.lists ||
        (this.isDashboardPayload(response) ? response : null);

      if (payload) {
        this.applyDashboardData(payload);
        return;
      }

      const updatedBooking = response.booking || response.record || response.data;
      if (updatedBooking && typeof updatedBooking === "object") {
        this.applyBookingUpdate(actionName, booking, updatedBooking);
        return;
      }

      if (response.redirectUrl || response.url) {
        window.location.href = response.redirectUrl || response.url;
        return;
      }

      if (this.config.enableOptimisticUpdates) {
        this.applyOptimisticTransition(actionName, booking);
      }

      this.refreshIcons();
    },

    // Replaces a single booking across lists after the backend returns an updated record.
    applyBookingUpdate(actionName, previousBooking, updatedBooking) {
      const normalizedBooking = this.normalizeBooking(
        {
          ...previousBooking,
          ...updatedBooking,
        },
        this.getNextStatusForAction(actionName, previousBooking?.status),
      );

      this.removeBookingFromLists(previousBooking?.id ?? normalizedBooking.id);

      const listKey = this.getListKeyForStatus(normalizedBooking.status);
      if (listKey) {
        this[listKey] = this.insertBookingSorted(this[listKey], normalizedBooking);
      }

      this.refreshIcons();
    },

    // Moves a booking locally when optimistic updates are enabled and no payload is returned.
    applyOptimisticTransition(actionName, booking) {
      const nextStatus = this.getNextStatusForAction(actionName, booking.status);
      if (!nextStatus || nextStatus === "archived") {
        this.removeBookingFromLists(booking.id);
        this.refreshIcons();
        return;
      }

      const updatedBooking = {
        ...booking,
        status: nextStatus,
      };

      if (nextStatus === "queued" && !updatedBooking.dropOffTime) {
        updatedBooking.dropOffTime = this.formatCurrentTime();
      }

      if (nextStatus === "in-progress" && !updatedBooking.startedAt) {
        updatedBooking.startedAt = this.formatCurrentTime();
      }

      if (nextStatus === "for-pickup" && !updatedBooking.completedAt) {
        updatedBooking.completedAt = this.formatCurrentTime();
      }

      this.applyBookingUpdate(actionName, booking, updatedBooking);
    },

    // Normalizes and applies dashboard metrics plus all booking lists into Alpine state.
    applyDashboardData(payload = {}) {
      const nextPayload = this.normalizeDashboardPayload(payload);

      if ("todayCount" in nextPayload) {
        this.todayCount = nextPayload.todayCount;
      }

      if ("weekCount" in nextPayload) {
        this.weekCount = nextPayload.weekCount;
      }

      if ("currentCapacity" in nextPayload) {
        this.currentCapacity = nextPayload.currentCapacity;
      }

      if ("maxCapacity" in nextPayload) {
        this.maxCapacity = nextPayload.maxCapacity;
      }

      if ("notificationCount" in nextPayload) {
        this.notificationCount = nextPayload.notificationCount;
      }

      if ("incomingList" in nextPayload) {
        this.incomingList = nextPayload.incomingList;
      }

      if ("queuedList" in nextPayload) {
        this.queuedList = nextPayload.queuedList;
      }

      if ("inProgressList" in nextPayload) {
        this.inProgressList = nextPayload.inProgressList;
      }

      if ("forPickupList" in nextPayload) {
        this.forPickupList = nextPayload.forPickupList;
      }

      if ("forPaymentList" in nextPayload) {
        this.forPaymentList = nextPayload.forPaymentList;
      }

      this.refreshIcons();
      this.dispatchDashboardEvent("admin-dashboard:data-applied", {
        state: this.getState(),
      });
    },

    // Accepts several backend payload shapes and converts them into one dashboard format.
    normalizeDashboardPayload(payload) {
      const bookingsByStatus = Array.isArray(payload.bookings)
        ? this.groupBookingsByStatus(payload.bookings)
        : {};

      const summary = payload.summary || payload.metrics || {};
      const capacity = payload.capacity || {};
      const notifications = payload.notifications || {};

      const nextPayload = {};

      if (this.hasValue(payload.todayCount) || this.hasValue(summary.today)) {
        nextPayload.todayCount = this.toNumber(
          payload.todayCount ?? summary.today,
          this.todayCount,
        );
      }

      if (this.hasValue(payload.weekCount) || this.hasValue(summary.week)) {
        nextPayload.weekCount = this.toNumber(
          payload.weekCount ?? summary.week,
          this.weekCount,
        );
      }

      if (
        this.hasValue(payload.currentCapacity) ||
        this.hasValue(capacity.current)
      ) {
        nextPayload.currentCapacity = this.toNumber(
          payload.currentCapacity ?? capacity.current,
          this.currentCapacity,
        );
      }

      if (this.hasValue(payload.maxCapacity) || this.hasValue(capacity.max)) {
        nextPayload.maxCapacity = this.toNumber(
          payload.maxCapacity ?? capacity.max,
          this.maxCapacity,
        );
      }

      if (
        this.hasValue(payload.notificationCount) ||
        this.hasValue(notifications.count)
      ) {
        nextPayload.notificationCount = this.toNumber(
          payload.notificationCount ?? notifications.count,
          this.notificationCount,
        );
      }

      if (
        "incomingList" in payload ||
        "incoming" in payload ||
        "incoming" in bookingsByStatus
      ) {
        nextPayload.incomingList = this.normalizeList(
          payload.incomingList ?? payload.incoming ?? bookingsByStatus.incoming,
          "incoming",
        );
      }

      if (
        "queuedList" in payload ||
        "queued" in payload ||
        "queued" in bookingsByStatus
      ) {
        nextPayload.queuedList = this.normalizeList(
          payload.queuedList ?? payload.queued ?? bookingsByStatus.queued,
          "queued",
        );
      }

      if (
        "inProgressList" in payload ||
        "inProgress" in payload ||
        "in_progress" in bookingsByStatus ||
        "in-progress" in bookingsByStatus
      ) {
        nextPayload.inProgressList = this.normalizeList(
          payload.inProgressList ??
            payload.inProgress ??
            bookingsByStatus["in_progress"] ??
            bookingsByStatus["in-progress"],
          "in-progress",
        );
      }

      if (
        "forPickupList" in payload ||
        "forPickup" in payload ||
        "for_pickup" in bookingsByStatus ||
        "for-pickup" in bookingsByStatus
      ) {
        nextPayload.forPickupList = this.normalizeList(
          payload.forPickupList ??
            payload.forPickup ??
            bookingsByStatus["for_pickup"] ??
            bookingsByStatus["for-pickup"],
          "for-pickup",
        );
      }

      if (
        "forPaymentList" in payload ||
        "forPayment" in payload ||
        "for_payment" in bookingsByStatus ||
        "for-payment" in bookingsByStatus
      ) {
        nextPayload.forPaymentList = this.normalizeList(
          payload.forPaymentList ??
            payload.forPayment ??
            bookingsByStatus["for_payment"] ??
            bookingsByStatus["for-payment"],
          "for-payment",
        );
      }

      return nextPayload;
    },

    // Normalizes a booking list and keeps the rendered order stable.
    normalizeList(list, fallbackStatus) {
      if (!Array.isArray(list)) {
        return [];
      }

      return list
        .map((booking) => this.normalizeBooking(booking, fallbackStatus))
        .sort((left, right) => this.compareBookings(left, right));
    },

    // Normalizes one booking record so the template can safely read consistent fields.
    normalizeBooking(booking, fallbackStatus) {
      const normalizedStatus = this.normalizeStatus(
        booking?.status || fallbackStatus,
      );
      const bookingId =
        booking?.id ??
        booking?.bookingId ??
        booking?.appointmentId ??
        booking?.queueNumber ??
        this.createFallbackId();

      return {
        ...booking,
        id: bookingId,
        queueNumber: this.toNumber(
          booking?.queueNumber ?? booking?.queue ?? 0,
          0,
        ),
        ownerName: this.toStringValue(
          booking?.ownerName ?? booking?.clientName ?? booking?.owner,
        ),
        contactNumber: this.toStringValue(
          booking?.contactNumber ?? booking?.phone ?? booking?.contact,
        ),
        petName: this.toStringValue(booking?.petName),
        petType: this.toStringValue(booking?.petType),
        breed: this.toStringValue(booking?.breed),
        serviceLabel: this.toStringValue(
          booking?.serviceLabel ?? booking?.service ?? booking?.packageLabel,
        ),
        appointmentDate: this.toStringValue(
          booking?.appointmentDate ?? booking?.date,
        ),
        appointmentTime: this.toStringValue(
          booking?.appointmentTime ?? booking?.time,
        ),
        dropOffTime: this.toStringValue(
          booking?.dropOffTime ?? booking?.dropoffTime,
        ),
        startedAt: this.toStringValue(booking?.startedAt),
        completedAt: this.toStringValue(booking?.completedAt),
        clientNotified: Boolean(booking?.clientNotified),
        paid: Boolean(booking?.paid),
        status: normalizedStatus,
      };
    },

    // Groups a flat bookings array by normalized status values.
    groupBookingsByStatus(bookings) {
      return bookings.reduce((groups, booking) => {
        const status = this.normalizeStatus(booking?.status);
        if (!status) {
          return groups;
        }

        if (!groups[status]) {
          groups[status] = [];
        }

        groups[status].push(booking);
        return groups;
      }, {});
    },

    // Sorts bookings primarily by queue number and then by owner name.
    compareBookings(left, right) {
      const leftQueue = Number(left?.queueNumber ?? 0);
      const rightQueue = Number(right?.queueNumber ?? 0);

      if (leftQueue !== rightQueue) {
        return leftQueue - rightQueue;
      }

      return String(left?.ownerName || "").localeCompare(
        String(right?.ownerName || ""),
      );
    },

    // Inserts a booking into a list and re-sorts the result.
    insertBookingSorted(list, booking) {
      return [...list, booking].sort((left, right) =>
        this.compareBookings(left, right),
      );
    },

    // Removes a booking from every visible status list using its id.
    removeBookingFromLists(bookingId) {
      this.incomingList    = this.incomingList.filter((item) => item.id !== bookingId);
      this.queuedList      = this.queuedList.filter((item) => item.id !== bookingId);
      this.inProgressList  = this.inProgressList.filter((item) => item.id !== bookingId);
      this.forPickupList   = this.forPickupList.filter((item) => item.id !== bookingId);
      this.forPaymentList  = this.forPaymentList.filter((item) => item.id !== bookingId);
      this.noShowList      = this.noShowList.filter((item) => item.id !== bookingId);
    },

    // Maps a frontend action name to the next booking status.
    getNextStatusForAction(actionName, currentStatus) {
      const normalizedCurrentStatus = this.normalizeStatus(currentStatus);
      const actionStatusMap = {
        checkIn: "queued",
        startGrooming: "in-progress",
        markDone: "for-payment",
        archive: "archived",
      };

      return actionStatusMap[actionName] || normalizedCurrentStatus;
    },

    // Resolves a booking status into the matching Alpine list property name.
    getListKeyForStatus(status) {
      const normalizedStatus = this.normalizeStatus(status);
      const listMap = {
        incoming: "incomingList",
        queued: "queuedList",
        "in-progress": "inProgressList",
        "for-pickup": "forPickupList",
        "for-payment": "forPaymentList",
      };

      return listMap[normalizedStatus] || "";
    },

    // Builds a unique key for tracking the loading state of one booking action.
    getActionKey(actionName, bookingId) {
      return `${actionName}:${bookingId}`;
    },

    // Returns whether a specific booking action is currently running.
    isActionBusy(actionName, bookingId) {
      return Boolean(this.pendingActions[this.getActionKey(actionName, bookingId)]);
    },

    // Provides the temporary button label shown while an action is in progress.
    getActionLabel(actionName) {
      const labelMap = {
        checkIn: "Checking in...",
        startGrooming: "Grooming...",
        markDone: "Finishing...",
        archive: "Archiving...",
      };

      return labelMap[actionName] || "Working...";
    },

    // Normalizes endpoint config so actions can accept either strings or config objects.
    resolveEndpointConfig(actionName, booking = null) {
      const endpointConfig = this.config.endpoints?.[actionName];
      if (!endpointConfig) {
        return null;
      }

      if (typeof endpointConfig === "string") {
        return {
          url: endpointConfig,
          method: actionName === "loadDashboard" || actionName === "viewDetails"
            ? "GET"
            : "POST",
        };
      }

      return {
        ...endpointConfig,
      };
    },

    // Sends the configured fetch request for a dashboard action or initial load.
    async requestEndpoint(actionName, booking, endpointConfig) {
      if (!endpointConfig?.url) {
        return null;
      }

      const method = (endpointConfig.method || "GET").toUpperCase();
      const headers = {
        "Content-Type": "application/json",
        ...(endpointConfig.headers || {}),
      };
      const url = this.interpolateUrl(endpointConfig.url, booking);
      const requestInit = {
        method,
        headers,
      };

      if (method !== "GET" && method !== "HEAD") {
        const payload =
          typeof endpointConfig.body === "function"
            ? endpointConfig.body({
                booking: this.cloneBooking(booking),
                action: actionName,
                state: this.getState(),
              })
            : endpointConfig.body;

        requestInit.body = JSON.stringify(
          payload ?? {
            id: booking?.id ?? null,
            booking: this.cloneBooking(booking),
            action: actionName,
          },
        );
      }

      const response = await fetch(url, requestInit);
      const responseData = await this.parseResponse(response);

      if (!response.ok) {
        const errorMessage =
          responseData?.message ||
          response.statusText ||
          `Request failed with status ${response.status}`;
        throw new Error(errorMessage);
      }

      return responseData;
    },

    // Parses JSON responses when available and safely falls back to plain text.
    async parseResponse(response) {
      const contentType = response.headers.get("content-type") || "";

      if (contentType.includes("application/json")) {
        return response.json().catch(() => ({}));
      }

      const text = await response.text().catch(() => "");
      if (!text) {
        return null;
      }

      return {
        message: text,
      };
    },

    // Replaces route placeholders like :id with the current booking id.
    interpolateUrl(url, booking) {
      if (!url) {
        return "";
      }

      return url.replace(/:id\b/g, booking?.id ?? "");
    },

    // Creates a shallow copy before passing booking data to external handlers.
    cloneBooking(booking) {
      if (!booking) {
        return null;
      }

      return {
        ...booking,
      };
    },

    // Returns a snapshot of the dashboard state for handlers and custom events.
    getState() {
      return {
        activeTab: this.activeTab,
        todayCount: this.todayCount,
        weekCount: this.weekCount,
        currentCapacity: this.currentCapacity,
        maxCapacity: this.maxCapacity,
        notificationCount: this.notificationCount,
        incomingList: [...this.incomingList],
        queuedList: [...this.queuedList],
        inProgressList: [...this.inProgressList],
        forPickupList: [...this.forPickupList],
        forPaymentList: [...this.forPaymentList],
      };
    },

    // Detects whether a returned object looks like a dashboard data payload.
    isDashboardPayload(value) {
      if (!value || typeof value !== "object" || Array.isArray(value)) {
        return false;
      }

      return (
        "incomingList" in value ||
        "queuedList" in value ||
        "inProgressList" in value ||
        "forPickupList" in value ||
        "forPaymentList" in value ||
        "incoming" in value ||
        "queued" in value ||
        "inProgress" in value ||
        "forPickup" in value ||
        "forPayment" in value ||
        "bookings" in value ||
        "summary" in value ||
        "metrics" in value ||
        "capacity" in value ||
        "notifications" in value
      );
    },

    // Checks whether a value is present without treating zero as empty.
    hasValue(value) {
      return value !== undefined && value !== null;
    },

    // Converts a value to a number and falls back safely when parsing fails.
    toNumber(value, fallbackValue = 0) {
      const parsedValue = Number(value);
      return Number.isFinite(parsedValue) ? parsedValue : fallbackValue;
    },

    // Converts nullable values into template-safe strings.
    toStringValue(value) {
      return value === undefined || value === null ? "" : String(value);
    },

    // Converts backend status variants into the frontend status names used by the UI.
    normalizeStatus(status) {
      if (!status) {
        return "";
      }

      const normalizedStatus = String(status).trim().toLowerCase();
      const statusMap = {
        in_progress: "in-progress",
        inprogress: "in-progress",
        for_pickup: "for-pickup",
        forpickup: "for-pickup",
        for_payment: "for-payment",
        forpayment: "for-payment",
      };

      return statusMap[normalizedStatus] || normalizedStatus;
    },

    // Creates a simple time string for optimistic status transitions.
    formatCurrentTime() {
      return new Date().toLocaleTimeString([], {
        hour: "numeric",
        minute: "2-digit",
      });
    },

    // Generates a temporary id when the backend record does not include one yet.
    createFallbackId() {
      if (window.crypto && typeof window.crypto.randomUUID === "function") {
        return window.crypto.randomUUID();
      }

      return `booking-${Date.now()}-${Math.random().toString(16).slice(2)}`;
    },

    // Broadcasts dashboard lifecycle and action events for external listeners.
    dispatchDashboardEvent(name, detail = {}) {
      window.dispatchEvent(
        new CustomEvent(name, {
          detail,
        }),
      );
    },

    // Re-renders Lucide icons after Alpine updates the DOM.
    refreshIcons() {
      this.$nextTick(() => {
        if (window.lucide) {
          window.lucide.createIcons();
        }
      });
    },

    // ── Incoming list grouped by date (today first) ───────────────────────

    // Returns YYYY-MM-DD in local time (avoids UTC off-by-one at midnight PH)
    localToday() {
      const d = new Date();
      return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;
    },

    groupedIncoming() {
      const groups = {};
      for (const booking of this.incomingList) {
        const date = booking.appointmentDate || 'unknown';
        if (!groups[date]) groups[date] = [];
        groups[date].push(booking);
      }

      const today = this.localToday();

      return Object.keys(groups)
        .sort((a, b) => a.localeCompare(b))
        .map(date => ({
          date,
          label: this.formatDateGroupLabel(date),
          isToday: date === today,
          bookings: groups[date],
        }));
    },

    formatDateGroupLabel(dateStr) {
      const today    = this.localToday();
      const tomorrowDate = new Date();
      tomorrowDate.setDate(tomorrowDate.getDate() + 1);
      const tomorrow = `${tomorrowDate.getFullYear()}-${String(tomorrowDate.getMonth() + 1).padStart(2, "0")}-${String(tomorrowDate.getDate()).padStart(2, "0")}`;

      const formatted = new Date(dateStr + 'T00:00:00').toLocaleDateString('en-PH', {
        weekday: 'long',
        month:   'long',
        day:     'numeric',
        year:    'numeric',
      });

      if (dateStr === today)    return 'Today — ' + formatted;
      if (dateStr === tomorrow) return 'Tomorrow — ' + formatted;
      return formatted;
    },

    // ── Payment ───────────────────────────────────────────────────────────────

    get paymentChange() {
      const final = parseFloat(this.paymentModal.finalPrice) || 0;
      const paid  = parseFloat(this.paymentModal.amountPaid)  || 0;
      return paid - final;
    },

    openPaymentModal(booking, isEarlyPayment = false) {
      const servicesTotal = (booking.services || []).reduce(
        (sum, s) => sum + parseFloat(s.priceAtBooking || 0), 0
      );
      this.paymentModal = {
        open: true,
        booking,
        isEarlyPayment,
        finalPrice: servicesTotal > 0 ? servicesTotal.toFixed(2) : "",
        amountPaid: "",
        paymentMethod: "cash",
        notes: "",
        busy: false,
        error: "",
      };
      this.$nextTick(() => { if (window.lucide) lucide.createIcons(); });
    },

    closePaymentModal() {
      this.paymentModal = {
        open: false, booking: null, isEarlyPayment: false,
        finalPrice: "", amountPaid: "", paymentMethod: "cash", notes: "", busy: false, error: "",
      };
    },

    async submitPayment() {
      const { booking, isEarlyPayment, finalPrice, amountPaid, notes } = this.paymentModal;
      const fp = parseFloat(finalPrice);
      const ap = parseFloat(amountPaid);

      if (!fp || fp <= 0) {
        this.paymentModal.error = "Please enter a valid final price.";
        return;
      }
      if (!ap || ap < fp) {
        this.paymentModal.error = "Amount paid cannot be less than the final price.";
        return;
      }

      this.paymentModal.busy  = true;
      this.paymentModal.error = "";

      try {
        const payload = {
          final_price:    fp,
          amount_paid:    ap,
          payment_method: this.paymentModal.paymentMethod || "cash",
          notes:          notes || null,
        };
        const res = isEarlyPayment
          ? await API.payNow(booking.id, payload)
          : await API.processPayment(booking.id, payload);

        this.closePaymentModal();
        this.receiptModal = {
          open: true,
          ownerName:     booking.ownerName,
          petName:       booking.petName,
          serviceLabel:  booking.serviceLabel,
          finalPrice:    fp,
          amountPaid:    ap,
          change:        res.change ?? (ap - fp),
          paymentMethod: this.paymentModal.paymentMethod || "cash",
          paidAt:        res.paid_at ?? new Date().toLocaleString("en-PH"),
          isEarlyPayment,
        };
        this.$nextTick(() => { if (window.lucide) lucide.createIcons(); });
        await this.loadAdminBookings();
        await this.loadNotifications();
      } catch (err) {
        this.paymentModal.error = err.message || "Payment failed. Please try again.";
      } finally {
        this.paymentModal.busy = false;
      }
    },

    closeReceiptModal() {
      this.receiptModal = {
        open: false, ownerName: "", petName: "", serviceLabel: "",
        finalPrice: 0, amountPaid: 0, change: 0, paymentMethod: "", paidAt: "", isEarlyPayment: false,
      };
    },

    printReceipt() {
      window.print();
    },

    async releaseBooking(booking) {
      try {
        await API.releaseBooking(booking.id);
        await this.loadAdminBookings();
      } catch (err) {
        alert(err.message || "Release failed. Please try again.");
      }
    },

    // ── Admin Bookings ────────────────────────────────────────────────────────

    async loadAdminBookings() {
      try {
        const data = await API.getAdminBookings(this.selectedDate);
        this._resetPollFailures("_bookingInterval");
        this.applyDashboardData(data);
      } catch (error) {
        this._stopPollOnFailure("_bookingInterval", "loadAdminBookings", error);
      }
    },

    // Called when the date picker changes — reloads bookings for the new date
    onDateChange() {
      this.loadAdminBookings();
    },

    closeDetailsModal() {
      this.detailsModalOpen = false;
      this.detailsBooking   = null;
    },

    // ── Notifications ─────────────────────────────────────────────────────────

    async loadNotifications() {
      try {
        const data = await API.getNotifications();
        this._resetPollFailures("_notifInterval");
        this.notifications    = data.notifications || [];
        this.notificationCount = data.unread_count  || 0;
      } catch (error) {
        this._stopPollOnFailure("_notifInterval", "loadNotifications", error);
      }
    },

    toggleNotifications() {
      this.notificationsOpen = !this.notificationsOpen;
    },

    closeNotifications() {
      this.notificationsOpen = false;
    },

    async handleMarkAllRead() {
      try {
        await API.markAllNotificationsRead();
        this.notifications     = this.notifications.map((n) => ({ ...n, is_read: true }));
        this.notificationCount = 0;
      } catch (error) {
        console.error("Failed to mark notifications as read:", error);
      }
    },

    async handleMarkOneRead(notif) {
      if (notif.is_read) return;
      try {
        await API.markNotificationRead(notif.notification_id);
        this.notifications = this.notifications.map((n) =>
          n.notification_id === notif.notification_id ? { ...n, is_read: true } : n
        );
        this.notificationCount = Math.max(0, this.notificationCount - 1);
      } catch (error) {
        console.error("Failed to mark notification as read:", error);
      }
    },

    // Returns the list to show based on the active tab, grouped into Today / Earlier
    groupedNotifications() {
      const source = this.notifTab === "unread"
        ? this.notifications.filter((n) => !n.is_read)
        : this.notifications;

      const todayStr = this.localToday();
      const today    = source.filter((n) => (n.created_at || "").slice(0, 10) === todayStr);
      const earlier  = source.filter((n) => (n.created_at || "").slice(0, 10) !== todayStr);

      return { today, earlier };
    },

    formatNotificationTime(createdAt) {
      if (!createdAt) return "";
      const date = new Date(createdAt);
      return date.toLocaleString("en-PH", {
        month:  "short",
        day:    "numeric",
        hour:   "numeric",
        minute: "2-digit",
      });
    },

    // ── No-Show & Clinic Status ───────────────────────────────────────────────

    async loadNoShows() {
      try {
        const data = await API.getNoShows();
        this._resetPollFailures("_clinicInterval");
        this.noShowList = (data.noShowList || []).map((b) => this.normalizeBooking(b, "no_show"));
      } catch (error) {
        this._stopPollOnFailure("_clinicInterval", "loadNoShows", error);
      }
    },

    async lateCheckInBooking(booking) {
      await this.runBookingAction("lateCheckIn", booking);
    },

    // Returns true when late check-in is still allowed (before 5 PM and clinic not stopped).
    isLateCheckInAvailable() {
      return new Date().getHours() < 17 && !this.clinicStopped;
    },

    async loadClinicStatus() {
      try {
        const data = await API.getClinicStatus();
        this._resetPollFailures("_clinicInterval");
        this.clinicStopped = Boolean(data.stopped_today);
      } catch (error) {
        this._stopPollOnFailure("_clinicInterval", "loadClinicStatus", error);
      }
    },

    // Opens the confirmation modal before stopping / reopening.
    handleStopReceivingClick() {
      if (this.clinicStopped) {
        this.stopModal = {
          open:    true,
          title:   "Reopen for Today?",
          message: "This will allow new walk-ins and late check-ins for the rest of the day.",
          action:  "reopen",
        };
      } else {
        this.stopModal = {
          open:    true,
          title:   "Stop Receiving for Today?",
          message: "Remaining walk-in bookings will be marked as no-show. This action can be undone before the day ends.",
          action:  "stop",
        };
      }
    },

    // Called by the Confirm button inside the stop-receiving modal.
    async executeStopReceiving() {
      const action = this.stopModal.action;
      this.stopModal = { open: false, title: "", message: "", action: "" };
      try {
        if (action === "stop") {
          await API.adminStopToday();
          this.clinicStopped = true;
          await this.loadAdminBookings();
          await this.loadNoShows();
          await this.loadNotifications();
        } else {
          await API.adminReopenToday();
          this.clinicStopped = false;
        }
      } catch (error) {
        console.error("Failed to toggle clinic status:", error);
      }
    },
  };
}
