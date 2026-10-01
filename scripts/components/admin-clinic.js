// ── Medical Record Modal ──────────────────────────────────────────────────────

function adminClinicModal() {
  return {
    open:       false,
    saving:     false,
    modalError: "",
    title:      "Medical Record",
    apptId:     null,
    currentAppt: null,
    activeSection: "medical",
    attachments: [],
    attachmentFile: null,
    attachmentLabel: "",
    attachmentBusy: false,
    attachmentBusyId: null,
    attachmentError: "",
    form: {
      chief_complaint: "", diagnosis: "", findings: "", treatment_given: "",
      vet_notes: "", follow_up_date: "", follow_up_notes: "",
      weight_kg: "", temperature_c: "", heart_rate_bpm: "",
      respiratory_rate_bpm: "", body_condition_score: "",
      medications: [],
    },

    init() {
      window.addEventListener("clinic-open-modal", (e) => this.openModal(e.detail));
    },

    openModal({ appt, section = "medical" } = {}) {
      if (!appt) return;
      this.apptId     = appt.id;
      this.currentAppt = appt;
      this.activeSection = "medical";
      this.modalError = "";
      this.title      = `Patient Record — ${appt.ownerName}${appt.pet?.name ? " / " + appt.pet.name : ""}`;
      const r = appt.record  || {};
      const v = appt.vitals  || {};
      this.attachments = (r.attachments || []).map((attachment) => ({ ...attachment }));
      this.attachmentFile = null;
      this.attachmentLabel = "";
      this.attachmentBusy = false;
      this.attachmentBusyId = null;
      this.attachmentError = "";
      this.form = {
        chief_complaint:      r.chief_complaint      || appt.chief_complaint || "",
        diagnosis:            r.diagnosis            || "",
        findings:             r.findings             || "",
        treatment_given:      r.treatment_given      || "",
        vet_notes:            r.vet_notes            || "",
        follow_up_date:       r.follow_up_date       || "",
        follow_up_notes:      r.follow_up_notes      || "",
        weight_kg:            v.weight_kg            ?? "",
        temperature_c:        v.temperature_c        ?? "",
        heart_rate_bpm:       v.heart_rate_bpm       ?? "",
        respiratory_rate_bpm: v.respiratory_rate_bpm ?? "",
        body_condition_score: v.body_condition_score ?? "",
        medications:          (r.medications || []).map((m) => ({ ...m })),
      };
      this.open = true;
      this.selectSection(section);
    },

    selectSection(section) {
      this.activeSection = section === "vaccinations" ? "vaccinations" : "medical";

      if (this.activeSection === "vaccinations" && this.currentAppt) {
        window.dispatchEvent(new CustomEvent("clinic-vaccinations-open", {
          detail: { appt: this.currentAppt },
        }));
      }
    },

    closeModal() {
      if (this.saving) return;
      this.open = false;
      this.activeSection = "medical";
    },

    selectAttachment(event) {
      this.attachmentFile = event.target.files?.[0] || null;
      this.attachmentError = "";
    },

    formatFileSize(bytes) {
      const size = Number(bytes);
      if (!Number.isFinite(size) || size < 0) return "Size unavailable";
      if (size < 1024) return `${size} B`;
      if (size < 1024 * 1024) return `${(size / 1024).toFixed(1)} KB`;
      return `${(size / (1024 * 1024)).toFixed(1)} MB`;
    },

    async uploadAttachment() {
      if (!this.attachmentFile) {
        this.attachmentError = "Choose a JPG, PNG, PDF, or DCM file first.";
        return;
      }

      this.attachmentBusy = true;
      this.attachmentError = "";
      try {
        const result = await API.clinicUploadAttachment(
          this.apptId,
          this.attachmentFile,
          this.attachmentLabel.trim(),
        );
        this.attachments.push(result.attachment);
        this.attachmentFile = null;
        this.attachmentLabel = "";
        if (this.$refs.attachmentFile) this.$refs.attachmentFile.value = "";
      } catch (error) {
        this.attachmentError = error.errors
          ? Object.values(error.errors).flat().join(" ")
          : (error.message || "Failed to upload attachment.");
      } finally {
        this.attachmentBusy = false;
      }
    },

    async downloadAttachment(attachment) {
      this.attachmentBusyId = attachment.id;
      this.attachmentError = "";
      try {
        const result = await API.clinicDownloadAttachment(this.apptId, attachment.id);
        const objectUrl = URL.createObjectURL(result.blob);
        const link = document.createElement("a");
        link.href = objectUrl;
        link.download = result.fileName || attachment.file_name || "clinic-attachment";
        document.body.appendChild(link);
        link.click();
        link.remove();
        setTimeout(() => URL.revokeObjectURL(objectUrl), 1000);
      } catch (error) {
        this.attachmentError = error.message || "Failed to download attachment.";
      } finally {
        this.attachmentBusyId = null;
      }
    },

    async deleteAttachment(attachment) {
      if (!window.confirm(`Delete ${attachment.file_name}? This cannot be undone.`)) return;

      this.attachmentBusyId = attachment.id;
      this.attachmentError = "";
      try {
        await API.clinicDeleteAttachment(this.apptId, attachment.id);
        this.attachments = this.attachments.filter((item) => item.id !== attachment.id);
      } catch (error) {
        this.attachmentError = error.message || "Failed to delete attachment.";
      } finally {
        this.attachmentBusyId = null;
      }
    },

    addMedication() {
      this.form.medications.push({ drug_name: "", dosage: "", frequency: "", duration: "", instructions: "" });
    },

    removeMedication(idx) {
      this.form.medications.splice(idx, 1);
    },

    async saveRecord(finishCase = false) {
      this.saving     = true;
      this.modalError = "";
      try {
        const payload = {
          chief_complaint:      this.form.chief_complaint      || null,
          diagnosis:            this.form.diagnosis             || null,
          findings:             this.form.findings              || null,
          treatment_given:      this.form.treatment_given       || null,
          vet_notes:            this.form.vet_notes             || null,
          follow_up_date:       this.form.follow_up_date        || null,
          follow_up_notes:      this.form.follow_up_notes       || null,
          weight_kg:            this.form.weight_kg             || null,
          temperature_c:        this.form.temperature_c         || null,
          heart_rate_bpm:       this.form.heart_rate_bpm        || null,
          respiratory_rate_bpm: this.form.respiratory_rate_bpm  || null,
          body_condition_score: this.form.body_condition_score   || null,
          medications:          this.form.medications.filter((m) => m.drug_name?.trim()),
          finish_case:          finishCase,
        };
        await API.clinicSaveRecord(this.apptId, payload);
        this.open = false;
        window.dispatchEvent(new CustomEvent("clinic-case-updated"));
      } catch (e) {
        this.modalError = e.message || "Failed to save record.";
      } finally {
        this.saving = false;
      }
    },
  };
}

// ── Clinic Records Page ───────────────────────────────────────────────────────

function adminClinicPage() {
  return {
    // Search state
    searchQuery: "",
    searchRows: [],
    noResults: false,
    searching: false,
    _timer: null,
    _reqId: 0,
    recordsPage: 1,
    hasMoreRecords: false,
    activeCases: [],
    activeCasesLoading: false,
    addCustomer: { open: false, saving: false, error: "", form: {} },

    // Patient profile panel
    profile: {
      open: false,
      loading: false,
      error: null,
      pet: null,
      owner: null,
      records: [],
    },

    // Owner's pet list (opened by View)
    petPicker: { open: false, owner: null, pets: [] },

    // Read-only consultation detail
    detail: { open: false, record: null },

    init() {
      const token = API.getAdminToken?.();
      const role  = API.getUserRole?.();
      if (!token || (role !== "admin" && role !== "staff")) {
        API.redirectToSignIn?.();
        return;
      }
      this.loadAllRecords();
      this.loadActiveCases();
      window.addEventListener("clinic-case-updated", () => this.loadActiveCases());
    },

    async loadActiveCases() {
      this.activeCasesLoading = true;
      try { this.activeCases = (await API.getActiveClinicCases()).cases || []; }
      catch { this.activeCases = []; }
      finally { this.activeCasesLoading = false; }
    },

    caseLabel(item) { return ({ online_request: "Online request", consultation: "Consultation", vaccination: "Vaccination" })[item.case_type] || "Clinic case"; },
    caseStatus(item) { return item.status === "waiting_to_arrive" ? "Waiting for Arrival" : "In Progress"; },
    caseOpened(item) { return item.created_at ? new Date(item.created_at).toLocaleString("en-PH", { dateStyle: "medium", timeStyle: "short" }) : "—"; },
    async cancelCase(item) {
      if (!window.confirm(`Cancel the case for ${item.pet?.name || "this patient"}?`)) return;
      try { await API.clinicCancel(item.id); await this.loadActiveCases(); }
      catch (error) { alert(error.message || "Could not cancel this case."); }
    },
    openCase(item, section) { window.dispatchEvent(new CustomEvent("clinic-open-modal", { detail: { appt: item, section: section || (item.case_type === "vaccination" ? "vaccinations" : "medical") } })); },
    async startCase(item) {
      try { const response = await API.startClinicCase(item.id); await this.loadActiveCases(); this.openCase(response.case); }
      catch (error) { alert(error.message || "Could not start this case."); }
    },
    openAddCustomer() {
      this.addCustomer = { open: true, saving: false, error: "", form: { first_name: "", last_name: "", middle_name: "", phone: "", email: "", pet_name: "", species: "", breed: "" } };
    },
    closeAddCustomer() { if (!this.addCustomer.saving) this.addCustomer.open = false; },
    async saveCustomerRecord(confirmSimilarName = false) {
      this.addCustomer.saving = true; this.addCustomer.error = "";
      try {
        const form = this.addCustomer.form;
        const owner = await API.createUnregisteredCustomer({ first_name: form.first_name, last_name: form.last_name, middle_name: form.middle_name || null, phone: form.phone, email: form.email || null, confirm_similar_name: confirmSimilarName });
        const petResponse = await API.adminAddCustomerPet("unregistered", owner.customer.id, { pet_name: form.pet_name, species: form.species, breed: form.breed || null });
        const pet = petResponse.pet;
        this.searchRows.unshift({ owner: owner.customer, pets: [{ id: pet.pet_id, petName: pet.pet_name, species: pet.species, breed: pet.breed }] });
        this.noResults = false;
        this.addCustomer.open = false;
        this.$nextTick?.(() => { if (window.lucide) window.lucide.createIcons(); });
      } catch (error) {
        if (error.code === "similar_customer_name" && !confirmSimilarName && window.confirm(error.message)) {
          this.addCustomer.saving = false;
          return this.saveCustomerRecord(true);
        }
        this.addCustomer.error = error.errors ? Object.values(error.errors).flat().join(" ") : (error.message || "Customer record could not be saved.");
      } finally { this.addCustomer.saving = false; }
    },

    async loadAllRecords(append = false) {
      this.searching = true;
      this.noResults = false;
      const page = append ? this.recordsPage + 1 : 1;
      try {
        const res = await API.getClinicRecords(page);
        this.recordsPage = page;
        this.hasMoreRecords = !!res.has_more;
        this.searchRows = append ? this.searchRows.concat(res.rows || []) : (res.rows || []);
        this.noResults = this.searchRows.length === 0;
      } catch {
        if (!append) this.searchRows = [];
        this.noResults = false;
      } finally {
        this.searching = false;
        this.$nextTick?.(() => { if (window.lucide) window.lucide.createIcons(); });
      }
    },

    _buildRows(res) {
      const rows = [];
      const rowsByOwner = new Map();
      const seenPetIds = new Set();

      for (const c of (res.customers || [])) {
        const pets = (c.pets || []).filter(p => !p.isArchived);
        pets.forEach(p => seenPetIds.add(String(p.id)));
        const row = { owner: c, pets };
        rowsByOwner.set(`${c.recordType}:${c.id}`, row);
        rows.push(row);
      }
      for (const p of (res.pets || [])) {
        if (seenPetIds.has(String(p.id))) continue;
        const key = `${p.ownerRecordType}:${p.ownerId}`;
        let row = rowsByOwner.get(key);
        if (!row) {
          row = {
            owner: {
              id: p.ownerId,
              recordType: p.ownerRecordType,
              fullName: p.ownerName,
              phone: null,
              email: null,
            },
            pets: [],
          };
          rowsByOwner.set(key, row);
          rows.push(row);
        }
        row.pets.push(p);
      }

      this.searchRows = rows;
      this.hasMoreRecords = false;
      this.noResults  = rows.length === 0;
    },

    async onSearchInput() {
      const q = this.searchQuery.trim();
      if (q.length === 0) {
        clearTimeout(this._timer);
        this._reqId++;
        await this.loadAllRecords();
        return;
      }
      if (q.length < 2) {
        clearTimeout(this._timer);
        this._reqId++;
        this.searchRows = [];
        this.noResults  = false;
        return;
      }
      clearTimeout(this._timer);
      this._timer = setTimeout(async () => {
        const rid = ++this._reqId;
        this.searching = true;
        try {
          const res = await API.searchWalkInCustomers(q);
          if (rid !== this._reqId) return;
          this._buildRows(res);
        } catch {
          if (rid !== this._reqId) return;
          this.searchRows = [];
          this.noResults  = false;
        } finally {
          if (rid === this._reqId) this.searching = false;
        }
      }, 350);
    },

    clearSearch() {
      clearTimeout(this._timer);
      this._reqId++;
      this.searchQuery = "";
      this.searchRows  = [];
      this.noResults   = false;
      this.searching   = false;
      this.loadAllRecords();
    },

    async openProfile(row) {
      if (!row.pet?.id) return;
      this.profile = {
        open: true,
        loading: true,
        error: null,
        pet: row.pet,
        owner: row.owner,
        records: [],
      };
      this.$nextTick?.(() => { if (window.lucide) window.lucide.createIcons(); });
      try {
        const data = await API.adminGetPetProfile(row.pet.id);
        this.profile.records = data.medical_records || [];
      } catch (err) {
        this.profile.error = err.message || "Failed to load pet profile.";
      } finally {
        this.profile.loading = false;
        this.$nextTick?.(() => { if (window.lucide) window.lucide.createIcons(); });
      }
    },

    closeProfile() {
      this.profile.open = false;
    },

    openPetPicker(row) {
      if (!row.pets?.length) return;
      this.petPicker = { open: true, owner: row.owner, pets: row.pets };
      this.$nextTick?.(() => { if (window.lucide) window.lucide.createIcons(); });
    },

    closePetPicker() {
      this.petPicker.open = false;
    },

    // One ongoing case per pet: a consultation and a vaccination share the same
    // case through its Medical Record and Vaccinations tabs.
    async openOrResumeCase(caseType) {
      const section = caseType === "vaccination" ? "vaccinations" : "medical";
      try {
        let caseItem;
        try {
          caseItem = (await API.createClinicCase({ pet_id: this.profile.pet.id, case_type: caseType })).case;
        } catch (error) {
          if (error.code !== "active_case_exists" || !error.data?.case) throw error;
          const tabName = section === "vaccinations" ? "Vaccinations" : "Medical Record";
          if (!window.confirm(`${error.message}\n\nOpen it and continue on the ${tabName} tab? You can record both the consultation and the vaccination in the same case.`)) return;
          caseItem = error.data.case;
          if (caseItem.status === "waiting_to_arrive") caseItem = (await API.startClinicCase(caseItem.id)).case;
        }
        await this.loadActiveCases(); this.closeProfile(); this.closePetPicker(); this.openCase(caseItem, section);
      } catch (error) { alert(error.message || `Could not open a ${caseType} case.`); }
    },

    async openVaccinations() {
      if (!this.profile.pet?.id) return;
      await this.openOrResumeCase("vaccination");
    },

    async newConsultation() {
      const { pet, owner } = this.profile;
      if (!pet?.id || !owner) return;

      await this.openOrResumeCase("consultation");
      return;

      // Build the owner draft in exactly the same format that walk-in-owner-step.js
      // saveOwnerDraft() produces, so the pet step can read it without modification.
      const middleInitial = owner.middleName
        ? String(owner.middleName).trim().replace(/\.+$/, "").toUpperCase().charAt(0)
        : "";
      const ownerDraft = {
        firstName:              owner.firstName  || "",
        lastName:               owner.lastName   || "",
        middleInitial,
        phone:                  owner.phone      || "",
        email:                  owner.email      || "",
        fullName:               owner.fullName   || "",
        bookingType:            "walk_in",
        appointmentType:        "clinic",
        ownerRecordType:        owner.recordType || "registered",
        customerUserId:         owner.recordType === "registered"   ? owner.id : null,
        unregisteredCustomerId: owner.recordType === "unregistered" ? owner.id : null,
        existingPets:           Array.isArray(owner.pets) ? owner.pets : [],
        // Flag so the pet step Back button returns to clinic, not Step 1
        directEntry:            "clinic",
      };

      // Clear any stale walk-in continuation data before setting the new draft
      ["walkInPetStep", "walkInConsentStep", "walkInReviewStep", "walkInBookingConfirmation"]
        .forEach(k => sessionStorage.removeItem(k));

      sessionStorage.setItem("walkInOwnerStep", JSON.stringify(ownerDraft));
      // Tell the pet step which pet to pre-select
      sessionStorage.setItem("clinicPreselectedPetId", String(pet.id));

      // Skip Step 1 entirely — go straight to pet details
      window.location.href = "./walk-in-pet-details.html";
    },

    viewDetail(record) {
      this.detail = { open: true, record };
      this.$nextTick?.(() => { if (window.lucide) window.lucide.createIcons(); });
    },

    petAge(pet) {
      if (!pet?.birthdate) return null;
      const yrs = Math.floor((Date.now() - new Date(pet.birthdate)) / (365.25 * 24 * 3600 * 1000));
      return yrs <= 0 ? "< 1 yr" : `${yrs} yr${yrs === 1 ? "" : "s"}`;
    },

    petSummary(pet) {
      if (!pet) return "—";
      return [pet.species, pet.breed].filter(Boolean).join(" · ") || "Pet";
    },

    ownerInitials(name) {
      return String(name || "?").trim().split(/\s+/).slice(0, 2).map(w => w[0]).join("").toUpperCase();
    },

    fmtDate(d) {
      if (!d) return "—";
      try { return new Date(d).toLocaleDateString("en-PH", { year: "numeric", month: "short", day: "numeric" }); }
      catch { return d; }
    },
  };
}
