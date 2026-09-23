function adminSettings() {
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
    activeSettingsTab: "general",
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
      username: "",
      email: "",
      createdUsername: "",
      submitting: false,
      error: "",
    },
    staffStatusModal: {
      open: false,
      staff: null,
      targetActive: false,
      busy: false,
      error: "",
    },
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
      return this.staffAccounts.filter((staff) => staff.active).length;
    },

    get inactiveStaffCount() {
      return this.staffAccounts.filter((staff) => !staff.active).length;
    },

    get filteredStaffAccounts() {
      return this.staffAccounts.filter((staff) => {
        return (
          this.staffFilter === "all" ||
          (this.staffFilter === "active" && staff.active) ||
          (this.staffFilter === "inactive" && !staff.active)
        );
      });
    },

    get securityCodeComplete() {
      return this.securityVerification.digits.every((digit) => /^\d$/.test(digit));
    },

    async init() {
      await Promise.all([this.loadAvailability(), this.loadSecurityAccounts()]);

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
      } catch (error) {
        this.availabilityError =
          error.message || "Could not load availability settings.";
      } finally {
        this.availabilityLoading = false;
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
          const active = Boolean(staff.is_active) && !Boolean(staff.is_archived);
          const setupRequired = Boolean(staff.password_setup_required);
          return {
            id: staff.user_id,
            fullName,
            initials: `${staff.first_name?.charAt(0) || "S"}${staff.last_name?.charAt(0) || ""}`.toUpperCase(),
            username: staff.username || "",
            email: staff.email || "",
            staffType: staff.staff_type || "",
            staffSubrole: staff.staff_subrole || "",
            roleLabel,
            subroleLabel,
            detailRoleLabel: subroleLabel ? `${subroleLabel} (${roleLabel})` : roleLabel,
            active,
            setupRequired,
            statusLabel: !active
              ? "Deactivated"
              : setupRequired
                ? "Setup Required"
                : "Active",
            statusClass: !active
              ? "bg-slate-100 text-slate-500"
              : setupRequired
                ? "bg-amber-50 text-amber-700"
                : "bg-emerald-50 text-emerald-700",
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

    emptyAddStaffModal(open = false) {
      return {
        open,
        step: "role",
        roleStage: "main",
        staffType: "",
        staffSubrole: "",
        firstName: "",
        lastName: "",
        username: "",
        email: "",
        createdUsername: "",
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

      const username = this.addStaffModal.username.trim();
      if (username && !/^[A-Za-z][A-Za-z0-9._-]{2,49}$/.test(username)) {
        this.addStaffModal.error = "Enter a valid username.";
        return;
      }

      const email = this.addStaffModal.email.trim().toLowerCase();
      if (!email) {
        this.addStaffModal.error = "Enter the staff email address.";
        return;
      }

      this.addStaffModal.submitting = true;
      try {
        const response = await API.requestStaffAccount({
          staff_type: this.addStaffModal.staffType,
          staff_subrole: this.addStaffModal.staffType === "clinic" ? staffSubrole : null,
          first_name: firstName,
          last_name: lastName,
          username: username || null,
          email,
        });
        this.addStaffModal.createdUsername = response.username;
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
      this.staffStatusModal = {
        open: true,
        staff,
        targetActive: !staff.active,
        busy: false,
        error: "",
      };
      this.refreshSecurityIcons();
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
        busy: false,
        error: "",
      };
    },

    async updateStaffStatus() {
      const { staff, targetActive } = this.staffStatusModal;
      if (!staff) return;

      this.staffStatusModal.error = "";
      this.staffStatusModal.busy = true;
      try {
        const response = await API.updateStaffAccountStatus(staff.id, targetActive);
        this.staffStatusModal.busy = false;
        this.closeStaffStatusModal();
        this.staffNotice = response.message;
        await this.loadSecurityAccounts();
      } catch (error) {
        this.staffStatusModal.error = this.firstApiError(error);
        this.staffStatusModal.busy = false;
      }
    },

    closeSecurityModals() {
      if (this.staffDetailsModal.open) this.closeStaffDetails();
      if (this.addStaffModal.open) this.closeAddStaffAccount();
      if (this.staffStatusModal.open) this.closeStaffStatusModal();
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
        const response = await API.confirmSecurityCredentialChange(
          this.securityVerification.changeId,
          this.securityVerification.digits.join(""),
        );

        if (response.requires_reauthentication) {
          API.clearAuthState();
          API.redirectToSignIn({ replace: true });
          return;
        }

        const message = response.message;
        this.securityVerification = this.emptySecurityVerification(false);
        this.staffNotice = message;
        await this.loadSecurityAccounts();
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
        const response = await API.resendSecurityCredentialChangeCode(
          this.securityVerification.changeId,
        );
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
