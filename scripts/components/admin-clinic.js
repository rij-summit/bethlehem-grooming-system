function escapeHtml(str) {
  return String(str ?? "")
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#039;");
}

function formatTime(iso) {
  if (!iso) return "—";
  try {
    return new Date(iso).toLocaleTimeString("en-PH", { hour: "2-digit", minute: "2-digit", hour12: true });
  } catch {
    return "—";
  }
}

const STATUS_LABEL = {
  waiting_to_arrive: "Waiting to Arrive",
  checked_in:        "In Queue",
  in_consultation:   "In Consultation",
  for_payment:       "For Payment",
  completed:         "Completed",
  cancelled:         "Cancelled",
};

const STATUS_BADGE = {
  waiting_to_arrive: "bg-slate-100 text-slate-600",
  checked_in:        "bg-blue-100 text-blue-700",
  in_consultation:   "bg-amber-100 text-amber-700",
  for_payment:       "bg-purple-100 text-purple-700",
  completed:         "bg-emerald-100 text-emerald-700",
  cancelled:         "bg-red-100 text-red-700",
};

function buildClinicActionButtons(appt) {
  const id = appt.id;
  const s  = appt.status;

  const btn = (label, action, cls) =>
    `<button type="button" onclick="window.__clinicAction('${action}',${id})" class="${cls} rounded-xl px-3 py-1.5 text-xs font-semibold transition">${escapeHtml(label)}</button>`;

  const primary = "bg-[#315b7e] text-white hover:bg-[#274864]";
  const danger  = "border border-red-200 bg-red-50 text-red-600 hover:bg-red-100";
  const outline = "border border-slate-200 bg-white text-slate-600 hover:bg-slate-50";

  const parts = [];
  if (s === "waiting_to_arrive") { parts.push(btn("Check In", "check-in", primary)); parts.push(btn("Cancel", "cancel", danger)); }
  if (s === "checked_in")        { parts.push(btn("Start Consultation", "start-consultation", primary)); parts.push(btn("Record", "record", outline)); parts.push(btn("Cancel", "cancel", danger)); }
  if (s === "in_consultation")   { parts.push(btn("Finish Consultation", "finish-consultation", primary)); parts.push(btn("Record", "record", outline)); }
  if (s === "for_payment")       { parts.push(btn("Process Payment", "pay", primary)); parts.push(btn("Record", "record", outline)); }
  if (s === "completed")         { parts.push(btn("View Record", "record", outline)); }
  return parts.join("");
}

function buildClinicCard(appt) {
  const pet     = appt.pet || {};
  const typeBadge = appt.appointment_type === "walk_in"
    ? `<span class="rounded-full bg-orange-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-orange-600">Walk-in</span>`
    : `<span class="rounded-full bg-teal-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-teal-600">Pre-reg</span>`;
  const statusBadge = `<span class="rounded-full px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide ${STATUS_BADGE[appt.status] || ''}">${STATUS_LABEL[appt.status] || appt.status}</span>`;

  let timeline = "";
  if (appt.checked_in_at)            timeline += `<p class="text-xs text-slate-400">Checked in: <span class="font-medium text-slate-600">${formatTime(appt.checked_in_at)}</span></p>`;
  if (appt.consultation_started_at)  timeline += `<p class="text-xs text-slate-400">Consult started: <span class="font-medium text-slate-600">${formatTime(appt.consultation_started_at)}</span></p>`;
  if (appt.consultation_finished_at) timeline += `<p class="text-xs text-slate-400">Consult ended: <span class="font-medium text-slate-600">${formatTime(appt.consultation_finished_at)}</span></p>`;

  const petSection = pet.name
    ? `<div class="mb-3 rounded-xl border border-slate-100 bg-slate-50 px-3 py-2">
         <p class="text-xs font-semibold text-slate-500">Patient</p>
         <p class="text-sm font-semibold text-[#2f4b66]">${escapeHtml(pet.name)}</p>
         <p class="text-xs text-slate-400">${escapeHtml(pet.species || "")}${pet.breed ? " · " + escapeHtml(pet.breed) : ""}${pet.weight ? " · " + pet.weight + " kg" : ""}</p>
       </div>` : "";

  const complaintSection = appt.chief_complaint
    ? `<div class="mb-3 rounded-xl border border-slate-100 bg-slate-50 px-3 py-2">
         <p class="text-xs font-semibold text-slate-500">Chief Complaint</p>
         <p class="text-sm text-slate-700">${escapeHtml(appt.chief_complaint)}</p>
       </div>` : "";

  return `<article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
    <div class="mb-3 flex items-start justify-between gap-2">
      <div>
        <div class="flex flex-wrap items-center gap-2">
          <span class="text-base font-bold text-[#2f4b66]">${escapeHtml(appt.ownerName)}</span>
          ${typeBadge}
        </div>
        <p class="mt-0.5 text-xs text-slate-400">${escapeHtml(appt.appointment_reference)}</p>
      </div>
      <div class="flex shrink-0 flex-col items-end gap-1">
        ${statusBadge}
        <span class="text-xs font-bold text-[#315b7e]">#${appt.queue_number}</span>
      </div>
    </div>
    ${petSection}${complaintSection}
    ${timeline ? `<div class="mb-3 space-y-0.5">${timeline}</div>` : ""}
    <div class="flex flex-wrap gap-2">${buildClinicActionButtons(appt)}</div>
  </article>`;
}

// ── Main component ────────────────────────────────────────────────────────────

function adminClinic() {
  return {
    loading: true,
    error:   "",
    activeTab: "queued",

    incoming:       [],
    queued:         [],
    inConsultation: [],
    forPayment:     [],
    completed:      [],

    tabs: [
      { key: "incoming",     label: "Incoming" },
      { key: "queued",       label: "In Queue" },
      { key: "consultation", label: "In Consultation" },
      { key: "payment",      label: "For Payment" },
      { key: "completed",    label: "Completed" },
    ],

    todayLabel: new Date().toLocaleDateString("en-PH", { weekday: "long", month: "long", day: "numeric" }),

    init() {
      this.checkAuth();
      this.loadQueue();
      this.bindGlobalActions();
    },

    checkAuth() {
      const token = API.getAdminToken?.();
      const role  = API.getUserRole?.();
      if (!token || (role !== "admin" && role !== "staff")) {
        window.location.href = "../client/sign-in.html";
      }
    },

    async loadQueue() {
      this.loading = true;
      this.error   = "";
      try {
        const data = await API.getClinicAppointments();
        this.incoming       = data.incoming        || [];
        this.queued         = data.queued          || [];
        this.inConsultation = data.in_consultation || [];
        this.forPayment     = data.for_payment     || [];
        this.completed      = data.completed       || [];
      } catch (e) {
        this.error = e.message || "Failed to load clinic queue.";
      } finally {
        this.loading = false;
      }
    },

    tabCount(key) {
      return {
        incoming:     this.incoming.length,
        queued:       this.queued.length,
        consultation: this.inConsultation.length,
        payment:      this.forPayment.length,
        completed:    this.completed.length,
      }[key] || 0;
    },

    cardHtml(appt) {
      return buildClinicCard(appt);
    },

    listFor(key) {
      return {
        incoming:     this.incoming,
        queued:       this.queued,
        consultation: this.inConsultation,
        payment:      this.forPayment,
        completed:    this.completed,
      }[key] || [];
    },

    emptyLabel(key) {
      return {
        incoming:     "No incoming appointments right now.",
        queued:       "Queue is empty.",
        consultation: "No active consultations.",
        payment:      "No appointments awaiting payment.",
        completed:    "No completed visits today.",
      }[key] || "No appointments.";
    },

    bindGlobalActions() {
      const self = this;
      window.__clinicAction = async (action, id) => {
        const appt = self.findAppt(id);
        if (action === "record") {
          window.dispatchEvent(new CustomEvent("clinic-open-modal", { detail: { appt } }));
          return;
        }
        if (action === "pay") {
          window.dispatchEvent(new CustomEvent("clinic-open-pay-modal", { detail: { appt } }));
          return;
        }
        if (action === "cancel" && !confirm("Cancel this appointment?")) return;
        try {
          if (action === "check-in")           await API.clinicCheckIn(id);
          if (action === "start-consultation")  await API.clinicStartConsultation(id);
          if (action === "finish-consultation") await API.clinicFinishConsultation(id);
          if (action === "cancel")              await API.clinicCancel(id);
          await self.loadQueue();
          if (window.lucide) window.lucide.createIcons();
        } catch (e) {
          alert(e.message || "Action failed. Please try again.");
        }
      };

      window.__clinicReload = async () => {
        await self.loadQueue();
        if (window.lucide) window.lucide.createIcons();
      };
    },

    findAppt(id) {
      return [
        ...this.incoming, ...this.queued,
        ...this.inConsultation, ...this.forPayment, ...this.completed,
      ].find((a) => a.id === id) || null;
    },
  };
}

// ── Medical Record Modal ──────────────────────────────────────────────────────

function adminClinicModal() {
  return {
    open:       false,
    saving:     false,
    modalError: "",
    title:      "Medical Record",
    apptId:     null,
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

    openModal({ appt } = {}) {
      if (!appt) return;
      this.apptId     = appt.id;
      this.modalError = "";
      this.title      = `Record — ${appt.ownerName}${appt.pet?.name ? " / " + appt.pet.name : ""}`;
      const r = appt.record  || {};
      const v = appt.vitals  || {};
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
    },

    addMedication() {
      this.form.medications.push({ drug_name: "", dosage: "", frequency: "", duration: "", instructions: "" });
    },

    removeMedication(idx) {
      this.form.medications.splice(idx, 1);
    },

    async saveRecord() {
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
        };
        await API.clinicSaveRecord(this.apptId, payload);
        this.open = false;
        window.__clinicReload?.();
      } catch (e) {
        this.modalError = e.message || "Failed to save record.";
      } finally {
        this.saving = false;
      }
    },
  };
}

// ── Payment Modal ─────────────────────────────────────────────────────────────

function adminClinicPayModal() {
  return {
    open:        false,
    paying:      false,
    payError:    "",
    apptId:      null,
    patientName: "",
    amount:      "",
    method:      "cash",

    init() {
      window.addEventListener("clinic-open-pay-modal", (e) => this.openModal(e.detail));
    },

    openModal({ appt } = {}) {
      if (!appt) return;
      this.apptId      = appt.id;
      this.patientName = `${appt.ownerName}${appt.pet?.name ? " / " + appt.pet.name : ""}`;
      this.amount      = appt.total_amount || "";
      this.method      = "cash";
      this.payError    = "";
      this.open        = true;
    },

    async submitPayment() {
      if (!this.amount && this.amount !== 0) {
        this.payError = "Please enter the total amount.";
        return;
      }
      this.paying   = true;
      this.payError = "";
      try {
        await API.clinicMarkPaid(this.apptId, {
          total_amount:   parseFloat(this.amount) || 0,
          payment_method: this.method,
        });
        this.open = false;
        window.__clinicReload?.();
      } catch (e) {
        this.payError = e.message || "Payment failed.";
      } finally {
        this.paying = false;
      }
    },
  };
}
