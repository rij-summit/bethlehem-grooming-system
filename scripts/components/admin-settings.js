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

    get activeAvailability() {
      return this.availability[this.availabilityService];
    },

    get activeAvailabilityLabel() {
      return this.availabilityService === "clinic" ? "Clinic" : "Grooming";
    },

    async init() {
      await this.loadAvailability();

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
  };
}
