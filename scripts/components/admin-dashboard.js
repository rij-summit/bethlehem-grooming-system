const PAYMENT_SIZE_OPTIONS = [
  { value: "small", label: "Small" },
  { value: "medium", label: "Medium" },
  { value: "large", label: "Large" },
  { value: "extra_large", label: "Extra Large" },
];

/*
 * Payment modal service rules mirror scripts/services/grooming-service.js.
 * This dashboard is loaded as a classic script, so keep these values in sync
 * until the admin page can read the same catalog from an API/shared bundle.
 */
function createPaymentFixedPrice({ label, sizeKey = "", amount }) {
  return {
    label,
    sizeKey,
    pricingType: "fixed",
    minAmount: amount,
    maxAmount: amount,
  };
}

function createPaymentPlusPrice({ label, sizeKey = "", amount }) {
  return {
    label,
    sizeKey,
    pricingType: "plus",
    minAmount: amount,
    maxAmount: null,
  };
}

function createPaymentRangePrice({ label, sizeKey = "", minAmount, maxAmount }) {
  return {
    label,
    sizeKey,
    pricingType: "range",
    minAmount,
    maxAmount,
  };
}

const PAYMENT_GROOMING_SERVICES = [
  {
    id: "partial_grooming",
    kind: "package",
    petType: "dog",
    name: "Partial Grooming",
    descriptionItems: [
      "Trimming, nail clipping and ear cleaning",
      "Cologne spritz and dry shampoo",
    ],
    priceOptions: [
      createPaymentFixedPrice({ label: "Small", sizeKey: "small", amount: 400 }),
      createPaymentFixedPrice({ label: "Medium", sizeKey: "medium", amount: 500 }),
      createPaymentPlusPrice({ label: "Large", sizeKey: "large", amount: 600 }),
      createPaymentPlusPrice({ label: "Extra Large", sizeKey: "extra_large", amount: 700 }),
    ],
  },
  {
    id: "regular_dog_grooming",
    kind: "package",
    petType: "dog",
    name: "Regular Dog Grooming",
    descriptionItems: [
      "Bathing with shampoo and blow drying",
      "Haircut, trims, nail clipping, ear cleaning and tooth-brushing",
    ],
    priceOptions: [
      createPaymentFixedPrice({ label: "Small", sizeKey: "small", amount: 550 }),
      createPaymentFixedPrice({ label: "Medium", sizeKey: "medium", amount: 650 }),
      createPaymentPlusPrice({ label: "Large", sizeKey: "large", amount: 850 }),
      createPaymentPlusPrice({ label: "Extra Large", sizeKey: "extra_large", amount: 1050 }),
    ],
  },
  {
    id: "deluxe_dog_grooming",
    kind: "package",
    petType: "dog",
    name: "Deluxe Dog Grooming",
    descriptionItems: [
      "Bathing with shampoo and blow drying",
      "Special haircut, nail clipping, ear cleaning and tooth-brushing",
    ],
    priceOptions: [
      createPaymentFixedPrice({ label: "Small", sizeKey: "small", amount: 650 }),
      createPaymentFixedPrice({ label: "Medium", sizeKey: "medium", amount: 750 }),
      createPaymentPlusPrice({ label: "Large", sizeKey: "large", amount: 1000 }),
      createPaymentPlusPrice({ label: "Extra Large", sizeKey: "extra_large", amount: 1200 }),
    ],
  },
  {
    id: "bath_and_go",
    kind: "package",
    petType: "dog",
    name: "Bath and Go!",
    descriptionItems: [
      "Bathing with shampoo and blow drying",
      "Nail clipping, ear cleaning and tooth-brushing",
    ],
    priceOptions: [
      createPaymentFixedPrice({ label: "Small", sizeKey: "small", amount: 450 }),
      createPaymentFixedPrice({ label: "Medium", sizeKey: "medium", amount: 550 }),
      createPaymentPlusPrice({ label: "Large", sizeKey: "large", amount: 650 }),
      createPaymentPlusPrice({ label: "Extra Large", sizeKey: "extra_large", amount: 750 }),
    ],
  },
  {
    id: "cat_full_grooming",
    kind: "package",
    petType: "cat",
    name: "Full Grooming",
    descriptionItems: [
      "Bathing with shampoo and blow drying",
      "Haircut if requested, nail clipping and ear cleaning",
    ],
    priceOptions: [
      createPaymentFixedPrice({ label: "Small", sizeKey: "small", amount: 500 }),
      createPaymentFixedPrice({ label: "Medium", sizeKey: "medium", amount: 600 }),
    ],
  },
  {
    id: "nail_clipping",
    kind: "ala_carte",
    petTypes: ["cat", "dog"],
    name: "Nail Clipping",
    descriptionItems: [],
    priceOptions: [
      createPaymentRangePrice({ label: "Standard", minAmount: 50, maxAmount: 100 }),
    ],
  },
  {
    id: "ear_cleaning",
    kind: "ala_carte",
    petTypes: ["cat", "dog"],
    name: "Ear Cleaning",
    descriptionItems: [],
    priceOptions: [
      createPaymentPlusPrice({ label: "Standard", amount: 150 }),
    ],
  },
  {
    id: "facial_trimming",
    kind: "ala_carte",
    petTypes: ["cat", "dog"],
    name: "Facial Trimming",
    descriptionItems: [],
    priceOptions: [
      createPaymentFixedPrice({ label: "Standard", amount: 150 }),
    ],
  },
  {
    id: "anal_sac_draining",
    kind: "ala_carte",
    petTypes: ["cat", "dog"],
    name: "Anal Sac Draining",
    descriptionItems: [],
    priceOptions: [
      createPaymentFixedPrice({ label: "Standard", amount: 150 }),
    ],
  },
  {
    id: "tooth_brushing",
    kind: "ala_carte",
    petTypes: ["cat", "dog"],
    name: "Tooth Brushing",
    descriptionItems: [],
    priceOptions: [
      createPaymentPlusPrice({ label: "Standard", amount: 100 }),
    ],
  },
];

const PAYMENT_SERVICE_BY_ID = new Map(
  PAYMENT_GROOMING_SERVICES.map((service) => [service.id, service]),
);
const PAYMENT_SERVICE_BY_NAME = new Map(
  PAYMENT_GROOMING_SERVICES.map((service) => [
    normalizePaymentText(service.name),
    service,
  ]),
);

function normalizePaymentText(value) {
  return String(value || "")
    .trim()
    .toLowerCase()
    .replace(/&/g, "and")
    .replace(/[^a-z0-9]+/g, " ")
    .trim();
}

function normalizePaymentSize(value) {
  const normalized = String(value || "").trim().toLowerCase();
  const sizeMap = {
    small: "small",
    medium: "medium",
    large: "large",
    "extra large": "extra_large",
    "extra-large": "extra_large",
    extra_large: "extra_large",
    extralarge: "extra_large",
    xl: "extra_large",
  };

  return sizeMap[normalized] || "";
}

function normalizePaymentPetType(value) {
  const normalized = normalizePaymentText(value);

  if (normalized.includes("cat")) {
    return "cat";
  }

  if (normalized.includes("dog")) {
    return "dog";
  }

  return normalized;
}

function getPaymentBaseSizeOptions(petType) {
  return normalizePaymentPetType(petType) === "cat"
    ? PAYMENT_SIZE_OPTIONS.filter((option) => ["small", "medium"].includes(option.value))
    : PAYMENT_SIZE_OPTIONS;
}

function normalizePaymentSizeForPet(size, petType) {
  const options = getPaymentBaseSizeOptions(petType);
  const sizeKey = normalizePaymentSize(size);

  return options.some((option) => option.value === sizeKey)
    ? sizeKey
    : options[0]?.value || "";
}

function formatPaymentSizeLabel(value) {
  const normalized = normalizePaymentSize(value);
  const labels = {
    small: "Small",
    medium: "Medium",
    large: "Large",
    extra_large: "Extra Large",
  };

  return labels[normalized] || "Size not specified";
}

function getPaymentServiceDefinition(rawService) {
  const serviceId = String(
    rawService?.slug ??
      rawService?.serviceSlug ??
      rawService?.service_slug ??
      rawService?.serviceId ??
      rawService?.id ??
      "",
  );

  if (PAYMENT_SERVICE_BY_ID.has(serviceId)) {
    return PAYMENT_SERVICE_BY_ID.get(serviceId);
  }

  const nameKey = normalizePaymentText(
    rawService?.name ?? rawService?.serviceName ?? rawService?.service_name,
  );

  return PAYMENT_SERVICE_BY_NAME.get(nameKey) || null;
}

function formatPaymentAmount(amount) {
  return `\u20b1${Number(amount || 0).toLocaleString("en-PH")}`;
}

function formatPaymentPriceOption(priceOption, includeCurrency = true) {
  if (!priceOption) {
    return "";
  }

  const minAmount = includeCurrency
    ? formatPaymentAmount(priceOption.minAmount)
    : Number(priceOption.minAmount || 0).toLocaleString("en-PH");

  if (priceOption.pricingType === "plus") {
    return `${minAmount}+`;
  }

  if (priceOption.pricingType === "range") {
    const maxAmount = includeCurrency
      ? formatPaymentAmount(priceOption.maxAmount)
      : Number(priceOption.maxAmount || 0).toLocaleString("en-PH");
    return `${minAmount}-${maxAmount}`;
  }

  return minAmount;
}

function summarizePaymentPriceOptions(priceOptions) {
  let minimumAmount = Number.POSITIVE_INFINITY;
  let maximumAmount = 0;
  let hasMaximumAmount = false;
  let hasOpenEndedMaximum = false;

  priceOptions.forEach((priceOption) => {
    if (!Number.isFinite(priceOption?.minAmount)) {
      return;
    }

    minimumAmount = Math.min(minimumAmount, priceOption.minAmount);

    if (priceOption.maxAmount === null) {
      hasOpenEndedMaximum = true;
      return;
    }

    maximumAmount = Math.max(maximumAmount, priceOption.maxAmount);
    hasMaximumAmount = true;
  });

  return {
    minAmount: minimumAmount === Number.POSITIVE_INFINITY ? 0 : minimumAmount,
    maxAmount: hasOpenEndedMaximum ? null : hasMaximumAmount ? maximumAmount : 0,
    pricingType: hasOpenEndedMaximum ? "plus" : "range",
  };
}

function getPaymentServicePricing(serviceDefinition, petSize) {
  if (!serviceDefinition) {
    return {
      minAmount: 0.01,
      displayPrice: "Enter price",
      placeholder: "0.00",
      selectedPriceOption: null,
    };
  }

  const options = Array.isArray(serviceDefinition.priceOptions)
    ? serviceDefinition.priceOptions
    : [];

  if (serviceDefinition.kind === "package") {
    const sizeKey = normalizePaymentSize(petSize);
    const selectedPriceOption = options.find((option) => option.sizeKey === sizeKey);

    if (selectedPriceOption) {
      return {
        minAmount: selectedPriceOption.minAmount || 0.01,
        displayPrice: formatPaymentPriceOption(selectedPriceOption),
        placeholder: formatPaymentPriceOption(selectedPriceOption, false),
        selectedPriceOption,
      };
    }

    const summary = summarizePaymentPriceOptions(options);
    return {
      minAmount: summary.minAmount || 0.01,
      displayPrice:
        summary.maxAmount === null
          ? `${formatPaymentAmount(summary.minAmount)}+`
          : `${formatPaymentAmount(summary.minAmount)}-${formatPaymentAmount(summary.maxAmount)}`,
      placeholder:
        summary.maxAmount === null
          ? `${Number(summary.minAmount || 0).toLocaleString("en-PH")}+`
          : `${Number(summary.minAmount || 0).toLocaleString("en-PH")}-${Number(summary.maxAmount || 0).toLocaleString("en-PH")}`,
      selectedPriceOption: null,
    };
  }

  const option = options[0] || null;

  if (!option) {
    return {
      minAmount: 0.01,
      displayPrice: "Enter price",
      placeholder: "0.00",
      selectedPriceOption: null,
    };
  }

  return {
    minAmount: option.minAmount || 0.01,
    displayPrice: formatPaymentPriceOption(option),
    placeholder: formatPaymentPriceOption(option, false),
    selectedPriceOption: option,
  };
}

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
    pickupModal: { open: false, booking: null, busy: false },
    paymentModal: {
      open: false,
      booking: null,
      isEarlyPayment: false,
      finalPrice: "",
      petBreakdown: [],
      amountPaid: "",
      paymentMethod: "cash",
      notes: "",
      busy: false,
      error: "",
    },
    receiptModal: {
      open: false,
      bookingReference: "",
      ownerName: "",
      contactNumber: "",
      petName: "",
      serviceLabel: "",
      appointmentDate: "",
      appointmentTime: "",
      pets: [],
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
    releasedList: [],

    // Boots the dashboard, loads any backend config/data, and exposes the UI bridge.
    async init() {
      if (this._initialized) return;
      this._initialized = true;

      if (!localStorage.getItem("admin_token")) {
        window.location.href = "../../pages/client/sign-in.html";
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
            this.setTab(booking.paid ? "to-be-picked-up" : "for-payment");
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

      if ("releasedList" in nextPayload) {
        this.releasedList = nextPayload.releasedList;
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

      if (
        "releasedList" in payload ||
        "released" in bookingsByStatus
      ) {
        nextPayload.releasedList = this.normalizeList(
          payload.releasedList ?? bookingsByStatus["released"],
          "to-be-picked-up",
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
        pickedUp: "archived",
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
        "to-be-picked-up": "releasedList",
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

    get paymentTotalDue() {
      return this.paymentModal.petBreakdown.reduce(
        (sum, pet) => sum + this.paymentPetSubtotal(pet),
        0,
      );
    },

    get paymentLineCount() {
      return this.paymentModal.petBreakdown.reduce(
        (count, pet) => count + (Array.isArray(pet.lines) ? pet.lines.length : 0),
        0,
      );
    },

    get paymentChange() {
      const paid = parseFloat(this.paymentModal.amountPaid) || 0;
      return paid - this.paymentTotalDue;
    },

    get canSubmitPayment() {
      return (
        !this.paymentModal.busy &&
        this.paymentLineCount > 0 &&
        this.paymentTotalDue > 0 &&
        !this.hasMissingPaymentPrices() &&
        !this.getInvalidPaymentLine() &&
        this.paymentChange >= 0
      );
    },

    openPaymentModal(booking, isEarlyPayment = false) {
      this.paymentModal = {
        open: true,
        booking,
        isEarlyPayment,
        finalPrice: "",
        petBreakdown: this.buildPaymentBreakdown(booking),
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
        finalPrice: "", petBreakdown: [], amountPaid: "", paymentMethod: "cash", notes: "", busy: false, error: "",
      };
    },

    buildPaymentBreakdown(booking) {
      const pets = this.normalizePaymentPets(booking);
      const services = this.normalizePaymentServices(booking?.services);

      /*
       * Backend handoff:
       * Multi-pet payment cards need either pets[].services or services[] items
       * that include bookingPetId/booking_pet_id. Service slug fields are also
       * preferred so the frontend can show the exact minimum price rule.
       */
      return pets.map((pet, petIndex) => {
        const petServices = this.getPaymentServicesForPet(booking, pet, pets, services);
        const lines = petServices.map((service, serviceIndex) =>
          this.normalizePaymentLine(service, pet, `${petIndex}-${serviceIndex}`),
        );

        return {
          ...pet,
          lines,
        };
      });
    },

    normalizePaymentPets(booking) {
      const sourcePets = Array.isArray(booking?.pets) && booking.pets.length > 0
        ? booking.pets
        : [{
            petName: booking?.petName,
            petType: booking?.petType,
            species: booking?.petType,
            breed: booking?.breed,
            size: booking?.petSize ?? booking?.size,
          }];

      return sourcePets.map((pet, index) => {
        const petTypeKey = normalizePaymentPetType(
          pet?.species ?? pet?.petType ?? pet?.pet_type,
        );
        const sizeKey = normalizePaymentSizeForPet(
          pet?.size ?? pet?.petSize ?? pet?.pet_size,
          petTypeKey,
        );

        return {
          id: pet?.bookingPetId ?? pet?.booking_pet_id ?? pet?.id ?? `pet-${index + 1}`,
          bookingPetId: pet?.bookingPetId ?? pet?.booking_pet_id ?? null,
          petId: pet?.petId ?? pet?.pet_id ?? pet?.id ?? null,
          name: this.toStringValue(
            pet?.petName ?? pet?.pet_name ?? pet?.name ?? `Pet ${index + 1}`,
          ),
          species: this.toStringValue(pet?.species ?? pet?.petType ?? pet?.pet_type),
          petTypeKey,
          breed: this.toStringValue(pet?.breed),
          sizeKey,
          sizeLabel: formatPaymentSizeLabel(sizeKey),
          services: Array.isArray(pet?.services) ? pet.services : [],
        };
      });
    },

    normalizePaymentServices(services) {
      if (!Array.isArray(services)) {
        return [];
      }

      return services.map((service, index) => ({
        ...service,
        id: service?.bookingServiceId ?? service?.booking_service_id ?? service?.id ?? `service-${index + 1}`,
        bookingPetId: service?.bookingPetId ?? service?.booking_pet_id ?? null,
        petId: service?.petId ?? service?.pet_id ?? null,
        petName: service?.petName ?? service?.pet_name ?? "",
      }));
    },

    getPaymentServicesForPet(booking, pet, pets, services) {
      const directPetServices = this.normalizePaymentServices(pet?.services);

      if (directPetServices.length > 0) {
        return directPetServices;
      }

      const matchedServices = services.filter((service) => {
        if (service.bookingPetId && pet.bookingPetId) {
          return String(service.bookingPetId) === String(pet.bookingPetId);
        }

        if (service.petId && pet.petId) {
          return String(service.petId) === String(pet.petId);
        }

        if (service.petName && pet.name) {
          return normalizePaymentText(service.petName) === normalizePaymentText(pet.name);
        }

        return false;
      });

      if (matchedServices.length > 0) {
        return matchedServices;
      }

      if (pets.length === 1) {
        return services;
      }

      const unscopedServices = services.filter(
        (service) => !service.bookingPetId && !service.petId && !service.petName,
      );
      const petIndex = pets.findIndex((candidate) => candidate.id === pet.id);

      /*
       * Frontend fallback:
       * when the API only sends a flat services[] list, split it across pets
       * only if the count makes the grouping unambiguous. Backend should still
       * expose bookingPetId/booking_pet_id for exact multi-pet association.
       */
      if (
        petIndex >= 0 &&
        unscopedServices.length >= pets.length &&
        unscopedServices.length % pets.length === 0
      ) {
        const servicesPerPet = unscopedServices.length / pets.length;
        const startIndex = petIndex * servicesPerPet;

        return unscopedServices.slice(startIndex, startIndex + servicesPerPet);
      }

      return pets[0]?.id === pet.id ? unscopedServices : [];
    },

    normalizePaymentLine(rawService, pet, fallbackId) {
      const serviceDefinition = getPaymentServiceDefinition(rawService);
      const fallbackAmount = parseFloat(rawService?.priceAtBooking ?? rawService?.price_at_booking ?? 0);
      const pricing = serviceDefinition
        ? getPaymentServicePricing(serviceDefinition, pet.sizeKey)
        : fallbackAmount > 0
          ? {
              minAmount: fallbackAmount,
              displayPrice: formatPaymentAmount(fallbackAmount),
              placeholder: Number(fallbackAmount).toLocaleString("en-PH"),
              selectedPriceOption: null,
            }
          : getPaymentServicePricing(null, pet.sizeKey);

      return {
        id: rawService?.id ?? fallbackId,
        bookingServiceId: rawService?.bookingServiceId ?? rawService?.booking_service_id ?? null,
        serviceDefinition,
        serviceId: serviceDefinition?.id ?? rawService?.slug ?? rawService?.serviceSlug ?? rawService?.service_slug ?? "",
        kind: serviceDefinition?.kind ?? rawService?.kind ?? "service",
        name: this.toStringValue(
          rawService?.name ??
            rawService?.serviceName ??
            rawService?.service_name ??
            serviceDefinition?.name ??
            "Grooming Service",
        ),
        description: this.toStringValue(
          rawService?.description ??
            rawService?.notes ??
            serviceDefinition?.descriptionItems?.[0] ??
            (serviceDefinition?.kind === "package" ? "Package service" : "A la carte service"),
        ),
        minAmount: pricing.minAmount,
        priceHint: pricing.displayPrice,
        placeholder: pricing.placeholder,
        amount: "",
      };
    },

    updatePaymentPetSize(pet) {
      pet.sizeKey = normalizePaymentSizeForPet(pet.sizeKey, pet.petTypeKey);
      pet.sizeLabel = formatPaymentSizeLabel(pet.sizeKey);

      pet.lines.forEach((line) => {
        this.refreshPaymentLinePricing(pet, line);
        this.enforcePaymentMinimum(pet, line);
      });

      this.paymentModal.error = "";
    },

    refreshPaymentLinePricing(pet, line) {
      const pricing = getPaymentServicePricing(line.serviceDefinition, pet.sizeKey);
      line.minAmount = pricing.minAmount;
      line.priceHint = pricing.displayPrice;
      line.placeholder = pricing.placeholder;
    },

    getPaymentSizeOptions(pet) {
      const baseOptions = getPaymentBaseSizeOptions(pet?.petTypeKey ?? pet?.species);
      const packageLines = (pet?.lines || []).filter(
        (line) => line.serviceDefinition?.kind === "package",
      );
      const allowedSizes = new Set();

      packageLines.forEach((line) => {
        (line.serviceDefinition?.priceOptions || []).forEach((option) => {
          if (option.sizeKey) {
            allowedSizes.add(option.sizeKey);
          }
        });
      });

      const options = allowedSizes.size > 0
        ? baseOptions.filter((option) => allowedSizes.has(option.value))
        : baseOptions;

      if (pet?.sizeKey && !options.some((option) => option.value === pet.sizeKey)) {
        return [
          ...options,
          { value: pet.sizeKey, label: formatPaymentSizeLabel(pet.sizeKey) },
        ];
      }

      return options;
    },

    paymentPetSubtotal(pet) {
      return (pet?.lines || []).reduce((sum, line) => {
        const amount = parseFloat(line.amount);
        return Number.isFinite(amount) ? sum + amount : sum;
      }, 0);
    },

    enforcePaymentMinimum(pet, line) {
      if (line.amount === "" || line.amount === null || line.amount === undefined) {
        return;
      }

      const amount = parseFloat(line.amount);
      const minimum = parseFloat(line.minAmount) || 0.01;

      if (!Number.isFinite(amount)) {
        line.amount = "";
        return;
      }

      if (amount < minimum) {
        line.amount = minimum.toFixed(2);
      }
    },

    hasMissingPaymentPrices() {
      return this.paymentModal.petBreakdown.some((pet) =>
        (pet.lines || []).some((line) => line.amount === "" || line.amount === null || line.amount === undefined),
      );
    },

    getInvalidPaymentLine() {
      for (const pet of this.paymentModal.petBreakdown) {
        for (const line of pet.lines || []) {
          const amount = parseFloat(line.amount);
          const minimum = parseFloat(line.minAmount) || 0.01;

          if (!Number.isFinite(amount)) {
            return { pet, line, reason: "missing" };
          }

          if (amount < minimum) {
            return { pet, line, reason: "minimum" };
          }
        }
      }

      return null;
    },

    clearPaymentError() {
      this.paymentModal.error = "";
    },

    formatPeso(amount) {
      return `\u20b1${Number(amount || 0).toFixed(2)}`;
    },

    formatReceiptValue(value, fallbackValue = "Not specified") {
      const text = this.toStringValue(value).trim();
      return text ? text : fallbackValue;
    },

    buildPaymentReceiptPets(petBreakdown) {
      const pets = Array.isArray(petBreakdown) ? petBreakdown : [];

      return pets.map((pet, petIndex) => {
        const lines = (Array.isArray(pet?.lines) ? pet.lines : []).map((line, lineIndex) => {
          const amount = parseFloat(line?.amount);

          return {
            id: `${pet?.id ?? `pet-${petIndex + 1}`}-${line?.id ?? lineIndex}`,
            name: this.formatReceiptValue(line?.name, "Grooming Service"),
            price: Number.isFinite(amount) ? amount : 0,
          };
        });

        return {
          id: pet?.id ?? `receipt-pet-${petIndex + 1}`,
          name: this.formatReceiptValue(pet?.name, `Pet ${petIndex + 1}`),
          species: this.formatReceiptValue(pet?.species || pet?.petTypeKey),
          breed: this.formatReceiptValue(pet?.breed),
          sizeLabel: this.formatReceiptValue(pet?.sizeLabel || formatPaymentSizeLabel(pet?.sizeKey)),
          lines,
          subtotal: lines.reduce((sum, line) => sum + line.price, 0),
        };
      });
    },

    async submitPayment() {
      const { booking, isEarlyPayment, amountPaid, notes } = this.paymentModal;
      const fp = Number(this.paymentTotalDue.toFixed(2));
      const ap = parseFloat(amountPaid);
      const paymentMethod = this.paymentModal.paymentMethod || "cash";

      if (this.paymentLineCount === 0) {
        this.paymentModal.error = "No booked services were found for this payment.";
        return;
      }

      if (this.hasMissingPaymentPrices()) {
        this.paymentModal.error = "Please enter the confirmed price for every service.";
        return;
      }

      const invalidLine = this.getInvalidPaymentLine();
      if (invalidLine) {
        this.paymentModal.error = `${invalidLine.line.name} for ${invalidLine.pet.name} cannot be less than ${this.formatPeso(invalidLine.line.minAmount)}.`;
        return;
      }

      if (!fp || fp <= 0) {
        this.paymentModal.error = "Please enter service prices before confirming payment.";
        return;
      }
      if (!ap || ap < fp) {
        this.paymentModal.error = "Amount paid cannot be less than the total amount due.";
        return;
      }

      this.paymentModal.busy  = true;
      this.paymentModal.error = "";

      try {
        const payload = {
          final_price:    fp,
          amount_paid:    ap,
          payment_method: paymentMethod,
          notes:          notes || null,
        };
        const res = isEarlyPayment
          ? await API.payNow(booking.id, payload)
          : await API.processPayment(booking.id, payload);

        /*
         * Frontend-only receipt snapshot:
         * the payment API currently persists the final total, while this modal
         * displays the admin-entered per-service amounts from the form above.
         * If line-level paid prices need storage later, backend can accept these
         * line amounts explicitly; no backend contract is changed here.
         */
        const receiptPets = this.buildPaymentReceiptPets(this.paymentModal.petBreakdown);
        const receiptPetNames = receiptPets.map((pet) => pet.name).filter(Boolean).join(", ");
        const receiptServiceNames = [
          ...new Set(
            receiptPets.flatMap((pet) => pet.lines.map((line) => line.name)).filter(Boolean),
          ),
        ].join(", ");

        this.closePaymentModal();
        this.receiptModal = {
          open: true,
          bookingReference: this.formatReceiptValue(
            booking.bookingReference ?? booking.booking_reference ?? booking.reference ?? booking.id,
            "Pending",
          ),
          ownerName:     this.formatReceiptValue(booking.ownerName, "Not provided"),
          contactNumber: this.formatReceiptValue(booking.contactNumber, "Not provided"),
          petName:       receiptPetNames || this.formatReceiptValue(booking.petName, "No pet selected"),
          serviceLabel:  receiptServiceNames || this.formatReceiptValue(booking.serviceLabel, "Grooming"),
          appointmentDate: this.formatReceiptValue(booking.appointmentDate, "No date selected"),
          appointmentTime: this.formatReceiptValue(booking.appointmentTime, "No time selected"),
          pets:          receiptPets,
          finalPrice:    fp,
          amountPaid:    ap,
          change:        res.change ?? (ap - fp),
          paymentMethod: paymentMethod,
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
        open: false, bookingReference: "", ownerName: "", contactNumber: "",
        petName: "", serviceLabel: "", appointmentDate: "", appointmentTime: "",
        pets: [], finalPrice: 0, amountPaid: 0, change: 0,
        paymentMethod: "", paidAt: "", isEarlyPayment: false,
      };
    },

    printReceipt() {
      const printClass = "payment-receipt-printing";
      const receiptSummary = document.getElementById("paymentReceiptSummary");
      const printClone = receiptSummary?.cloneNode(true) ?? null;
      let classRestored = false;

      function restorePrintClass() {
        if (classRestored) {
          return;
        }

        classRestored = true;
        printClone?.remove();
        document.body.classList.remove(printClass);
        window.removeEventListener("afterprint", restorePrintClass);
      }

      if (printClone) {
        printClone.classList.add("payment-print-clone");
        document.body.appendChild(printClone);
      }

      document.body.classList.add(printClass);
      window.addEventListener("afterprint", restorePrintClass);
      window.print();
      window.setTimeout(restorePrintClass, 1000);
    },

    async releaseBooking(booking) {
      try {
        await API.releaseBooking(booking.id);
        await this.loadAdminBookings();
      } catch (err) {
        alert(err.message || "Release failed. Please try again.");
      }
    },

    confirmPickedUp(booking) {
      this.pickupModal = { open: true, booking, busy: false };
    },

    async executePickedUp() {
      const booking = this.pickupModal.booking;
      if (!booking) return;

      this.pickupModal.busy = true;
      try {
        await API.markPickedUp(booking.id);
        this.pickupModal = { open: false, booking: null, busy: false };
        await this.loadAdminBookings();
      } catch (err) {
        this.pickupModal.busy = false;
        alert(err.message || "Failed to mark as picked up. Please try again.");
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
