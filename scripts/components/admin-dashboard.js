const PAYMENT_SIZE_OPTIONS = [
  { value: "small", label: "Small" },
  { value: "medium", label: "Medium" },
  { value: "large", label: "Large" },
  { value: "extra_large", label: "Extra Large" },
];

const PAYMENT_BILL_DENOMINATION = 1000;

function maximumPaymentAmount(totalDue) {
  const amount = Number(totalDue);

  if (!Number.isFinite(amount) || amount <= 0) {
    return 0;
  }

  return (
    Math.floor(amount / PAYMENT_BILL_DENOMINATION) * PAYMENT_BILL_DENOMINATION +
    PAYMENT_BILL_DENOMINATION * 2
  );
}

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

function parsePaymentNumber(value) {
  const amount = Number.parseFloat(String(value ?? "").replace(/,/g, ""));
  return Number.isFinite(amount) ? amount : null;
}

function getPaymentPetSizeCandidate(source) {
  if (!source) {
    return "";
  }

  return [
    source.size,
    source.petSize,
    source.pet_size,
    source.sizeKey,
    source.size_key,
    source.selectedSize,
    source.selected_size,
    source.pet?.size,
    source.pet?.petSize,
    source.pet?.pet_size,
  ].find((value) => normalizePaymentSize(value)) || "";
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

function inferPaymentSizeFromServices(services, petType) {
  if (!Array.isArray(services) || services.length === 0) {
    return "";
  }

  const allowedSizes = new Set(
    getPaymentBaseSizeOptions(petType).map((option) => option.value),
  );

  for (const rawService of services) {
    const serviceDefinition = getPaymentServiceDefinition(rawService);

    if (serviceDefinition?.kind !== "package") {
      continue;
    }

    const bookedAmount = parsePaymentNumber(
      rawService?.priceAtBooking ?? rawService?.price_at_booking,
    );

    if (!Number.isFinite(bookedAmount) || bookedAmount <= 0) {
      continue;
    }

    const matchedPriceOption = (serviceDefinition.priceOptions || []).find(
      (option) =>
        option.sizeKey &&
        allowedSizes.has(option.sizeKey) &&
        Math.abs(Number(option.minAmount || 0) - bookedAmount) < 0.01,
    );

    if (matchedPriceOption) {
      return matchedPriceOption.sizeKey;
    }
  }

  return "";
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
      pricingType: "custom",
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
        pricingType: selectedPriceOption.pricingType,
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
      pricingType: summary.pricingType,
      selectedPriceOption: null,
    };
  }

  const option = options[0] || null;

  if (!option) {
    return {
      minAmount: 0.01,
      displayPrice: "Enter price",
      placeholder: "0.00",
      pricingType: "custom",
      selectedPriceOption: null,
    };
  }

  return {
    minAmount: option.minAmount || 0.01,
    displayPrice: formatPaymentPriceOption(option),
    placeholder: formatPaymentPriceOption(option, false),
    pricingType: option.pricingType,
    selectedPriceOption: option,
  };
}

function shouldLockPaymentPrice(pricing, lockFixedPrices) {
  if (!lockFixedPrices) {
    return false;
  }

  const pricingType = pricing?.pricingType || "custom";

  return pricingType !== "plus" && pricingType !== "custom";
}

/*
 * Backend integration contract:
 * - Optional preload config: window.ADMIN_DASHBOARD_CONFIG = { bootstrap, endpoints, handlers, ... }
 * - Optional runtime bridge after Alpine init: window.adminDashboardUI.setData(...), setConfig(...), getState()
 * - Action hooks: loadDashboard, walkInBooking, checkIn, startGrooming, markDone, archive, viewDetails
 */
function adminDashboard() {
  return {
    ...adminGroomingConcernState(),
    ...adminClinicReferralState(),
    ...adminStoppedPaymentReviewState(),
    activeTab: "incoming",
    todayCount: 0,
    weekCount: 0,
    revenueToday: 0,
    revenuePaymentCount: 0,
    noShowWeekCount: 0,
    noShowWeekRate: 0,
    currentCapacity: 0,
    maxCapacity: 20,
    notificationCount: 0,
    notificationsOpen: false,
    notifications: [],
    recentActivity: [],
    notifTab: "all",
    detailsModalOpen: false,
    detailsBooking: null,
    pendingActions: {},
    localCancelledBookingIds: [],
    localRevertedToIncomingBookings: [],
    localRevertedToQueuedBookings: [],
    expandedQueuedBookingIds: {},
    expandedInProgressBookingIds: {},
    _pollFailures: {},
    // Use local date (not UTC) so the calendar defaults to the correct day in PH
    selectedDate: (() => {
      const today = window.AppClock?.todayKey?.();
      if (today) return today;

      const d = new Date();
      return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;
    })(),
    get todayDate() {
      const today = window.AppClock?.todayKey?.();
      if (today) return today;

      const d = new Date();
      return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;
    },
    get maxDate() {
      const maxDate = window.AppClock?.dateKeyWithOffset?.(3);
      if (maxDate) return maxDate;

      const d = new Date();
      d.setDate(d.getDate() + 3);
      return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;
    },
    noShowList: [],
    forPaymentList: [],
    activeGroomers: 2,
    activeGroomingPets: 0,
    groomerCapacityBusy: false,
    groomerCapacityError: "",
    clinicStopped: false,
    stopModal: { open: false, title: "", message: "" },
    pickupModal: { open: false, booking: null, busy: false },
    actionConfirmModal: {
      open: false,
      action: "",
      booking: null,
      title: "",
      message: "",
      confirmLabel: "",
      busyLabel: "",
      icon: "checkIn",
      variant: "primary",
      busy: false,
      error: "",
    },
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
      walkInBookingUrl: "./walk-in-booking.html",
      detailPageUrl: "",
      enableOptimisticUpdates: false,
      includeFutureAppointments: false,
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

    get showFutureAppointments() {
      return Boolean(this.config.includeFutureAppointments);
    },

    // Boots the dashboard, loads any backend config/data, and exposes the UI bridge.
    async init() {
      if (this._initialized) return;
      this._initialized = true;

      if (!localStorage.getItem("admin_token")) {
        window.location.href = "../../pages/client/sign-in.html";
        return;
      }

      await window.AppClock?.load?.();
      this.selectedDate = this.todayDate;

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
          startPetGrooming: async ({ booking }) => {
            const pet = booking?.actionPet;
            const bookingPetId = pet?.bookingPetId ?? pet?.booking_pet_id ?? pet?.id;

            if (!bookingPetId) {
              throw new Error("Cannot start grooming: missing booking pet id.");
            }

            const response = await API.adminStartPetGrooming(booking.id, bookingPetId);
            await this.loadAdminBookings();

            this.setInProgressBookingExpanded(booking.id, true);
            this.setTab("in-progress");

            return response;
          },
          markDone: async ({ booking }) => {
            await API.adminMarkDone(booking.id);
            await this.loadAdminBookings();
            this.setTab(booking.paid ? "to-be-picked-up" : "for-payment");
          },
          markPetDone: async ({ booking }) => {
            const pet = booking?.actionPet;
            const bookingPetId = pet?.bookingPetId ?? pet?.booking_pet_id ?? pet?.id;

            if (!bookingPetId) {
              throw new Error("Cannot finish grooming: missing booking pet id.");
            }

            const response = await API.adminMarkPetDone(booking.id, bookingPetId);
            await this.loadAdminBookings();

            if (response?.all_pets_finished) {
              const completedStatus = this.normalizeStatus(response.booking_status);
              this.setTab(
                completedStatus === "to-be-picked-up" || completedStatus === "released"
                  ? "to-be-picked-up"
                  : "for-payment",
              );
            } else {
              this.setInProgressBookingExpanded(booking.id, true);
              this.setTab("in-progress");
            }

            return response;
          },
          cancel: async ({ booking }) => {
            await API.adminCancelBooking(booking.id);
            await this.loadAdminBookings();
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

    // Opens a second confirmation before moving an Incoming booking into Queued.
    confirmCheckInBooking(booking) {
      const ownerName = String(booking?.ownerName || "").trim();
      this.openActionConfirmModal({
        action: "checkIn",
        booking,
        title: "Confirm Check In",
        message: ownerName
          ? `Are you sure you want to check in ${ownerName}?`
          : "Are you sure you want to check in this appointment?",
        confirmLabel: "Yes, Check In",
        busyLabel: "Checking in...",
        icon: "checkIn",
        variant: "primary",
      });
    },

    // Opens a second confirmation before moving a Queued booking into In-Progress.
    confirmStartGroomingBooking(booking) {
      if (this.isGroomerCapacityFull) {
        this.groomerCapacityError = "Groomer capacity is full. Finish a pet before starting another.";
        return;
      }

      const ownerName = String(booking?.ownerName || "").trim();
      this.openActionConfirmModal({
        action: "startGrooming",
        booking,
        title: "Confirm Start Grooming",
        message: ownerName
          ? `Are you sure you want to start grooming for ${ownerName}?`
          : "Are you sure you want to start grooming this appointment?",
        confirmLabel: "Yes, Start",
        busyLabel: "Starting...",
        icon: "grooming",
        variant: "primary",
      });
    },

    // Confirms a per-pet start. The selected pet travels with the cloned booking
    // so the shared confirmation modal can continue using its existing contract.
    confirmStartGroomingPet(booking, pet) {
      if (this.isPetReferredToClinic(pet) || pet?.isGroomingStarted || this.isGroomerCapacityFull) {
        if (this.isGroomerCapacityFull) {
          this.groomerCapacityError = "Groomer capacity is full. Finish a pet before starting another.";
        }
        return;
      }

      const petName = String(pet?.petName ?? pet?.pet_name ?? pet?.name ?? "this pet").trim();
      const hasEarlierUnfinishedPets = this.hasEarlierQueuedUnfinishedPets(booking);
      this.openActionConfirmModal({
        action: "startPetGrooming",
        booking: {
          ...booking,
          actionPet: { ...pet },
        },
        title: hasEarlierUnfinishedPets ? "Queue Order Warning" : "Confirm Start Grooming",
        message: hasEarlierUnfinishedPets
          ? "An earlier queue still has unfinished pets. Are you sure you want to start grooming this pet first?"
          : `Are you sure you want to start grooming for ${petName}?`,
        confirmLabel: "Yes, Start",
        busyLabel: "Starting...",
        icon: "grooming",
        variant: hasEarlierUnfinishedPets ? "warning" : "primary",
      });
    },

    isPetGroomingFinished(pet) {
      if (this.petGroomingState(pet) === "finished") {
        return true;
      }

      if (pet?.isGroomingFinished === true) {
        return true;
      }

      const finishedValues = [
        pet?.groomingFinishedAt,
        pet?.grooming_finished_at,
        pet?.groomingFinishedAtIso,
        pet?.grooming_finished_at_iso,
        pet?.groomingEndTime,
        pet?.grooming_end_time,
      ];

      return finishedValues.some((value) => {
        const text = String(value ?? "").trim().toLowerCase();
        return Boolean(text && !["-", "\u2014", "none", "null", "not provided"].includes(text));
      });
    },

    isPetGroomingStarted(pet) {
      if (this.isPetReferredToClinic(pet)) {
        return false;
      }

      if (["in_progress", "paused", "stopped", "finished"].includes(
        this.petGroomingState(pet),
      )) {
        return true;
      }

      if (pet?.isGroomingStarted === true) {
        return true;
      }

      const startedValues = [
        pet?.groomingStartedAt,
        pet?.grooming_started_at,
        pet?.groomingStartedAtIso,
        pet?.grooming_started_at_iso,
        pet?.groomingStartTime,
        pet?.grooming_start_time,
      ];

      return startedValues.some((value) => {
        const text = String(value ?? "").trim().toLowerCase();
        return Boolean(text && !["-", "\u2014", "none", "null", "not provided"].includes(text));
      });
    },

    hasActiveGroomingPets(booking) {
      const pets = Array.isArray(booking?.pets) ? booking.pets : [];
      return pets.some((pet) => this.petGroomingState(pet) === "in_progress");
    },

    hasStartedGroomingPets(booking) {
      const pets = Array.isArray(booking?.pets) ? booking.pets : [];
      return pets.some((pet) => this.isPetGroomingStarted(pet));
    },

    hasActiveGroomingPetsForBooking(bookingId) {
      const id = String(bookingId ?? "");
      const booking = [...this.inProgressList, ...this.queuedList].find(
        (item) => String(item?.id ?? "") === id,
      );

      return this.hasActiveGroomingPets(booking);
    },

    hasUnfinishedQueuedPets(booking) {
      const pets = Array.isArray(booking?.pets) ? booking.pets : [];
      return pets.some((pet) =>
        !this.isPetReferredToClinic(pet) && !this.isPetGroomingFinished(pet),
      );
    },

    getWaitingGroomingPets(booking) {
      const pets = Array.isArray(booking?.pets) ? booking.pets : [];
      return pets.filter((pet) => this.petGroomingState(pet) === "not_started");
    },

    petGroomingState(pet) {
      if (this.isPetReferredToClinic(pet)) {
        return "referred_to_clinic";
      }

      const explicit = String(
        pet?.groomingState ?? pet?.grooming_state ?? "",
      ).trim().toLowerCase();
      if (["not_started", "in_progress", "paused", "stopped", "finished"].includes(explicit)) {
        return explicit;
      }

      if (pet?.isGroomingFinished === true) return "finished";
      if (pet?.isGroomingStarted === true) return "in_progress";

      const finished = pet?.groomingFinishedAtIso
        ?? pet?.grooming_finished_at
        ?? pet?.groomingFinishedAt;
      if (finished) return "finished";

      const started = pet?.groomingStartedAtIso
        ?? pet?.grooming_start_time
        ?? pet?.groomingStartedAt;
      return started ? "in_progress" : "not_started";
    },

    petGroomingStateLabel(pet) {
      return {
        not_started: "Not started",
        in_progress: "In progress",
        paused: "Paused",
        stopped: "Grooming stopped",
        finished: "Finished",
        referred_to_clinic: "Referred to clinic",
      }[this.petGroomingState(pet)] || "Not started";
    },

    isPetReferredToClinic(pet) {
      return pet?.hasClinicReferral === true || pet?.has_clinic_referral === true;
    },

    isPetGroomingInProgress(pet) {
      return this.petGroomingState(pet) === "in_progress";
    },

    hasEarlierQueuedUnfinishedPets(booking) {
      const selectedId = String(booking?.id ?? "");
      const selectedIndex = this.queuedList.findIndex(
        (queuedBooking) => String(queuedBooking?.id ?? "") === selectedId,
      );

      if (selectedIndex > 0) {
        return this.queuedList
          .slice(0, selectedIndex)
          .some((queuedBooking) => this.hasUnfinishedQueuedPets(queuedBooking));
      }

      const selectedQueueNumber = Number(booking?.queueNumber ?? 0);
      if (!Number.isFinite(selectedQueueNumber) || selectedQueueNumber <= 0) {
        return false;
      }

      return this.queuedList.some((queuedBooking) => {
        const queueNumber = Number(queuedBooking?.queueNumber ?? 0);
        return (
          Number.isFinite(queueNumber) &&
          queueNumber > 0 &&
          queueNumber < selectedQueueNumber &&
          this.hasUnfinishedQueuedPets(queuedBooking)
        );
      });
    },

    // Opens a second confirmation before finishing an In-Progress booking.
    confirmMarkBookingDone(booking) {
      const ownerName = String(booking?.ownerName || "").trim();
      this.openActionConfirmModal({
        action: "markDone",
        booking,
        title: "Confirm Finished",
        message: ownerName
          ? `Are you sure you want to mark ${ownerName}'s appointment as finished?`
          : "Are you sure you want to mark this appointment as finished?",
        confirmLabel: "Yes, Finished",
        busyLabel: "Finishing...",
        icon: "finished",
        variant: "primary",
      });
    },

    // Confirms completion for one pet without finishing the owner booking early.
    confirmMarkPetDone(booking, pet) {
      if (!pet?.isGroomingStarted || pet?.isGroomingFinished) {
        return;
      }

      const petName = String(pet?.petName ?? pet?.pet_name ?? pet?.name ?? "this pet").trim();
      this.openActionConfirmModal({
        action: "markPetDone",
        booking: {
          ...booking,
          actionPet: { ...pet },
        },
        title: "Confirm Finished",
        message: `Are you sure you want to mark ${petName} as finished?`,
        confirmLabel: "Yes, Finished",
        busyLabel: "Finishing...",
        icon: "finished",
        variant: "primary",
      });
    },

    // Opens a second confirmation before moving a Queued booking back to Incoming in this UI.
    confirmRevertQueuedBooking(booking) {
      const ownerName = String(booking?.ownerName || "").trim();
      this.openActionConfirmModal({
        action: "revertQueued",
        booking,
        title: "Revert to Incoming",
        message: ownerName
          ? `Are you sure you want to move ${ownerName}'s appointment back to Incoming?`
          : "Are you sure you want to move this appointment back to Incoming?",
        confirmLabel: "Yes, Revert",
        busyLabel: "Reverting...",
        icon: "revert",
        variant: "primary",
      });
    },

    // Opens a second confirmation before moving an In-Progress booking back to Queued in this UI.
    confirmRevertInProgressBooking(booking) {
      const ownerName = String(booking?.ownerName || "").trim();
      this.openActionConfirmModal({
        action: "revertInProgress",
        booking,
        title: "Revert to Queued",
        message: ownerName
          ? `Are you sure you want to move ${ownerName}'s appointment back to Queued?`
          : "Are you sure you want to move this appointment back to Queued?",
        confirmLabel: "Yes, Revert",
        busyLabel: "Reverting...",
        icon: "revert",
        variant: "primary",
      });
    },

    // Opens a second confirmation before hiding a booking as cancelled in this UI.
    confirmCancelBooking(booking) {
      const ownerName = String(booking?.ownerName || "").trim();
      this.openActionConfirmModal({
        action: "cancel",
        booking,
        title: "Cancel Appointment",
        message: ownerName
          ? `Are you sure you want to cancel ${ownerName}'s appointment?`
          : "Are you sure you want to cancel this appointment?",
        confirmLabel: "Yes, Cancel",
        busyLabel: "Cancelling...",
        icon: "cancel",
        variant: "danger",
      });
    },

    openActionConfirmModal({
      action,
      booking,
      title,
      message,
      confirmLabel,
      busyLabel,
      icon = "checkIn",
      variant = "primary",
    }) {
      this.actionConfirmModal = {
        open: true,
        action,
        booking: this.cloneBooking(booking),
        title,
        message,
        confirmLabel,
        busyLabel,
        icon,
        variant,
        busy: false,
        error: "",
      };
    },

    closeActionConfirmModal(force = false) {
      if (this.actionConfirmModal.busy && !force) {
        return;
      }

      this.actionConfirmModal = {
        open: false,
        action: "",
        booking: null,
        title: "",
        message: "",
        confirmLabel: "",
        busyLabel: "",
        icon: "checkIn",
        variant: "primary",
        busy: false,
        error: "",
      };
    },

    async executeConfirmedBookingAction() {
      const { action, booking } = this.actionConfirmModal;
      if (!action || !booking) {
        return;
      }

      this.actionConfirmModal.busy = true;
      this.actionConfirmModal.error = "";

      try {
        if (action === "checkIn") {
          await this.checkInBooking(booking);
        } else if (action === "startGrooming") {
          await this.startGroomingBooking(booking);
        } else if (action === "startPetGrooming") {
          await this.runBookingAction("startPetGrooming", booking);
        } else if (action === "markDone") {
          await this.markBookingDone(booking);
        } else if (action === "markPetDone") {
          await this.runBookingAction("markPetDone", booking);
        } else if (action === "cancel") {
          await this.cancelBooking(booking);
        } else if (action === "revertQueued") {
          this.revertQueuedBookingFrontendOnly(booking);
        } else if (action === "revertInProgress") {
          this.revertInProgressBookingFrontendOnly(booking);
        }
        this.closeActionConfirmModal(true);
      } catch (error) {
        this.actionConfirmModal.error = error.message || "Action failed. Please try again.";
      } finally {
        this.actionConfirmModal.busy = false;
      }
    },

    // Starts the Incoming -> Queued transition for a booking.
    async checkInBooking(booking) {
      await this.runBookingAction("checkIn", booking);
    },

    async cancelBooking(booking) {
      await this.runBookingAction("cancel", booking);
    },

    // Legacy local-only fallback used only if a custom integration calls it directly.
    cancelBookingFrontendOnly(booking) {
      if (!booking || booking.id === undefined || booking.id === null) {
        return;
      }

      this.rememberLocalCancellation(booking.id);
      this.localRevertedToIncomingBookings = this.localRevertedToIncomingBookings.filter(
        (item) => String(item.id) !== String(booking.id),
      );
      this.localRevertedToQueuedBookings = this.localRevertedToQueuedBookings.filter(
        (item) => String(item.id) !== String(booking.id),
      );
      this.removeBookingFromLists(booking.id);
      this.dispatchDashboardEvent("admin-dashboard:booking-cancelled-ui-only", {
        booking: this.cloneBooking(booking),
        state: this.getState(),
      });
      this.refreshIcons();
    },

    // Frontend-only revert: moves a queued card back into Incoming in this browser session.
    // BACKEND: replace this with a real queued -> incoming endpoint/status when this workflow is supported server-side.
    revertQueuedBookingFrontendOnly(booking) {
      if (!booking || booking.id === undefined || booking.id === null) {
        return;
      }

      const incomingBooking = this.normalizeBooking(
        {
          ...booking,
          status: "incoming",
        },
        "incoming",
      );

      this.rememberLocalRevertToIncoming(incomingBooking);
      this.removeBookingFromLists(incomingBooking.id);
      this.incomingList = this.insertBookingSorted(this.incomingList, incomingBooking);
      this.setTab("incoming");
      this.dispatchDashboardEvent("admin-dashboard:booking-reverted-ui-only", {
        booking: this.cloneBooking(incomingBooking),
        state: this.getState(),
      });
      this.refreshIcons();
    },

    // Frontend-only revert: moves an in-progress card back into Queued in this browser session.
    // BACKEND: replace this with a real in_progress -> queued endpoint/status when this workflow is supported server-side.
    revertInProgressBookingFrontendOnly(booking) {
      if (!booking || booking.id === undefined || booking.id === null) {
        return;
      }

      const queuedBooking = this.normalizeBooking(
        {
          ...booking,
          status: "queued",
        },
        "queued",
      );

      this.rememberLocalRevertToQueued(queuedBooking);
      this.removeBookingFromLists(queuedBooking.id);
      this.queuedList = this.insertBookingSorted(this.queuedList, queuedBooking);
      this.setTab("queued");
      this.dispatchDashboardEvent("admin-dashboard:booking-reverted-ui-only", {
        booking: this.cloneBooking(queuedBooking),
        state: this.getState(),
      });
      this.refreshIcons();
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
        if (["startGrooming", "startPetGrooming"].includes(actionName)) {
          this.groomerCapacityError = "";
        }

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
        if (["startGrooming", "startPetGrooming"].includes(actionName)) {
          this.groomerCapacityError = error.message;
          await this.loadAdminBookings();
        }
        this.dispatchDashboardEvent("admin-dashboard:action-error", {
          action: actionName,
          booking: this.cloneBooking(booking),
          error: error.message,
          state: this.getState(),
        });
        throw error;
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

      if ("revenueToday" in nextPayload) {
        this.revenueToday = nextPayload.revenueToday;
      }

      if ("revenuePaymentCount" in nextPayload) {
        this.revenuePaymentCount = nextPayload.revenuePaymentCount;
      }

      if ("noShowWeekCount" in nextPayload) {
        this.noShowWeekCount = nextPayload.noShowWeekCount;
      }

      if ("noShowWeekRate" in nextPayload) {
        this.noShowWeekRate = nextPayload.noShowWeekRate;
      }

      if ("currentCapacity" in nextPayload) {
        this.currentCapacity = nextPayload.currentCapacity;
      }

      if ("maxCapacity" in nextPayload) {
        this.maxCapacity = nextPayload.maxCapacity;
      }

      if ("activeGroomers" in nextPayload) {
        this.activeGroomers = nextPayload.activeGroomers;
      }

      if ("activeGroomingPets" in nextPayload) {
        this.activeGroomingPets = nextPayload.activeGroomingPets;
      }

      if ("notificationCount" in nextPayload) {
        this.notificationCount = nextPayload.notificationCount;
      }

      if ("recentActivity" in nextPayload) {
        this.recentActivity = nextPayload.recentActivity;
      }

      if ("incomingList" in nextPayload) {
        this.incomingList = this.filterLocalCancelledBookings(nextPayload.incomingList);
      }

      if ("queuedList" in nextPayload) {
        this.queuedList = this.filterLocalCancelledBookings(nextPayload.queuedList);
      }

      if ("inProgressList" in nextPayload) {
        this.inProgressList = this.filterLocalCancelledBookings(nextPayload.inProgressList);
      }

      if ("forPickupList" in nextPayload) {
        this.forPickupList = this.filterLocalCancelledBookings(nextPayload.forPickupList);
      }

      if ("forPaymentList" in nextPayload) {
        this.forPaymentList = this.filterLocalCancelledBookings(nextPayload.forPaymentList);
      }

      if ("releasedList" in nextPayload) {
        this.releasedList = this.filterLocalCancelledBookings(nextPayload.releasedList);
      }

      this.applyLocalRevertedBookings();
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
      const groomerCapacity = payload.groomerCapacity || payload.groomer_capacity || {};
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
        this.hasValue(payload.revenueToday) ||
        this.hasValue(summary.revenueToday) ||
        this.hasValue(summary.revenue_today)
      ) {
        nextPayload.revenueToday = this.toNumber(
          payload.revenueToday ?? summary.revenueToday ?? summary.revenue_today,
          this.revenueToday,
        );
      }

      if (
        this.hasValue(payload.revenuePaymentCount) ||
        this.hasValue(summary.revenuePaymentCount) ||
        this.hasValue(summary.revenue_payment_count)
      ) {
        nextPayload.revenuePaymentCount = this.toNumber(
          payload.revenuePaymentCount ??
            summary.revenuePaymentCount ??
            summary.revenue_payment_count,
          this.revenuePaymentCount,
        );
      }

      if (
        this.hasValue(payload.noShowWeekCount) ||
        this.hasValue(summary.noShowWeek) ||
        this.hasValue(summary.noShowWeekCount) ||
        this.hasValue(summary.no_show_week)
      ) {
        nextPayload.noShowWeekCount = this.toNumber(
          payload.noShowWeekCount ??
            summary.noShowWeekCount ??
            summary.noShowWeek ??
            summary.no_show_week,
          this.noShowWeekCount,
        );
      }

      if (
        this.hasValue(payload.noShowWeekRate) ||
        this.hasValue(summary.noShowWeekRate) ||
        this.hasValue(summary.no_show_week_rate)
      ) {
        nextPayload.noShowWeekRate = this.toNumber(
          payload.noShowWeekRate ?? summary.noShowWeekRate ?? summary.no_show_week_rate,
          this.noShowWeekRate,
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
        this.hasValue(payload.activeGroomers) ||
        this.hasValue(groomerCapacity.groomers_on_duty)
      ) {
        nextPayload.activeGroomers = this.toNumber(
          payload.activeGroomers ?? groomerCapacity.groomers_on_duty,
          this.activeGroomers,
        );
      }

      if (
        this.hasValue(payload.activeGroomingPets) ||
        this.hasValue(groomerCapacity.active_pets)
      ) {
        nextPayload.activeGroomingPets = this.toNumber(
          payload.activeGroomingPets ?? groomerCapacity.active_pets,
          this.activeGroomingPets,
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

      if ("recentActivity" in payload || "recent_activity" in payload) {
        nextPayload.recentActivity = this.normalizeRecentActivity(
          payload.recentActivity ?? payload.recent_activity,
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

      this.promoteStartedQueuedBookings(nextPayload);

      return nextPayload;
    },

    promoteStartedQueuedBookings(nextPayload) {
      if (!Array.isArray(nextPayload.queuedList)) {
        return;
      }

      const queuedBookings = [];
      const promotedBookings = [];

      for (const booking of nextPayload.queuedList) {
        if (this.hasStartedGroomingPets(booking)) {
          promotedBookings.push(
            this.normalizeBooking(
              {
                ...booking,
                status: "in-progress",
              },
              "in-progress",
            ),
          );
        } else {
          queuedBookings.push(booking);
        }
      }

      if (promotedBookings.length === 0) {
        return;
      }

      const existingInProgressIds = new Set(
        (nextPayload.inProgressList || []).map((booking) => String(booking?.id ?? "")),
      );

      nextPayload.queuedList = queuedBookings;
      nextPayload.inProgressList = [
        ...(nextPayload.inProgressList || []),
        ...promotedBookings.filter(
          (booking) => !existingInProgressIds.has(String(booking?.id ?? "")),
        ),
      ].sort((left, right) => this.compareBookings(left, right));
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
        petType: this.formatBookingPetTypes(booking),
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

    // ── QUEUE ETA ENGINE ─────────────────────────────────────────────────────
    // Assigns each unfinished pet to the next available groomer slot and returns
    // a map of { bookingPetId → { estStartAt: ms, estDoneAt: ms } }.
    _computeQueueETAs() {
      const now = Date.now();
      const slots = Array(Math.max(1, this.activeGroomers)).fill(now);

      const petKey = (pet) => String(pet.bookingPetId ?? pet.id ?? "");

      const getPetDuration = (booking, pet) => {
        const petId = String(pet.bookingPetId ?? pet.id ?? "");
        const services = (booking.services || []).filter(
          (s) => String(s.bookingPetId ?? s.booking_pet_id ?? "") === petId,
        );
        const total = services.reduce(
          (sum, s) => sum + (Number(s.durationMinutes) || 60),
          0,
        );
        return total > 0 ? total : 60;
      };

      const earliestSlotIdx = () =>
        slots.reduce((minI, t, i) => (t < slots[minI] ? i : minI), 0);

      const etaMap = {};

      // In-progress pets already occupy a groomer slot — estimate their remaining time
      const sortedInProgress = [...this.inProgressList].sort(
        (a, b) => (a.queueNumber ?? 0) - (b.queueNumber ?? 0),
      );
      for (const booking of sortedInProgress) {
        for (const pet of booking.pets ?? []) {
          if (
            this.isPetReferredToClinic(pet) ||
            !this.isPetGroomingStarted(pet) ||
            this.isPetGroomingFinished(pet)
          ) continue;
          const duration = getPetDuration(booking, pet);
          const startIso = pet.groomingStartedAtIso;
          let estDone;
          if (startIso) {
            const elapsed = (now - new Date(startIso).getTime()) / 60000;
            const remaining = Math.max(5, duration - elapsed);
            estDone = now + remaining * 60000;
          } else {
            estDone = now + duration * 60000;
          }
          const i = earliestSlotIdx();
          slots[i] = estDone;
          etaMap[petKey(pet)] = {
            estStartAt: startIso ? new Date(startIso).getTime() : now,
            estDoneAt: estDone,
          };
        }
      }

      // Waiting pets, including queued siblings in In Progress, fill the next slots.
      const sortedWaiting = [...this.inProgressList, ...this.queuedList]
        .map((booking) => ({
          ...booking,
          pets: this.getWaitingGroomingPets(booking),
        }))
        .filter((booking) => booking.pets.length > 0)
        .sort((a, b) => (a.queueNumber ?? 0) - (b.queueNumber ?? 0));
      for (const booking of sortedWaiting) {
        for (const pet of booking.pets ?? []) {
          const duration = getPetDuration(booking, pet);
          const i = earliestSlotIdx();
          const estStart = slots[i];
          const estDone = estStart + duration * 60000;
          slots[i] = estDone;
          etaMap[petKey(pet)] = { estStartAt: estStart, estDoneAt: estDone };
        }
      }

      return etaMap;
    },

    get petETAs() {
      return this._computeQueueETAs();
    },

    // Summary stats shown in the Queue Overview panel
    get queueSummary() {
      const etaMap = this.petETAs;
      const vals = Object.values(etaMap);
      const now = Date.now();
      const petsWaiting = [...this.inProgressList, ...this.queuedList].reduce(
        (n, b) => n + this.getWaitingGroomingPets(b).length,
        0,
      );

      if (vals.length === 0) {
        return { petsWaiting, avgWaitLabel: "—", lastDoneLabel: null };
      }

      const queuedVals = vals.filter((v) => v.estStartAt >= now);
      const avgMs =
        queuedVals.length > 0
          ? queuedVals.reduce((s, v) => s + (v.estDoneAt - v.estStartAt), 0) /
            queuedVals.length
          : 0;
      const avgMins = Math.round(avgMs / 60000);
      const avgWaitLabel = avgMins > 0 ? `~${avgMins} min` : "—";

      const lastDoneMs = Math.max(...vals.map((v) => v.estDoneAt));
      const lastDoneLabel =
        lastDoneMs > now
          ? new Date(lastDoneMs).toLocaleTimeString("en-US", {
              hour: "numeric",
              minute: "2-digit",
              hour12: true,
            })
          : null;

      return { petsWaiting, avgWaitLabel, lastDoneLabel };
    },

    get isGroomerCapacityFull() {
      return this.activeGroomingPets >= this.activeGroomers;
    },

    // Returns "~3:45 PM" for the last unfinished pet in a booking, or null.
    getBookingETA(booking) {
      const etaMap = this.petETAs;
      let latest = null;
      for (const pet of booking.pets ?? []) {
        if (pet.isGroomingFinished) continue;
        const entry = etaMap[String(pet.bookingPetId ?? pet.id ?? "")];
        if (entry && (!latest || entry.estDoneAt > latest)) {
          latest = entry.estDoneAt;
        }
      }
      if (!latest) return null;
      return new Date(latest).toLocaleTimeString("en-US", {
        hour: "numeric",
        minute: "2-digit",
        hour12: true,
      });
    },

    // Returns elapsed time string for a pet actively being groomed.
    getPetElapsed(pet) {
      const startIso = pet.groomingStartedAtIso;
      if (!startIso) return "";
      const mins = Math.round(
        (Date.now() - new Date(startIso).getTime()) / 60000,
      );
      if (mins <= 0) return "";
      if (mins < 60) return `${mins} min elapsed`;
      const h = Math.floor(mins / 60);
      const m = mins % 60;
      return m > 0 ? `${h}h ${m}m elapsed` : `${h}h elapsed`;
    },

    // Returns "Est. done ~3:45 PM" for a specific in-progress pet.
    getPetEstDone(booking, pet) {
      const etaMap = this.petETAs;
      const entry = etaMap[String(pet.bookingPetId ?? pet.id ?? "")];
      if (!entry || entry.estDoneAt <= Date.now()) return "";
      const t = new Date(entry.estDoneAt).toLocaleTimeString("en-US", {
        hour: "numeric",
        minute: "2-digit",
        hour12: true,
      });
      return `Est. done ~${t}`;
    },

    async setActiveGroomers(n) {
      const nextValue = Math.min(5, Math.max(1, Number(n) || 1));
      if (this.groomerCapacityBusy || nextValue === this.activeGroomers) {
        return;
      }

      this.groomerCapacityBusy = true;
      this.groomerCapacityError = "";

      try {
        const response = await API.adminUpdateGroomersOnDuty(nextValue);
        this.activeGroomers = Number(response?.groomers_on_duty) || nextValue;
        await this.loadAdminBookings();
      } catch (error) {
        this.groomerCapacityError = error.message || "Unable to update groomers on duty.";
      } finally {
        this.groomerCapacityBusy = false;
      }
    },
    // ── END QUEUE ETA ENGINE ─────────────────────────────────────────────────

    // Removes a booking from every visible status list using its id.
    removeBookingFromLists(bookingId) {
      const id = String(bookingId);
      this.incomingList    = this.incomingList.filter((item) => String(item.id) !== id);
      this.queuedList      = this.queuedList.filter((item) => String(item.id) !== id);
      this.inProgressList  = this.inProgressList.filter((item) => String(item.id) !== id);
      this.forPickupList   = this.forPickupList.filter((item) => String(item.id) !== id);
      this.forPaymentList  = this.forPaymentList.filter((item) => String(item.id) !== id);
      this.releasedList    = this.releasedList.filter((item) => String(item.id) !== id);
      this.noShowList      = this.noShowList.filter((item) => String(item.id) !== id);
    },

    rememberLocalCancellation(bookingId) {
      const id = String(bookingId);
      if (!this.localCancelledBookingIds.includes(id)) {
        this.localCancelledBookingIds = [...this.localCancelledBookingIds, id];
      }
    },

    isLocallyCancelled(booking) {
      return this.localCancelledBookingIds.includes(String(booking?.id));
    },

    filterLocalCancelledBookings(list) {
      return Array.isArray(list)
        ? list.filter((booking) => !this.isLocallyCancelled(booking))
        : [];
    },

    rememberLocalRevertToIncoming(booking) {
      const id = String(booking?.id);
      const nextRevertedBookings = this.localRevertedToIncomingBookings.filter(
        (item) => String(item.id) !== id,
      );

      this.localRevertedToIncomingBookings = [
        ...nextRevertedBookings,
        this.cloneBooking(booking),
      ];
      this.localRevertedToQueuedBookings = this.localRevertedToQueuedBookings.filter(
        (item) => String(item.id) !== id,
      );
    },

    rememberLocalRevertToQueued(booking) {
      const id = String(booking?.id);
      const nextRevertedBookings = this.localRevertedToQueuedBookings.filter(
        (item) => String(item.id) !== id,
      );

      this.localRevertedToQueuedBookings = [
        ...nextRevertedBookings,
        this.cloneBooking(booking),
      ];
      this.localRevertedToIncomingBookings = this.localRevertedToIncomingBookings.filter(
        (item) => String(item.id) !== id,
      );
    },

    applyLocalRevertedBookings() {
      const incomingRevertedBookings = this.localRevertedToIncomingBookings.filter(
        (booking) => !this.isLocallyCancelled(booking),
      );
      const queuedRevertedBookings = this.localRevertedToQueuedBookings.filter(
        (booking) => !this.isLocallyCancelled(booking),
      );
      const revertedBookings = [
        ...incomingRevertedBookings,
        ...queuedRevertedBookings,
      ];

      if (revertedBookings.length === 0) {
        return;
      }

      const revertedIds = new Set(
        revertedBookings.map((booking) => String(booking.id)),
      );

      this.incomingList = this.incomingList.filter(
        (booking) => !revertedIds.has(String(booking.id)),
      );
      this.queuedList = this.queuedList.filter(
        (booking) => !revertedIds.has(String(booking.id)),
      );
      this.inProgressList = this.inProgressList.filter(
        (booking) => !revertedIds.has(String(booking.id)),
      );
      this.forPickupList = this.forPickupList.filter(
        (booking) => !revertedIds.has(String(booking.id)),
      );
      this.forPaymentList = this.forPaymentList.filter(
        (booking) => !revertedIds.has(String(booking.id)),
      );
      this.releasedList = this.releasedList.filter(
        (booking) => !revertedIds.has(String(booking.id)),
      );
      this.noShowList = this.noShowList.filter(
        (booking) => !revertedIds.has(String(booking.id)),
      );

      for (const booking of incomingRevertedBookings) {
        this.incomingList = this.insertBookingSorted(
          this.incomingList,
          this.normalizeBooking(booking, "incoming"),
        );
      }

      for (const booking of queuedRevertedBookings) {
        this.queuedList = this.insertBookingSorted(
          this.queuedList,
          this.normalizeBooking(booking, "queued"),
        );
      }
    },

    // Maps a frontend action name to the next booking status.
    getNextStatusForAction(actionName, currentStatus) {
      const normalizedCurrentStatus = this.normalizeStatus(currentStatus);
      const actionStatusMap = {
        checkIn: "queued",
        startGrooming: "in-progress",
        markDone: "for-payment",
        cancel: "cancelled",
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

    isQueuedBookingExpanded(bookingId) {
      return Boolean(this.expandedQueuedBookingIds[String(bookingId)]);
    },

    setQueuedBookingExpanded(bookingId, expanded) {
      this.expandedQueuedBookingIds = {
        ...this.expandedQueuedBookingIds,
        [String(bookingId)]: Boolean(expanded),
      };
      this.$nextTick(() => this.refreshIcons());
    },

    toggleQueuedBooking(bookingId) {
      this.setQueuedBookingExpanded(
        bookingId,
        !this.isQueuedBookingExpanded(bookingId),
      );
    },

    isInProgressBookingExpanded(bookingId) {
      return Boolean(this.expandedInProgressBookingIds[String(bookingId)]);
    },

    setInProgressBookingExpanded(bookingId, expanded) {
      this.expandedInProgressBookingIds = {
        ...this.expandedInProgressBookingIds,
        [String(bookingId)]: Boolean(expanded),
      };
      this.$nextTick(() => this.refreshIcons());
    },

    toggleInProgressBooking(bookingId) {
      this.setInProgressBookingExpanded(
        bookingId,
        !this.isInProgressBookingExpanded(bookingId),
      );
    },

    // Provides the temporary button label shown while an action is in progress.
    getActionLabel(actionName) {
      const labelMap = {
        checkIn: "Checking in...",
        startGrooming: "Grooming...",
        startPetGrooming: "Starting...",
        markDone: "Finishing...",
        markPetDone: "Finishing...",
        cancel: "Cancelling...",
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
        revenueToday: this.revenueToday,
        revenuePaymentCount: this.revenuePaymentCount,
        noShowWeekCount: this.noShowWeekCount,
        noShowWeekRate: this.noShowWeekRate,
        currentCapacity: this.currentCapacity,
        maxCapacity: this.maxCapacity,
        activeGroomers: this.activeGroomers,
        activeGroomingPets: this.activeGroomingPets,
        notificationCount: this.notificationCount,
        recentActivity: [...this.recentActivity],
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
        "recentActivity" in value ||
        "recent_activity" in value ||
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

    formatDashboardCurrency(amount) {
      return `\u20b1${Number(amount || 0).toLocaleString("en-PH", {
        maximumFractionDigits: 0,
      })}`;
    },

    formatServiceAvailedPrice(service, booking = this.detailsBooking) {
      const bookedAmount = parsePaymentNumber(
        service?.priceAtBooking ?? service?.price_at_booking,
      );

      if (Number.isFinite(bookedAmount) && bookedAmount > 0) {
        return this.formatPeso(bookedAmount);
      }

      const serviceDefinition = getPaymentServiceDefinition(service);
      if (!serviceDefinition) {
        return "Price unavailable";
      }

      const petSize = this.getServiceAvailedPetSize(service, booking);
      const pricing = getPaymentServicePricing(serviceDefinition, petSize);

      return pricing.displayPrice === "Enter price"
        ? "Price unavailable"
        : pricing.displayPrice;
    },

    getServiceAvailedAmount(service, booking = this.detailsBooking, pet = null) {
      const amount = parsePaymentNumber(
        service?.priceAtBooking ??
          service?.price_at_booking ??
          service?.paidPrice ??
          service?.paid_price ??
          service?.finalPrice ??
          service?.final_price,
      );

      if (Number.isFinite(amount) && amount > 0) {
        return amount;
      }

      const serviceDefinition = getPaymentServiceDefinition(service);
      if (!serviceDefinition) {
        return null;
      }

      const petSize =
        getPaymentPetSizeCandidate(pet) ||
        this.getServiceAvailedPetSize(service, booking);
      const pricing = getPaymentServicePricing(serviceDefinition, petSize);

      return pricing.displayPrice === "Enter price" ? null : pricing.minAmount;
    },

    getServicesAvailedTotal(booking = this.detailsBooking) {
      const paymentTotal = parsePaymentNumber(
        booking?.paidAmount ??
          booking?.paid_amount ??
          booking?.payment?.finalPrice ??
          booking?.payment?.final_price,
      );

      if (Number.isFinite(paymentTotal) && paymentTotal > 0) {
        return paymentTotal;
      }

      const services = Array.isArray(booking?.services) ? booking.services : [];
      if (services.length === 0) {
        return null;
      }

      const serviceAmounts = services.map((service) =>
        this.getServiceAvailedAmount(service, booking),
      );

      if (serviceAmounts.some((amount) => !Number.isFinite(amount) || amount <= 0)) {
        return null;
      }

      return serviceAmounts.reduce((total, amount) => total + amount, 0);
    },

    formatServicesAvailedTotal(booking = this.detailsBooking) {
      const total = this.getServicesAvailedTotal(booking);

      return Number.isFinite(total) && total > 0
        ? this.formatPeso(total)
        : "Price unavailable";
    },

    getDetailsPaymentPet(pet, booking = this.detailsBooking, pets = this.normalizePaymentPets(booking)) {
      if (pets.length === 0) {
        return null;
      }

      const bookingPetId = pet?.bookingPetId ?? pet?.booking_pet_id;
      if (bookingPetId) {
        const matchedPet = pets.find((candidate) =>
          [candidate.bookingPetId, candidate.id].some((value) =>
            value !== null &&
            value !== undefined &&
            String(value) === String(bookingPetId),
          ),
        );

        if (matchedPet) {
          return matchedPet;
        }
      }

      const petId = pet?.petId ?? pet?.pet_id ?? pet?.id;
      if (petId) {
        const matchedPet = pets.find((candidate) =>
          [candidate.petId, candidate.id].some((value) =>
            value !== null &&
            value !== undefined &&
            String(value) === String(petId),
          ),
        );

        if (matchedPet) {
          return matchedPet;
        }
      }

      const petName = pet?.petName ?? pet?.pet_name ?? pet?.name;
      if (petName) {
        const normalizedPetName = normalizePaymentText(petName);
        const matchedPet = pets.find((candidate) =>
          normalizePaymentText(candidate.name) === normalizedPetName,
        );

        if (matchedPet) {
          return matchedPet;
        }
      }

      return pets.length === 1 ? pets[0] : null;
    },

    getPetServicesAvailed(pet, booking = this.detailsBooking) {
      const pets = this.normalizePaymentPets(booking);
      const normalizedPet = this.getDetailsPaymentPet(pet, booking, pets);
      if (!normalizedPet) {
        return [];
      }

      const services = this.normalizePaymentServices(booking?.services);
      return this.getPaymentServicesForPet(
        booking,
        normalizedPet,
        pets,
        services,
      );
    },

    // Hides clinic-referred pets from the active grooming card, then presents
    // the remaining records as dogs first, cats second, and others afterward.
    getQueuedPets(booking) {
      const pets = Array.isArray(booking?.pets) ? booking.pets : [];
      const speciesRank = (pet) => {
        const species = String(
          pet?.species ?? pet?.petType ?? pet?.pet_type ?? "",
        ).trim().toLowerCase();

        if (species === "dog" || species === "dogs") return 0;
        if (species === "cat" || species === "cats") return 1;
        return 2;
      };

      return pets
        .filter((pet) => !this.isPetReferredToClinic(pet))
        .map((pet, originalIndex) => ({ pet, originalIndex }))
        .sort((left, right) =>
          speciesRank(left.pet) - speciesRank(right.pet) ||
          left.originalIndex - right.originalIndex,
        )
        .map(({ pet }) => pet);
    },

    // In Progress mirrors the Queued card and retains finished pets as disabled
    // indicators until every pet in the owner booking is complete.
    getInProgressPets(booking) {
      return this.getQueuedPets(booking);
    },

    shouldShowOwnerReschedule(booking) {
      return Boolean(booking?.paid);
    },

    formatPetQueueNumber(pet, petIndex = 0) {
      const queueNumber = Number(pet?.petQueueNumber ?? petIndex + 1);
      return `#P${Number.isFinite(queueNumber) && queueNumber > 0 ? queueNumber : petIndex + 1}`;
    },

    formatPetCardServiceLabel(pet, booking) {
      const names = this.getPetServicesAvailed(pet, booking)
        .map((service) => service?.name ?? service?.serviceName ?? service?.service_name)
        .filter(Boolean);

      return names.length > 0 ? names.join(", ") : "No selected services recorded";
    },

    formatPetCardValue(value, fallback = "Not provided") {
      const text = String(value ?? "").trim();
      if (!text || text === "—") {
        return fallback;
      }

      return text
        .replace(/_/g, " ")
        .replace(/\b\w/g, (character) => character.toUpperCase());
    },

    escapePrintHtml(value) {
      const element = document.createElement("span");
      element.textContent = String(value ?? "");
      return element.innerHTML;
    },

    printPetCard(booking, pet, petIndex = 0) {
      const services = this.getPetServicesAvailed(pet, booking);
      const serviceItems = services.length > 0
        ? services.map((service) => {
            const serviceName = service?.name ?? service?.serviceName ?? service?.service_name ?? "Grooming service";
            return `<li>${this.escapePrintHtml(serviceName)}</li>`;
          }).join("")
        : "<li>No selected services recorded</li>";

      const petName = pet?.petName ?? pet?.pet_name ?? pet?.name ?? "Pet";
      const groomingInstructions =
        pet?.specialInstructions ??
        pet?.special_instructions ??
        booking?.specialNotes ??
        "None provided";
      const medicalInformation =
        pet?.medicalConditions ??
        pet?.medical_conditions ??
        "None provided";
      const contactNumber = this.toStringValue(booking?.contactNumber).trim();
      const formattedContactNumber = contactNumber
        ? this.formatMobileNumber(contactNumber)
        : "Not provided";
      const printRoot = document.createElement("section");
      const previousTitle = document.title;
      let cleanupTimer = null;

      printRoot.className = "pet-grooming-print-clone";
      printRoot.innerHTML = `
        <header class="pet-grooming-print-header">
          <div>
            <p class="pet-grooming-print-clinic">Bethlehem Animal Clinic</p>
            <p class="pet-grooming-print-subtitle">Individual Pet Grooming Card</p>
          </div>
          <strong class="pet-grooming-print-queue">${this.escapePrintHtml(this.formatPetQueueNumber(pet, petIndex))}</strong>
        </header>
        <section class="pet-grooming-print-section">
          <h1>${this.escapePrintHtml(petName)}</h1>
          <div class="pet-grooming-print-grid">
            <p><span>Owner</span>${this.escapePrintHtml(booking?.ownerName || "Not provided")}</p>
            <p><span>Phone number</span>${this.escapePrintHtml(formattedContactNumber)}</p>
            <p><span>Species</span>${this.escapePrintHtml(this.formatPetCardValue(pet?.species ?? pet?.petType ?? pet?.pet_type))}</p>
            <p><span>Breed</span>${this.escapePrintHtml(this.formatPetCardValue(pet?.breed))}</p>
            <p><span>Size</span>${this.escapePrintHtml(this.formatPetCardValue(pet?.size ?? pet?.petSize ?? pet?.pet_size))}</p>
            <p><span>Fur type</span>${this.escapePrintHtml(this.formatPetCardValue(pet?.furType ?? pet?.fur_type))}</p>
            <p><span>Weight</span>${this.escapePrintHtml(this.formatPetCardValue(pet?.weight))}</p>
            <p><span>Dropped off</span>${this.escapePrintHtml(booking?.dropOffTime || "Not recorded")}</p>
          </div>
        </section>
        <section class="pet-grooming-print-section">
          <h2>Selected services</h2>
          <ul class="pet-grooming-print-services">${serviceItems}</ul>
        </section>
        <section class="pet-grooming-print-section">
          <h2>Grooming instructions</h2>
          <p class="pet-grooming-print-notes">${this.escapePrintHtml(groomingInstructions)}</p>
        </section>
        <section class="pet-grooming-print-section pet-grooming-print-medical">
          <h2>Medical information</h2>
          <p class="pet-grooming-print-notes">${this.escapePrintHtml(medicalInformation)}</p>
        </section>
      `;

      const cleanup = () => {
        printRoot.remove();
        document.body.classList.remove("pet-grooming-card-printing");
        document.title = previousTitle;
        window.removeEventListener("afterprint", cleanup);
        if (cleanupTimer) {
          window.clearTimeout(cleanupTimer);
          cleanupTimer = null;
        }
      };

      document.body.appendChild(printRoot);
      document.body.classList.add("pet-grooming-card-printing");
      document.title = `${this.formatPetQueueNumber(pet, petIndex)} ${petName}`;
      window.addEventListener("afterprint", cleanup);
      cleanupTimer = window.setTimeout(cleanup, 60000);
      window.print();
    },

    getPetServicesAvailedTotal(pet, booking = this.detailsBooking) {
      const normalizedPet = this.getDetailsPaymentPet(pet, booking);
      const petServices = this.getPetServicesAvailed(pet, booking);

      if (!normalizedPet || petServices.length === 0) {
        return null;
      }

      const serviceAmounts = petServices.map((service) =>
        this.getServiceAvailedAmount(service, booking, normalizedPet),
      );

      if (serviceAmounts.some((amount) => !Number.isFinite(amount) || amount <= 0)) {
        return null;
      }

      return serviceAmounts.reduce((total, amount) => total + amount, 0);
    },

    formatPetServicesAvailedTotal(pet, booking = this.detailsBooking) {
      const total = this.getPetServicesAvailedTotal(pet, booking);

      return Number.isFinite(total) && total > 0
        ? this.formatPeso(total)
        : "Price unavailable";
    },

    getServiceAvailedPetSize(service, booking = this.detailsBooking) {
      const serviceSize = getPaymentPetSizeCandidate(service);

      if (serviceSize) {
        return serviceSize;
      }

      const matchedPet = this.getServiceAvailedPet(service, booking);

      return (
        getPaymentPetSizeCandidate(matchedPet) ||
        getPaymentPetSizeCandidate(booking)
      );
    },

    getServiceAvailedPet(service, booking = this.detailsBooking) {
      const pets = Array.isArray(booking?.pets) ? booking.pets : [];

      if (pets.length === 0) {
        return null;
      }

      const bookingPetId = service?.bookingPetId ?? service?.booking_pet_id;
      if (bookingPetId) {
        const matchedPet = pets.find((pet) =>
          String(pet?.bookingPetId ?? pet?.booking_pet_id ?? pet?.id) ===
          String(bookingPetId),
        );

        if (matchedPet) {
          return matchedPet;
        }
      }

      const petId = service?.petId ?? service?.pet_id;
      if (petId) {
        const matchedPet = pets.find((pet) =>
          String(pet?.petId ?? pet?.pet_id) === String(petId),
        );

        if (matchedPet) {
          return matchedPet;
        }
      }

      const petName = service?.petName ?? service?.pet_name;
      if (petName) {
        const normalizedPetName = normalizePaymentText(petName);
        const matchedPet = pets.find((pet) =>
          normalizePaymentText(pet?.petName ?? pet?.pet_name ?? pet?.name) ===
          normalizedPetName,
        );

        if (matchedPet) {
          return matchedPet;
        }
      }

      return pets.length === 1 ? pets[0] : null;
    },

    formatPercent(value) {
      return `${Number(value || 0).toFixed(1)}%`;
    },

    normalizeRecentActivity(activity) {
      return (Array.isArray(activity) ? activity : [])
        .map((item, index) => ({
          id: item?.id ?? `activity-${index}`,
          type: this.toStringValue(item?.type || "activity"),
          title: this.toStringValue(item?.title || "Activity"),
          subtitle: this.toStringValue(item?.subtitle || item?.message),
          createdAt: this.toStringValue(item?.createdAt ?? item?.created_at ?? item?.time),
          timeLabel: this.toStringValue(item?.timeLabel ?? item?.time_label),
        }))
        .filter((item) => item.title);
    },

    todayQueuePreview() {
      const today = this.localToday();
      const visibleBookings = [...this.inProgressList, ...this.queuedList]
        .filter((booking, index, bookings) =>
          bookings.findIndex((candidate) => String(candidate.id) === String(booking.id)) === index,
        );

      return visibleBookings
        .filter((booking) => booking.appointmentDate === today)
        .sort((left, right) => {
          const statusOrder = { "in-progress": 0, queued: 1 };
          const leftStatus = statusOrder[left.status] ?? 2;
          const rightStatus = statusOrder[right.status] ?? 2;
          if (leftStatus !== rightStatus) return leftStatus - rightStatus;
          return String(left.appointmentTime || "").localeCompare(String(right.appointmentTime || ""));
        })
        .slice(0, 5);
    },

    queuePreviewStatusLabel(status) {
      return this.normalizeStatus(status) === "in-progress" ? "In progress" : "Queued";
    },

    queuePreviewBadgeClass(status) {
      return this.normalizeStatus(status) === "in-progress"
        ? "bg-sky-100 text-sky-700"
        : "bg-amber-100 text-amber-700";
    },

    queuePreviewAccentClass(status) {
      return this.normalizeStatus(status) === "in-progress"
        ? "bg-sky-100 text-sky-700"
        : "bg-amber-100 text-amber-700";
    },

    queuePreviewDetail(booking) {
      const action = this.normalizeStatus(booking?.status) === "in-progress"
        ? "Grooming"
        : "Checkup";
      return `${action} - ${booking?.appointmentTime || "Time pending"}`;
    },

    activityDotClass(type) {
      const normalizedType = this.toStringValue(type);
      if (normalizedType === "completed") return "admin-activity-dot-pickup";
      if (normalizedType === "in_progress") return "bg-sky-600";
      if (normalizedType === "queued" || normalizedType === "check_in") return "bg-amber-700";
      if (normalizedType === "payment") return "bg-green-600";
      return "bg-slate-400";
    },

    activityTimeLabel(activity) {
      if (activity?.timeLabel) return activity.timeLabel;
      if (!activity?.createdAt) return "";

      const date = new Date(activity.createdAt);
      if (Number.isNaN(date.getTime())) return "";

      return date.toLocaleTimeString("en-PH", {
        hour: "numeric",
        minute: "2-digit",
      });
    },

    // Converts nullable values into template-safe strings.
    toStringValue(value) {
      return value === undefined || value === null ? "" : String(value);
    },

    // Derive the card label from the actual pets instead of trusting a first-pet summary.
    formatBookingPetTypes(booking) {
      const pets = Array.isArray(booking?.pets) ? booking.pets : [];
      if (pets.length === 0) {
        return this.toStringValue(booking?.petType);
      }

      const typeCounts = pets.reduce((counts, pet) => {
        const type = this.toStringValue(
          pet?.species ?? pet?.petType ?? pet?.pet_type,
        ).trim().toLowerCase();

        if (type) {
          counts.set(type, (counts.get(type) || 0) + 1);
        }

        return counts;
      }, new Map());

      if (typeCounts.size === 0) {
        return this.toStringValue(booking?.petType);
      }

      const supportedTypes = ["dog", "cat"].filter((type) => typeCounts.has(type));
      const otherTypes = Array.from(typeCounts.keys()).filter(
        (type) => !supportedTypes.includes(type),
      );
      const labels = [...supportedTypes, ...otherTypes].map((type) => {
        const label = type.charAt(0).toUpperCase() + type.slice(1);
        return typeCounts.get(type) > 1 ? `${label}s` : label;
      });

      if (labels.length === 1) return labels[0];
      if (labels.length === 2) return `${labels[0]} and ${labels[1]}`;

      return `${labels.slice(0, -1).join(", ")}, and ${labels.at(-1)}`;
    },

    formatMobileNumber(value) {
      const text = this.toStringValue(value).trim();
      if (!text) return "—";

      const digits = text.replace(/\D/g, "");
      if (digits.length === 11) {
        return `${digits.slice(0, 4)}-${digits.slice(4, 7)}-${digits.slice(7)}`;
      }

      return text;
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
      const today = window.AppClock?.todayKey?.();
      if (today) return today;

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
      const tomorrow = window.AppClock?.dateKeyWithOffset?.(1) || (() => {
        const tomorrowDate = new Date();
        tomorrowDate.setDate(tomorrowDate.getDate() + 1);
        return `${tomorrowDate.getFullYear()}-${String(tomorrowDate.getMonth() + 1).padStart(2, "0")}-${String(tomorrowDate.getDate()).padStart(2, "0")}`;
      })();

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

    bookingHasPayNowBlockedPet(booking) {
      return (booking?.pets || []).some((pet) =>
        ["paused", "stopped"].includes(pet?.groomingState ?? pet?.grooming_state),
      );
    },

    get paymentTotalDue() {
      return this.paymentModal.petBreakdown.reduce(
        (sum, pet) => sum + this.paymentPetSubtotal(pet),
        0,
      );
    },

    get paymentLineCount() {
      return this.paymentModal.petBreakdown.reduce(
        (count, pet) => count + (pet.isStoppedReviewed ? 0 : (Array.isArray(pet.lines) ? pet.lines.length : 0)),
        0,
      );
    },

    get paymentMaximumAmount() {
      return maximumPaymentAmount(this.paymentTotalDue);
    },

    get paymentChange() {
      const paid = parseFloat(this.paymentModal.amountPaid) || 0;
      return paid - this.paymentTotalDue;
    },

    get canSubmitPayment() {
      const paid = parseFloat(this.paymentModal.amountPaid) || 0;
      const isZeroTotal = !this.paymentModal.isEarlyPayment && this.paymentTotalDue === 0;

      return (
        !this.paymentModal.busy &&
        this.paymentModal.petBreakdown.length > 0 &&
        (isZeroTotal || this.paymentTotalDue > 0) &&
        !this.hasMissingPaymentPrices() &&
        !this.getInvalidPaymentLine() &&
        (isZeroTotal || this.paymentChange >= 0) &&
        (isZeroTotal || paid <= this.paymentMaximumAmount)
      );
    },

    openPaymentModal(booking, isEarlyPayment = false) {
      if (!isEarlyPayment && booking?.paymentReady === false) {
        alert(booking?.paymentBlockedReason || "This booking is not ready for final payment.");
        return;
      }

      const petBreakdown = this.buildPaymentBreakdown(booking, { lockFixedPrices: true });
      const zeroTotal = !isEarlyPayment
        && petBreakdown.length > 0
        && petBreakdown.reduce((sum, pet) => sum + this.paymentPetSubtotal(pet), 0) === 0;
      this.paymentModal = {
        open: true,
        booking,
        isEarlyPayment,
        finalPrice: "",
        petBreakdown,
        amountPaid: zeroTotal ? "0.00" : "",
        paymentMethod: zeroTotal ? "others" : "cash",
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

    buildPaymentBreakdown(booking, paymentOptions = {}) {
      const pets = this.normalizePaymentPets(booking);
      const services = this.normalizePaymentServices(booking?.services);
      const serverSummary = booking?.paymentSummary ?? booking?.payment_summary ?? {};
      const serverPets = new Map(
        (serverSummary?.pets || []).map((pet) => [String(pet.booking_pet_id), pet]),
      );

      /*
       * Backend handoff:
       * Multi-pet payment cards need either pets[].services or services[] items
       * that include bookingPetId/booking_pet_id. Service slug fields are also
       * preferred so the frontend can show the exact minimum price rule.
       */
      return pets.map((pet, petIndex) => {
        const serverPet = serverPets.get(String(pet.bookingPetId)) || null;
        if (serverPet?.payment_kind === "stopped_reviewed") {
          return {
            ...pet,
            isStoppedReviewed: true,
            groomingState: "stopped",
            lines: (serverPet.service_breakdown || []).map((line, lineIndex) => ({
              id: line.booking_service_id ?? `stopped-${petIndex}-${lineIndex}`,
              bookingServiceId: line.booking_service_id,
              name: line.label || "Grooming Service",
              description: line.line_type === "add_on" ? "Saved add-on price" : "Saved original service price",
              amount: Number(line.price_at_booking || 0).toFixed(2),
              isFixedPriceLocked: true,
            })),
            originalSubtotal: Number(serverPet.review_original_pet_subtotal ?? serverPet.original_pet_subtotal ?? 0),
            finalReviewedCharge: Number(serverPet.final_pet_charge || 0),
            adjustment: Number(serverPet.adjustment || 0),
            reviewDecision: serverPet.review_decision,
            reviewDecisionLabel: serverPet.review_decision_label,
            customerExplanation: serverPet.customer_explanation || "",
            reviewedAt: serverPet.reviewed_at || null,
          };
        }

        const petServices = this.getPaymentServicesForPet(booking, pet, pets, services);
        const inferredSizeKey = inferPaymentSizeFromServices(petServices, pet.petTypeKey);
        const pricedPet = inferredSizeKey
          ? {
              ...pet,
              sizeKey: inferredSizeKey,
              sizeLabel: formatPaymentSizeLabel(inferredSizeKey),
            }
          : pet;
        const lines = petServices.map((service, serviceIndex) =>
          this.normalizePaymentLine(service, pricedPet, `${petIndex}-${serviceIndex}`, paymentOptions),
        );

        return {
          ...pricedPet,
          isStoppedReviewed: false,
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
      const bookingSizeCandidate = sourcePets.length === 1
        ? getPaymentPetSizeCandidate(booking)
        : "";

      return sourcePets.map((pet, index) => {
        const petTypeKey = normalizePaymentPetType(
          pet?.species ?? pet?.petType ?? pet?.pet_type,
        );
        const sizeCandidate = getPaymentPetSizeCandidate(pet) || bookingSizeCandidate;
        const sizeKey = normalizePaymentSizeForPet(
          sizeCandidate,
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
          groomingState: pet?.groomingState ?? pet?.grooming_state ?? "not_started",
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

    normalizePaymentLine(rawService, pet, fallbackId, paymentOptions = {}) {
      const serviceDefinition = getPaymentServiceDefinition(rawService);
      const fallbackAmount = parseFloat(rawService?.priceAtBooking ?? rawService?.price_at_booking ?? 0);
      const pricing = serviceDefinition
        ? getPaymentServicePricing(serviceDefinition, pet.sizeKey)
        : fallbackAmount > 0
          ? {
              minAmount: fallbackAmount,
              displayPrice: formatPaymentAmount(fallbackAmount),
              placeholder: Number(fallbackAmount).toLocaleString("en-PH"),
              pricingType: "fixed",
              selectedPriceOption: null,
            }
          : getPaymentServicePricing(null, pet.sizeKey);
      const pricingType = pricing.pricingType || "custom";
      const lockFixedPrices = Boolean(paymentOptions.lockFixedPrices);
      const isFixedPriceLocked = shouldLockPaymentPrice(pricing, lockFixedPrices);

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
        pricingType,
        lockFixedPrices,
        isFixedPriceLocked,
        amount: isFixedPriceLocked ? Number(pricing.minAmount).toFixed(2) : "",
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
      const wasFixedPriceLocked = Boolean(line.isFixedPriceLocked);
      const pricing = getPaymentServicePricing(line.serviceDefinition, pet.sizeKey);
      const pricingType = pricing.pricingType || "custom";
      line.minAmount = pricing.minAmount;
      line.priceHint = pricing.displayPrice;
      line.placeholder = pricing.placeholder;
      line.pricingType = pricingType;
      line.isFixedPriceLocked = shouldLockPaymentPrice(pricing, line.lockFixedPrices);

      if (line.isFixedPriceLocked) {
        line.amount = Number(pricing.minAmount).toFixed(2);
      } else if (wasFixedPriceLocked) {
        line.amount = "";
      }
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
      if (pet?.isStoppedReviewed) {
        return Number(pet.finalReviewedCharge || 0);
      }

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

    enforcePaymentAmountLimit(event = null) {
      const currentValue = event?.target?.value ?? this.paymentModal.amountPaid;
      const amount = parseFloat(currentValue);
      const maximum = this.paymentMaximumAmount;

      if (Number.isFinite(amount) && maximum > 0 && amount > maximum) {
        const maximumValue = String(maximum);
        this.paymentModal.amountPaid = maximumValue;

        if (event?.target) {
          event.target.value = maximumValue;
        }
      }

      if (event) {
        this.clearPaymentError();
      }
    },

    hasMissingPaymentPrices() {
      return this.paymentModal.petBreakdown.some((pet) =>
        !pet.isStoppedReviewed && (pet.lines || []).some((line) => line.amount === "" || line.amount === null || line.amount === undefined),
      );
    },

    getInvalidPaymentLine() {
      for (const pet of this.paymentModal.petBreakdown) {
        if (pet.isStoppedReviewed) continue;
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

    buildPaymentServicePrices(petBreakdown) {
      const pets = Array.isArray(petBreakdown) ? petBreakdown : [];
      const servicePrices = [];

      pets.forEach((pet) => {
        if (pet?.isStoppedReviewed) return;
        (Array.isArray(pet?.lines) ? pet.lines : []).forEach((line) => {
          const bookingServiceId =
            line?.bookingServiceId ?? line?.booking_service_id ?? null;
          const amount = parseFloat(line?.amount);

          if (!bookingServiceId || !Number.isFinite(amount)) {
            return;
          }

          servicePrices.push({
            booking_service_id: bookingServiceId,
            amount: Number(amount.toFixed(2)),
          });
        });
      });

      return servicePrices;
    },

    buildServerPaymentReceiptPets(summary, fallbackPets = []) {
      if (!Array.isArray(summary?.pets)) {
        return this.buildPaymentReceiptPets(fallbackPets);
      }

      return summary.pets.map((pet, index) => ({
        id: pet.booking_pet_id ?? `receipt-pet-${index + 1}`,
        name: this.formatReceiptValue(pet.pet_name, `Pet ${index + 1}`),
        species: this.formatReceiptValue(pet.pet_species),
        breed: "Not specified",
        sizeLabel: "Not specified",
        stoppedReviewed: pet.payment_kind === "stopped_reviewed",
        groomingStateLabel: pet.grooming_state_label,
        reviewDecisionLabel: pet.review_decision_label,
        originalSubtotal: Number(pet.review_original_pet_subtotal ?? pet.original_pet_subtotal ?? 0),
        adjustment: Number(pet.adjustment || 0),
        customerExplanation: pet.customer_explanation || "",
        lines: (pet.service_breakdown || []).map((line, lineIndex) => ({
          id: line.booking_service_id ?? `${index}-${lineIndex}`,
          name: this.formatReceiptValue(line.label, "Grooming Service"),
          price: Number(line.price_at_booking || 0),
        })),
        subtotal: Number(pet.final_pet_charge || 0),
      }));
    },

    async submitPayment() {
      const { booking, isEarlyPayment, amountPaid, notes } = this.paymentModal;
      const fp = Number(this.paymentTotalDue.toFixed(2));
      const isZeroTotal = !isEarlyPayment && fp === 0;
      const ap = isZeroTotal ? 0 : parseFloat(amountPaid);
      const paymentMethod = this.paymentModal.paymentMethod || "cash";

      if (this.paymentModal.petBreakdown.length === 0) {
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

      if (isEarlyPayment && (!fp || fp <= 0)) {
        this.paymentModal.error = "Please enter service prices before confirming payment.";
        return;
      }
      if (!isZeroTotal && (!ap || ap < fp)) {
        this.paymentModal.error = "Amount paid cannot be less than the total amount due.";
        return;
      }
      if (!isZeroTotal && ap > this.paymentMaximumAmount) {
        this.paymentModal.error = `Amount paid cannot exceed ${this.formatPeso(this.paymentMaximumAmount)}.`;
        return;
      }

      const servicePrices = this.buildPaymentServicePrices(this.paymentModal.petBreakdown);
      if (servicePrices.length !== this.paymentLineCount) {
        this.paymentModal.error = "Service price records are incomplete. Please refresh and try again.";
        return;
      }

      this.paymentModal.busy  = true;
      this.paymentModal.error = "";

      try {
        const payload = {
          final_price:     fp,
          amount_paid:     ap,
          payment_method:  paymentMethod,
          notes:           notes || null,
          service_prices:  servicePrices,
        };
        const res = isEarlyPayment
          ? await API.payNow(booking.id, payload)
          : await API.processPayment(booking.id, payload);

        const receiptPets = this.buildServerPaymentReceiptPets(
          res?.payment_summary,
          this.paymentModal.petBreakdown,
        );
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
          finalPrice:    Number(res.final_price ?? fp),
          amountPaid:    Number(res.amount_paid ?? ap),
          change:        res.change ?? (ap - fp),
          paymentMethod: res.payment_method_label ?? res.payment_method ?? paymentMethod,
          paidAt:        res.paid_at ?? new Date().toLocaleString("en-PH"),
          isEarlyPayment,
        };
        this.$nextTick(() => { if (window.lucide) lucide.createIcons(); });
        await this.loadAdminBookings();
        await this.loadNotifications();
        if (!isEarlyPayment && res?.booking_status === "released") {
          this.setTab("to-be-picked-up");
        }
        if (isEarlyPayment && res?.all_pets_finished) {
          this.setTab("to-be-picked-up");
        }
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
        const data = await API.getAdminBookings(this.selectedDate, {
          includeFuture: this.showFutureAppointments,
        });
        this._resetPollFailures("_bookingInterval");
        this.applyDashboardData(data);
        await this.refreshMedicalConcernIndicators();
        await this.refreshStoppedPaymentReviewStatuses();
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
        this.noShowList = this.filterLocalCancelledBookings(
          (data.noShowList || []).map((b) => this.normalizeBooking(b, "no_show")),
        );
        this.applyLocalRevertedBookings();
      } catch (error) {
        this._stopPollOnFailure("_clinicInterval", "loadNoShows", error);
      }
    },

    async lateCheckInBooking(booking) {
      await this.runBookingAction("lateCheckIn", booking);
    },

    // Returns true when late check-in is still allowed (before 5 PM and clinic not stopped).
    isLateCheckInAvailable() {
      const currentMinutes = window.AppClock?.currentMinutes?.() ?? (new Date().getHours() * 60);
      return currentMinutes < 17 * 60 && !this.clinicStopped;
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
