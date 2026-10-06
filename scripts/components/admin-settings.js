function adminSettings() {
  const currentRole = typeof API !== "undefined" && typeof API.getUserRole === "function"
    ? API.getUserRole()
    : "admin";
  const defaultAvailability = {
    clinic: {
      open_time: "08:00",
      close_time: "17:00",
      pre_registration_cutoff_time: "14:00",
    },
    grooming: {
      open_time: "08:00",
      close_time: "17:00",
      pre_registration_cutoff_time: "14:00",
    },
  };

  const clone = (value) => JSON.parse(JSON.stringify(value));

  return {
    isAdmin: currentRole === "admin",
    isStaff: currentRole === "staff",
    activeSettingsTab: "general",
    groomingPrices: [],
    groomingPriceLimits: null,
    pricingLoading: false,
    pricingError: "",
    pricingModal: { open: false, service: null, rows: [], savedState: "", defaultRows: [], busy: false, errors: {}, pristineFields: {}, error: "" },

    async loadGroomingPrices() {
      if (!this.isAdmin) return;
      this.pricingLoading = true;
      this.pricingError = "";
      try {
        const response = await API.getGroomingCatalogue();
        if (!response.priceLimits?.package || !response.priceLimits?.ala_carte) throw new Error("Grooming price limits are unavailable.");
        this.groomingPrices = response.data;
        this.groomingPriceLimits = response.priceLimits;
      } catch (error) {
        this.pricingError = "Unable to load grooming pricing. Please retry.";
      } finally { this.pricingLoading = false; }
    },

    pricingAmount(amount) {
      return `₱${Number(amount).toLocaleString("en-PH", { maximumFractionDigits: 2 })}`;
    },

    pricingDisplay(option) {
      const amount = this.pricingAmount(option.minAmount);
      if (option.pricingType === "starting_at") return `${amount}+`;
      if (option.pricingType === "range") return `${amount}–${this.pricingAmount(option.maxAmount)}`;
      return amount;
    },

    pricingSizeDisplay(service, sizeKey) {
      const option = service.priceOptions.find((item) => item.sizeKey === sizeKey);
      return option ? this.pricingDisplay(option) : "—";
    },

    pricingRows(options) {
      return options.map((option) => ({ ...option, amount: String(option.minAmount),
        maximum: option.pricingType === "range" ? String(option.maxAmount) : "" }));
    },

    pricingState(rows) {
      const amount = (value) => /^\d+(?:\.\d{1,2})?$/.test(String(value)) ? Number(value).toFixed(2) : String(value);
      return JSON.stringify(rows.map((row) => ({ sizeKey: row.sizeKey, pricingType: row.pricingType,
        amount: amount(row.amount), maximum: row.pricingType === "range" ? amount(row.maximum) : null })));
    },

    get pricingIsDirty() {
      return this.pricingState(this.pricingModal.rows) !== this.pricingModal.savedState;
    },

    get pricingCanRestoreDefault() {
      return !this.pricingIsDirty && this.pricingModal.defaultRows.length > 0
        && this.pricingState(this.pricingModal.defaultRows) !== this.pricingModal.savedState;
    },

    get pricingActionDisabled() {
      return this.pricingModal.busy || (!this.pricingCanRestoreDefault
        && (!this.pricingIsDirty || Object.keys(this.pricingModal.errors).length > 0));
    },

    restoreGroomingDefault() {
      if (!this.isAdmin || this.pricingModal.busy || !this.pricingCanRestoreDefault) return;
      this.pricingModal.rows = clone(this.pricingModal.defaultRows);
      this.pricingModal.pristineFields = {};
      this.pricingModal.error = "";
      this.validatePricing();
    },

    async submitPricingAction() {
      if (this.pricingActionDisabled) return;
      if (this.pricingCanRestoreDefault) this.restoreGroomingDefault();
      else await this.saveGroomingPrice();
    },

    openPricingModal(service, trigger) {
      if (!this.isAdmin) return;
      this._pricingTrigger = trigger;
      const rows = this.pricingRows(service.priceOptions);
      this.pricingModal = { open: true, service, busy: false, error: "", errors: {}, pristineFields: {},
        rows, savedState: this.pricingState(rows), defaultRows: this.pricingRows(service.defaultPriceOptions || []) };
      this.validatePricing();
      this.$nextTick(() => {
        if (!this.pricingModal.open) return;
        this.lockPricingPage();
        this.$refs.pricingDialog.querySelector("select, input")?.focus();
      });
    },

    closePricingModal() {
      if (this.pricingModal.busy) return;
      this.pricingModal.open = false;
      this.unlockPricingPage();
      this._pricingTrigger?.focus();
    },

    lockPricingPage() {
      if (this._pricingPageState) return;
      const body = document.body;
      const root = document.documentElement;
      const overlay = this.$refs.pricingDialog.closest(".grooming-pricing-overlay");
      const scrollbarWidth = window.innerWidth - root.clientWidth;
      this._pricingPageState = { bodyOverflow: body.style.overflow, rootOverflow: root.style.overflow,
        bodyPadding: body.style.paddingRight,
        background: [...body.children].filter((element) => element !== overlay)
          .map((element) => ({ element, inert: element.inert })) };
      if (scrollbarWidth > 0) body.style.paddingRight = `${parseFloat(window.getComputedStyle(body).paddingRight) + scrollbarWidth}px`;
      body.style.overflow = "hidden";
      root.style.overflow = "hidden";
      this._pricingPageState.background.forEach(({ element }) => { element.inert = true; });
    },

    unlockPricingPage() {
      const saved = this._pricingPageState;
      if (!saved) return;
      document.body.style.overflow = saved.bodyOverflow;
      document.body.style.paddingRight = saved.bodyPadding;
      document.documentElement.style.overflow = saved.rootOverflow;
      saved.background.forEach(({ element, inert }) => { element.inert = inert; });
      this._pricingPageState = null;
    },

    destroy() {
      this.unlockPricingPage();
    },

    pricingModalKeydown(event) {
      if (!this.pricingModal.open) return;
      if (event.key === "Escape") { event.preventDefault(); this.closePricingModal(); return; }
      if (event.key !== "Tab") return;
      const controls = [...this.$refs.pricingDialog.querySelectorAll("button:not(:disabled), select:not(:disabled), input:not(:disabled)")]
        .filter((element) => element.offsetParent !== null);
      if (!controls.length) { event.preventDefault(); this.$refs.pricingDialog.focus(); return; }
      const first = controls[0], last = controls[controls.length - 1];
      if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last?.focus(); }
      else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus(); }
    },

    changePricingType(row) {
      row.maximum = "";
      // Defer newly revealed field feedback while still validating completeness.
      for (const field of ["amount", "maximum"]) {
        const key = this.pricingFieldKey(row, field);
        if (row.pricingType === "range") this.pricingModal.pristineFields[key] = true;
        else delete this.pricingModal.pristineFields[key];
      }
      this.validatePricing();
    },

    pricingFieldChanged(row, field) {
      delete this.pricingModal.pristineFields[this.pricingFieldKey(row, field)];
      this.validatePricing();
    },

    pricingFieldError(row, field) {
      const key = this.pricingFieldKey(row, field);
      return this.pricingModal.pristineFields[key] ? "" : this.pricingModal.errors[key];
    },

    pricingAmountIsValid(value) {
      const limits = this.groomingPriceLimits?.[this.pricingModal.service?.kind];
      const text = String(value);
      return Boolean(limits) && /^\d+(?:\.\d{1,2})?$/.test(text) && text.trim() === text
        && Number.isFinite(Number(value)) && Number(value) >= limits.minimum && Number(value) <= limits.maximum;
    },

    pricingPreview(row) {
      if (!["fixed", "range", "starting_at"].includes(row.pricingType) || !this.pricingAmountIsValid(row.amount)
        || (row.pricingType === "range" && (!this.pricingAmountIsValid(row.maximum) || Number(row.maximum) <= Number(row.amount)))) return "—";
      return this.pricingDisplay({ pricingType: row.pricingType, minAmount: Number(row.amount), maxAmount: Number(row.maximum) });
    },

    pricingFieldKey(row, field) {
      return this.pricingModal.service?.kind === "package" ? `sizes.${row.sizeKey}.${field}` : field;
    },

    validatePricing() {
      const errors = {};
      const packageService = this.pricingModal.service.kind === "package";
      const validateAmount = (value, key) => {
        if (!this.pricingAmountIsValid(value)) errors[key] = "Enter a reasonable price amount.";
      };
      if (packageService) {
        const supported = this.pricingModal.service.priceOptions.map((option) => option.sizeKey);
        if (this.pricingModal.rows.length !== supported.length ||
            this.pricingModal.rows.some((row, index) => row.sizeKey !== supported[index])) {
          errors.sizes = "Only the supported package sizes may be updated.";
        }
      }
      for (const row of this.pricingModal.rows) {
        const key = this.pricingFieldKey(row, "amount");
        validateAmount(row.amount, key);
        if (!["fixed", "range", "starting_at"].includes(row.pricingType)) {
          errors[this.pricingFieldKey(row, "pricing_type")] = "Choose a supported pricing type.";
        }
        if (row.pricingType === "range") {
          const maximumKey = this.pricingFieldKey(row, "maximum");
          validateAmount(row.maximum, maximumKey);
          if (!errors[maximumKey] && !errors[key] && Number(row.maximum) <= Number(row.amount)) errors[maximumKey] = "Maximum price must be greater than minimum price.";
        }
      }
      if (packageService && Object.keys(errors).length === 0) {
        let previous = 0;
        for (const row of this.pricingModal.rows) {
          if (Number(row.amount) < previous) errors[this.pricingFieldKey(row, "amount")] = "Price must not be lower than the preceding size.";
          previous = Number(row.amount);
        }
      }
      this.pricingModal.errors = errors;
      return Object.keys(errors).length === 0;
    },

    async saveGroomingPrice() {
      if (!this.isAdmin || this.pricingModal.busy) return;
      this.pricingModal.pristineFields = {};
      if (!this.validatePricing()) return;
      if (!this.pricingIsDirty) return;
      const modal = this.pricingModal;
      const payload = modal.service.kind === "package"
        ? { sizes: Object.fromEntries(modal.rows.map((row) => [row.sizeKey, { pricing_type: row.pricingType, amount: row.amount,
          ...(row.pricingType === "range" ? { maximum: row.maximum } : {}) }])) }
        : { pricing_type: modal.rows[0].pricingType, amount: modal.rows[0].amount,
          ...(modal.rows[0].pricingType === "range" ? { maximum: modal.rows[0].maximum } : {}) };
      modal.busy = true;
      modal.error = "";
      try {
        const response = await API.updateGroomingPricing(modal.service.serviceId, payload);
        this.groomingPrices = this.groomingPrices.map((service) => service.id === response.data.id ? response.data : service);
        modal.busy = false;
        this.closePricingModal();
        this.showStaffActionToast(`${modal.service.name} pricing updated.`);
      } catch (error) {
        modal.errors = Object.fromEntries(Object.entries(error.errors || {}).map(([key, messages]) => [key, messages[0]]));
        modal.error = error.message || "Unable to save pricing. Please retry.";
      } finally { modal.busy = false; }
    },
    appointmentReminders: true,
    noShowAlerts: true,
    paymentReceipt: true,
    availabilityService: "clinic",
    availability: clone(defaultAvailability),
    savedAvailability: clone(defaultAvailability),
    availabilityLoading: true,
    availabilitySaving: false,
    availabilityError: "",
    availabilitySuccess: "",
    groomersOnDuty: 2,
    groomersOnDutySaving: false,
    groomersOnDutyError: "",
    groomersOnDutySuccess: "",
    adminAccount: {
      email: "Admin email",
      username: "",
    },
    adminPassword: {
      username: "",
      current: "",
      password: "",
      confirmation: "",
      showCurrent: false,
      showPassword: false,
      showConfirmation: false,
      submitting: false,
      error: "",
      success: "",
    },
    staffAccount: {
      fullName: "",
      roleLabel: "Staff",
      username: "",
      email: "",
      statusLabel: "",
    },
    staffPassword: {
      current: "",
      password: "",
      confirmation: "",
      showCurrent: false,
      showPassword: false,
      showConfirmation: false,
      submitting: false,
      error: "",
      success: "",
    },
    staffAccounts: [],
    staffLoading: true,
    staffError: "",
    staffFilter: "all",
    staffNotice: "",
    addStaffModal: {
      open: false,
      step: "role",
      roleStage: "main",
      staffType: "",
      staffSubrole: "",
      firstName: "",
      lastName: "",
      email: "",
      submitting: false,
      error: "",
    },
    staffStatusModal: {
      open: false,
      staff: null,
      targetActive: false,
      password: "",
      showPassword: false,
      busy: false,
      error: "",
    },
    staffSetupModal: { open: false, staff: null, password: "", showPassword: false, busy: false, error: "" },
    staffSetupBusyId: null,
    staffDetailsModal: {
      open: false,
      staff: null,
    },
    securityVerification: {
      open: false,
      changeId: null,
      purpose: "",
      targetName: "",
      email: "",
      digits: ["", "", "", "", "", ""],
      submitting: false,
      resending: false,
      error: "",
      notice: "",
    },

    get activeAvailability() {
      return this.availability[this.availabilityService];
    },

    get activeAvailabilityLabel() {
      return this.availabilityService === "clinic" ? "Clinic" : "Grooming";
    },

    get activeStaffCount() {
      return this.staffAccounts.filter((staff) => staff.state === "active").length;
    },

    get pendingStaffCount() {
      return this.staffAccounts.filter((staff) => staff.state === "pending").length;
    },

    get deactivatedStaffCount() {
      return this.staffAccounts.filter((staff) => staff.state === "deactivated").length;
    },

    get filteredStaffAccounts() {
      return this.staffAccounts.filter((staff) => this.staffFilter === "all" || staff.state === this.staffFilter);
    },

    get securityCodeComplete() {
      return this.securityVerification.digits.every((digit) => /^\d$/.test(digit));
    },

    async init() {
      await Promise.all([
        this.loadAvailability(),
        this.isAdmin ? this.loadSecurityAccounts() : this.loadOwnAccount(),
      ]);

      this.$nextTick(() => {
        if (window.lucide) window.lucide.createIcons();
      });
    },

    normalizeAvailability(payload) {
      const normalized = clone(defaultAvailability);

      for (const service of ["clinic", "grooming"]) {
        const settings = payload?.[service];
        if (!settings) continue;

        normalized[service] = {
          open_time: settings.open_time || normalized[service].open_time,
          close_time: settings.close_time || normalized[service].close_time,
          pre_registration_cutoff_time:
            settings.pre_registration_cutoff_time ||
            normalized[service].pre_registration_cutoff_time,
        };
      }

      return normalized;
    },

    async loadAvailability() {
      this.availabilityLoading = true;
      this.availabilityError = "";

      try {
        const response = await API.getAvailabilitySettings();
        this.availability = this.normalizeAvailability(response.availability);
        this.savedAvailability = clone(this.availability);
        this.groomersOnDuty = Number(response.groomers_on_duty) || 1;
      } catch (error) {
        this.availabilityError =
          error.message || "Could not load availability settings.";
      } finally {
        this.availabilityLoading = false;
      }
    },

    accountRoleLabel(account) {
      if (account?.staff_subrole === "veterinarian") return "Veterinarian";
      if (account?.staff_subrole === "clinic_receptionist") return "Clinic Receptionist";
      if (account?.staff_type === "grooming") return "Grooming Staff";
      if (account?.staff_type === "clinic") return "Clinic Staff";
      return account?.role === "admin" ? "Administrator" : "Staff";
    },

    async loadOwnAccount() {
      try {
        const response = await API.getSettingsSecurityAccount();
        const account = response?.account || {};
        this.staffAccount = {
          fullName: `${account.first_name || ""} ${account.last_name || ""}`.trim() || "Staff account",
          roleLabel: this.accountRoleLabel(account),
          username: account.username || "No username",
          email: account.email || "No email address",
          statusLabel: account.is_active && !account.is_archived ? "Active" : "Inactive",
        };
      } catch (error) {
        this.staffPassword.error = error.message || "Could not load your account information.";
      }
    },

    async setGroomersOnDuty(value) {
      const nextValue = Math.min(5, Math.max(1, Number(value) || 1));
      if (this.groomersOnDutySaving || nextValue === this.groomersOnDuty) return;

      this.groomersOnDutySaving = true;
      this.groomersOnDutyError = "";
      this.groomersOnDutySuccess = "";
      try {
        const response = await API.adminUpdateGroomersOnDuty(nextValue);
        this.groomersOnDuty = Number(response.groomers_on_duty) || nextValue;
        this.groomersOnDutySuccess = "Groomers on duty updated.";
      } catch (error) {
        this.groomersOnDutyError = error.message || "Could not update groomers on duty.";
      } finally {
        this.groomersOnDutySaving = false;
      }
    },

    async loadSecurityAccounts() {
      this.staffLoading = true;
      this.staffError = "";
      try {
        const response = await API.getAdminSecurityAccounts();
        this.adminAccount = {
          email: response?.admin?.email || "Admin email",
          username: response?.admin?.username || "",
        };
        this.adminPassword.username = this.adminAccount.username;
        this.staffAccounts = (response?.staff || []).map((staff) => {
          const fullName = `${staff.first_name || ""} ${staff.last_name || ""}`.trim()
            || "Staff account";
          const roleLabel = staff.staff_type === "clinic"
            ? "Clinic Staff"
            : staff.staff_type === "grooming"
              ? "Grooming Receptionist"
              : "Staff";
          const subroleLabel = staff.staff_subrole === "veterinarian"
            ? "Veterinarian"
            : staff.staff_subrole === "clinic_receptionist"
              ? "Clinic Receptionist"
              : "";
          const setupRequired = Boolean(staff.password_setup_required);
          const state = setupRequired ? "pending" : staff.is_active && !staff.is_archived ? "active" : "deactivated";
          const active = state === "active";
          return {
            id: staff.user_id,
            fullName,
            initials: `${staff.first_name?.charAt(0) || "S"}${staff.last_name?.charAt(0) || ""}`.toUpperCase(),
            username: setupRequired ? "" : staff.username || "",
            email: staff.email || "",
            staffType: staff.staff_type || "",
            staffSubrole: staff.staff_subrole || "",
            roleLabel,
            subroleLabel,
            detailRoleLabel: subroleLabel ? `${subroleLabel} (${roleLabel})` : roleLabel,
            active,
            state,
            setupRequired,
            setupLinkExpired: Boolean(staff.setup_link_expired),
            statusLabel: state === "pending" ? "Pending setup" : state === "active" ? "Active" : "Deactivated",
            statusClass: state === "pending" ? "bg-amber-50 text-amber-700" : state === "active" ? "bg-emerald-50 text-emerald-700" : "bg-slate-100 text-slate-500",
          };
        });
      } catch (error) {
        this.staffError = error.message || "Could not load security accounts.";
      } finally {
        this.staffLoading = false;
      }
    },

    selectAvailabilityService(service) {
      if (!["clinic", "grooming"].includes(service)) return;

      this.availabilityService = service;
      this.availabilityError = "";
      this.availabilitySuccess = "";
    },

    resetAvailability() {
      this.availability[this.availabilityService] = clone(
        this.savedAvailability[this.availabilityService],
      );
      this.availabilityError = "";
      this.availabilitySuccess = "";
    },

    async saveAvailability() {
      const settings = this.activeAvailability;
      this.availabilityError = "";
      this.availabilitySuccess = "";

      if (
        !settings.open_time ||
        !settings.close_time ||
        !settings.pre_registration_cutoff_time
      ) {
        this.availabilityError = "Complete all availability time fields.";
        return;
      }

      this.availabilitySaving = true;

      try {
        const response = await API.adminUpdateAvailability({
          service: this.availabilityService,
          open_time: settings.open_time,
          close_time: settings.close_time,
          pre_registration_cutoff_time:
            settings.pre_registration_cutoff_time,
        });

        this.availability = this.normalizeAvailability(response.availability);
        this.savedAvailability = clone(this.availability);
        this.availabilitySuccess =
          response.message || `${this.activeAvailabilityLabel} availability updated.`;
      } catch (error) {
        this.availabilityError =
          error.message || "Could not save availability settings.";
      } finally {
        this.availabilitySaving = false;
      }
    },

    passwordValidationError(password, confirmation, required = true) {
      if (!required && !password && !confirmation) return "";
      if (!password || !confirmation) {
        return "Complete both new password fields.";
      }
      if (password !== confirmation) {
        return "The new passwords do not match.";
      }
      if (
        password.length < 12 ||
        !/[a-z]/.test(password) ||
        !/[A-Z]/.test(password) ||
        !/\d/.test(password) ||
        !/[^A-Za-z0-9]/.test(password)
      ) {
        return "Use at least 12 characters with uppercase, lowercase, a number, and a symbol.";
      }

      return "";
    },

    firstApiError(error) {
      const validationErrors = error?.errors || {};
      const firstMessages = Object.values(validationErrors)[0];
      return Array.isArray(firstMessages) && firstMessages[0]
        ? firstMessages[0]
        : error?.message || "The request could not be completed.";
    },

    async submitAdminPassword() {
      this.adminPassword.error = "";
      this.adminPassword.success = "";

      if (!this.adminPassword.current) {
        this.adminPassword.error = "Enter your current password.";
        return;
      }

      const username = this.adminPassword.username.trim();
      const usernameChanged = username !== this.adminAccount.username;
      const validationError = this.passwordValidationError(
        this.adminPassword.password,
        this.adminPassword.confirmation,
        false,
      );
      if (validationError) {
        this.adminPassword.error = validationError;
        return;
      }
      if (!usernameChanged && !this.adminPassword.password) {
        this.adminPassword.error = "Enter a different username or a new password.";
        return;
      }

      this.adminPassword.submitting = true;
      try {
        const response = await API.requestAdminCredentialChange({
          current_password: this.adminPassword.current,
          username,
          password: this.adminPassword.password || null,
          password_confirmation: this.adminPassword.confirmation || null,
        });
        this.adminPassword.current = "";
        this.adminPassword.password = "";
        this.adminPassword.confirmation = "";
        this.adminPassword.username = this.adminAccount.username;
        this.openSecurityVerification(response);
      } catch (error) {
        this.adminPassword.error = this.firstApiError(error);
      } finally {
        this.adminPassword.submitting = false;
      }
    },

    async submitStaffPassword() {
      this.staffPassword.error = "";
      this.staffPassword.success = "";

      if (!this.staffPassword.current) {
        this.staffPassword.error = "Enter your current password.";
        return;
      }

      const validationError = this.passwordValidationError(
        this.staffPassword.password,
        this.staffPassword.confirmation,
      );
      if (validationError) {
        this.staffPassword.error = validationError;
        return;
      }
      if (this.staffPassword.current === this.staffPassword.password) {
        this.staffPassword.error = "Choose a password that is different from your current password.";
        return;
      }

      this.staffPassword.submitting = true;
      try {
        const response = await API.requestStaffPasswordChange({
          current_password: this.staffPassword.current,
          password: this.staffPassword.password,
          password_confirmation: this.staffPassword.confirmation,
        });
        this.staffPassword.current = "";
        this.staffPassword.password = "";
        this.staffPassword.confirmation = "";
        this.openSecurityVerification(response);
      } catch (error) {
        this.staffPassword.error = this.firstApiError(error);
      } finally {
        this.staffPassword.submitting = false;
      }
    },

    emptyAddStaffModal(open = false) {
      return {
        open,
        step: "role",
        roleStage: "main",
        staffType: "",
        staffSubrole: "",
        firstName: "",
        lastName: "",
        email: "",
        submitting: false,
        error: "",
      };
    },

    openAddStaffAccount() {
      this.staffNotice = "";
      this.addStaffModal = this.emptyAddStaffModal(true);
      this.refreshSecurityIcons();
    },

    closeAddStaffAccount() {
      if (this.addStaffModal.submitting) return;
      this.addStaffModal = this.emptyAddStaffModal(false);
    },

    handleAddStaffClose() {
      if (this.addStaffModal.step === "role"
        && this.addStaffModal.roleStage === "clinic") {
        this.addStaffModal.roleStage = "main";
        this.addStaffModal.staffType = "";
        this.addStaffModal.staffSubrole = "";
        this.addStaffModal.error = "";
        this.refreshSecurityIcons();
        return;
      }

      this.closeAddStaffAccount();
    },

    chooseStaffType(staffType) {
      if (!["clinic", "grooming"].includes(staffType)) return;
      this.addStaffModal.staffType = staffType;
      this.addStaffModal.staffSubrole = "";

      if (staffType === "clinic") {
        this.addStaffModal.roleStage = "clinic";
        this.addStaffModal.error = "";
        this.refreshSecurityIcons();
        return;
      }

      this.addStaffModal.step = "details";
      this.addStaffModal.error = "";
      this.refreshSecurityIcons();
      this.$nextTick(() => document.getElementById("newStaffFirstName")?.focus());
    },

    chooseClinicSubrole(staffSubrole) {
      if (!["veterinarian", "clinic_receptionist"].includes(staffSubrole)) return;
      this.addStaffModal.staffType = "clinic";
      this.addStaffModal.staffSubrole = staffSubrole;
      this.addStaffModal.step = "details";
      this.addStaffModal.error = "";
      this.refreshSecurityIcons();
      this.$nextTick(() => document.getElementById("newStaffFirstName")?.focus());
    },

    returnToStaffType() {
      if (this.addStaffModal.submitting) return;
      this.addStaffModal.step = "role";
      this.addStaffModal.roleStage = this.addStaffModal.staffType === "clinic" ? "clinic" : "main";
      this.addStaffModal.error = "";
      this.refreshSecurityIcons();
    },

    async requestNewStaffAccount() {
      this.addStaffModal.error = "";

      const firstName = this.addStaffModal.firstName.trim();
      const lastName = this.addStaffModal.lastName.trim();
      if (!firstName || !lastName) {
        this.addStaffModal.error = "Enter the staff first and last name.";
        return;
      }

      const staffSubrole = this.addStaffModal.staffSubrole;
      if (this.addStaffModal.staffType === "clinic"
        && !["veterinarian", "clinic_receptionist"].includes(staffSubrole)) {
        this.addStaffModal.error = "Choose a Clinic Staff sub-role.";
        return;
      }

      const email = this.addStaffModal.email.trim().toLowerCase();
      if (!email) {
        this.addStaffModal.error = "Enter the staff email address.";
        return;
      }
      if (!/^[^\s@]+@[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?)+$/.test(email)) {
        this.addStaffModal.error = "Enter a valid email address, such as name@example.com.";
        return;
      }

      this.addStaffModal.submitting = true;
      try {
        await API.requestStaffAccount({
          staff_type: this.addStaffModal.staffType,
          staff_subrole: this.addStaffModal.staffType === "clinic" ? staffSubrole : null,
          first_name: firstName,
          last_name: lastName,
          email,
        });
        this.addStaffModal.step = "success";
        await this.loadSecurityAccounts();
        this.refreshSecurityIcons();
      } catch (error) {
        this.addStaffModal.error = this.firstApiError(error);
      } finally {
        this.addStaffModal.submitting = false;
      }
    },

    openStaffStatusModal(staff) {
      if (staff.setupRequired) return;
      this.staffStatusModal = {
        open: true,
        staff,
        targetActive: !staff.active,
        password: "",
        showPassword: false,
        busy: false,
        error: "",
      };
      this.refreshSecurityIcons();
      this.$nextTick(() => document.getElementById("staffStatusAdminPassword")?.focus());
    },

    openStaffDetails(staff) {
      if (!staff) return;

      this.staffDetailsModal = { open: true, staff };
      this.refreshSecurityIcons();
    },

    closeStaffDetails() {
      this.staffDetailsModal = { open: false, staff: null };
    },

    closeStaffStatusModal() {
      if (this.staffStatusModal.busy) return;
      this.staffStatusModal = {
        open: false,
        staff: null,
        targetActive: false,
        password: "",
        showPassword: false,
        busy: false,
        error: "",
      };
    },

    async updateStaffStatus() {
      const { staff, targetActive } = this.staffStatusModal;
      if (!staff || this.staffStatusModal.busy) return;

      this.staffStatusModal.error = "";
      if (!this.staffStatusModal.password) {
        this.staffStatusModal.error = "Enter your admin password to confirm.";
        return;
      }
      this.staffStatusModal.busy = true;
      try {
        await API.updateStaffAccountStatus(staff.id, targetActive, this.staffStatusModal.password);
        this.staffStatusModal.busy = false;
        this.closeStaffStatusModal();
        const staffName = staff.fullName && staff.fullName !== "Staff account"
          ? staff.fullName
          : staff.subroleLabel || staff.roleLabel || "Staff account";
        this.showStaffActionToast(targetActive
          ? `${staffName} was reactivated and can sign in again.`
          : `${staffName} was deactivated and signed out.`);
        await this.loadSecurityAccounts();
      } catch (error) {
        this.staffStatusModal.error = this.firstApiError(error);
        this.staffStatusModal.busy = false;
      }
    },

    async resendStaffSetup(staff) {
      if (!staff?.setupRequired || this.staffSetupBusyId) return;
      this.staffSetupBusyId = staff.id;
      this.staffNotice = "";
      this.staffError = "";
      try {
        const response = await API.resendStaffSetupEmail(staff.id);
        this.staffNotice = response.message;
        await this.loadSecurityAccounts();
        if (this.staffDetailsModal.open) {
          this.staffDetailsModal.staff = this.staffAccounts.find((account) => account.id === staff.id) || null;
        }
      } catch (error) {
        this.staffNotice = this.firstApiError(error);
      } finally {
        this.staffSetupBusyId = null;
      }
    },

    openStaffSetupModal(staff) {
      if (!staff?.setupRequired) return;
      this.closeStaffDetails();
      this.staffSetupModal = { open: true, staff, password: "", showPassword: false, busy: false, error: "" };
      this.refreshSecurityIcons();
    },

    closeStaffSetupModal() {
      if (this.staffSetupModal.busy) return;
      this.staffSetupModal = { open: false, staff: null, password: "", showPassword: false, busy: false, error: "" };
    },

    async cancelStaffSetup() {
      const staff = this.staffSetupModal.staff;
      if (!staff || this.staffSetupModal.busy) return;
      this.staffSetupModal.error = "";
      if (!this.staffSetupModal.password) {
        this.staffSetupModal.error = "Enter your admin password to confirm.";
        return;
      }
      this.staffSetupModal.busy = true;
      try {
        const response = await API.cancelStaffSetup(staff.id, this.staffSetupModal.password);
        this.staffSetupModal.busy = false;
        this.closeStaffSetupModal();
        this.showStaffActionToast(response.message);
        await this.loadSecurityAccounts();
      } catch (error) {
        this.staffSetupModal.error = this.firstApiError(error);
        this.staffSetupModal.busy = false;
      }
    },

    showStaffActionToast(message) {
      this.$nextTick(() => {
        // Allow the existing modal's 150ms closing transition to finish.
        setTimeout(() => window.showSuccessToast(message), 200);
      });
    },

    closeSecurityModals() {
      if (this.staffDetailsModal.open) this.closeStaffDetails();
      if (this.addStaffModal.open) this.closeAddStaffAccount();
      if (this.staffStatusModal.open) this.closeStaffStatusModal();
      if (this.staffSetupModal.open) this.closeStaffSetupModal();
      if (this.securityVerification.open) this.closeSecurityVerification();
    },

    emptySecurityVerification(open = false, challenge = {}) {
      return {
        open,
        changeId: challenge.change_id || null,
        purpose: challenge.purpose || "",
        targetName: challenge.target_name || "",
        email: challenge.confirmation_email || "",
        digits: ["", "", "", "", "", ""],
        submitting: false,
        resending: false,
        error: "",
        notice: "",
      };
    },

    openSecurityVerification(challenge) {
      this.securityVerification = this.emptySecurityVerification(true, challenge);
      this.refreshSecurityIcons();
      this.$nextTick(() => document.getElementById("securityCodeDigit0")?.focus());
    },

    closeSecurityVerification() {
      if (this.securityVerification.submitting) return;
      this.securityVerification = this.emptySecurityVerification(false);
    },

    focusSecurityCodeDigit(index) {
      this.$nextTick(() => document.getElementById(`securityCodeDigit${index}`)?.focus());
    },

    applySecurityCode(value, startIndex = 0) {
      const digits = String(value || "").replace(/\D/g, "").slice(0, 6 - startIndex);
      if (!digits) return;

      [...digits].forEach((digit, offset) => {
        this.securityVerification.digits[startIndex + offset] = digit;
      });
      this.focusSecurityCodeDigit(Math.min(5, startIndex + digits.length));
    },

    handleSecurityCodeInput(index, event) {
      const digits = String(event.target.value || "").replace(/\D/g, "");
      if (digits.length > 1) {
        this.applySecurityCode(digits, index);
        return;
      }

      const digit = digits.slice(-1);
      this.securityVerification.digits[index] = digit;
      event.target.value = digit;
      if (digit && index < 5) this.focusSecurityCodeDigit(index + 1);
    },

    handleSecurityCodeBackspace(index) {
      if (this.securityVerification.digits[index] || index === 0) return;
      this.securityVerification.digits[index - 1] = "";
      this.focusSecurityCodeDigit(index - 1);
    },

    pasteSecurityCode(event) {
      const value = event.clipboardData?.getData("text") || "";
      this.securityVerification.digits = ["", "", "", "", "", ""];
      this.applySecurityCode(value);
    },

    clearSecurityCode() {
      this.securityVerification.digits = ["", "", "", "", "", ""];
      this.focusSecurityCodeDigit(0);
    },

    async confirmSecurityCredentialChange() {
      if (!this.securityCodeComplete || !this.securityVerification.changeId) return;

      this.securityVerification.error = "";
      this.securityVerification.notice = "";
      this.securityVerification.submitting = true;
      try {
        const confirmChange = this.isStaff
          ? API.confirmStaffPasswordChange
          : API.confirmSecurityCredentialChange;
        const response = await confirmChange(
          this.securityVerification.changeId,
          this.securityVerification.digits.join(""),
        );

        if (response.requires_reauthentication) {
          API.clearAuthState();
          API.redirectToSignIn({ replace: true });
          return;
        }

        this.securityVerification = this.emptySecurityVerification(false);
        if (this.isStaff) {
          this.staffPassword.success = "Password updated successfully.";
          window.setTimeout?.(() => {
            this.staffPassword.success = "";
          }, 5000);
        } else {
          this.staffNotice = response.message;
          await this.loadSecurityAccounts();
        }
      } catch (error) {
        this.securityVerification.error = this.firstApiError(error);
        this.clearSecurityCode();
      } finally {
        this.securityVerification.submitting = false;
      }
    },

    async resendSecurityCredentialChangeCode() {
      if (!this.securityVerification.changeId || this.securityVerification.resending) return;

      this.securityVerification.error = "";
      this.securityVerification.notice = "";
      this.securityVerification.resending = true;
      try {
        const resendCode = this.isStaff
          ? API.resendStaffPasswordChangeCode
          : API.resendSecurityCredentialChangeCode;
        const response = await resendCode(this.securityVerification.changeId);
        this.securityVerification.email = response.confirmation_email;
        this.securityVerification.notice = response.message;
        this.clearSecurityCode();
      } catch (error) {
        this.securityVerification.error = this.firstApiError(error);
      } finally {
        this.securityVerification.resending = false;
      }
    },

    refreshSecurityIcons() {
      this.$nextTick(() => {
        if (window.lucide) window.lucide.createIcons();
      });
    },
  };
}
