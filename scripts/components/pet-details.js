// Connected to pages/client/pet-details.html
// Depends on: api.js

document.addEventListener("DOMContentLoaded", () => {
  if (!API.getCustomerToken()) {
    window.location.href = "./sign-in.html";
    return;
  }

  const loadingState = document.getElementById("petLoadingState");
  const errorState = document.getElementById("petErrorState");
  const errorTitle = document.getElementById("petErrorTitle");
  const errorMessage = document.getElementById("petErrorMessage");
  const profileContent = document.getElementById("petProfileContent");
  const overviewGrid = document.getElementById("petOverviewGrid");
  const groomingRecords = document.getElementById("petGroomingRecords");
  const medicalRecords = document.getElementById("petMedicalRecords");
  const vaccinationRecords = document.getElementById("petVaccinationRecords");
  const concernNotifications = document.getElementById("petConcernNotifications");
  const concernDetail = document.getElementById("petConcernDetail");
  const bookGroomingLink = document.getElementById("bookGroomingLink");
  let medicalLoadState = "idle";
  let vaccinationLoadState = "idle";
  let concernLoadState = "idle";
  let requestedConcernHandled = false;
  let concernResponseSubmitting = false;

  const profileParams = new URLSearchParams(window.location.search);
  const petIdParam = profileParams.get("pet_id");
  const petId = Number(petIdParam);
  const requestedTab = profileParams.get("tab");
  const requestedConcernPublicId = profileParams.get("concern");

  const escapeHtml = (value) => String(value ?? "")
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#039;");

  const isMissing = (value) => value === null || value === undefined || String(value).trim() === "";
  const displayValue = (value) => isMissing(value) ? "Not provided." : String(value);
  const isTrue = (value) => value === true || value === 1 || value === "1";

  const titleCase = (value) => {
    if (isMissing(value)) return "Not provided.";
    return String(value)
      .replaceAll("_", " ")
      .replace(/\b\w/g, (character) => character.toUpperCase());
  };

  const formatDate = (value) => {
    if (isMissing(value)) return "Not provided.";
    const date = new Date(`${value}T00:00:00`);
    if (Number.isNaN(date.getTime())) return String(value);
    return date.toLocaleDateString("en-PH", {
      year: "numeric",
      month: "long",
      day: "numeric",
    });
  };

  const formatDateTime = (value) => {
    if (isMissing(value)) return "Not provided.";
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return String(value);
    return date.toLocaleString("en-PH", {
      year: "numeric",
      month: "long",
      day: "numeric",
      hour: "numeric",
      minute: "2-digit",
    });
  };

  const formatWeight = (value) => isMissing(value) ? "Not provided." : `${value} kg`;

  const formatBoolean = (value) => {
    if (value === null || value === undefined || value === "") return "Not provided.";
    return isTrue(value) ? "Yes" : "No";
  };

  const setError = (title, message) => {
    loadingState?.classList.add("hidden");
    profileContent?.classList.add("hidden");
    errorTitle.textContent = title;
    errorMessage.textContent = message;
    errorState?.classList.remove("hidden");
  };

  const renderIcons = () => {
    if (window.lucide) window.lucide.createIcons();
  };

  const setupSidebar = () => {
    const sidebar = document.getElementById("clientSidebar");
    const toggle = document.getElementById("clientSidebarToggle");
    const close = document.getElementById("clientSidebarClose");
    const backdrop = document.getElementById("clientSidebarBackdrop");
    const mobileSidebarQuery = window.matchMedia("(max-width: 1180px)");
    const sidebarLinks = sidebar?.querySelectorAll("a") || [];

    const setOpen = (open) => {
      document.body.classList.toggle("client-sidebar-open", open);
      toggle?.setAttribute("aria-expanded", String(open));
      toggle?.setAttribute("aria-label", open ? "Close navigation menu" : "Open navigation menu");
    };

    const closeSidebar = () => setOpen(false);

    toggle?.addEventListener("click", () => {
      if (!mobileSidebarQuery.matches) return;
      setOpen(!document.body.classList.contains("client-sidebar-open"));
    });
    close?.addEventListener("click", () => setOpen(false));
    backdrop?.addEventListener("click", () => setOpen(false));
    sidebarLinks.forEach((link) => link.addEventListener("click", closeSidebar));
    document.addEventListener("keydown", (event) => {
      if (event.key === "Escape") closeSidebar();
    });
    mobileSidebarQuery.addEventListener("change", (event) => {
      if (!event.matches) closeSidebar();
    });
    setOpen(false);
  };

  const setupTabs = () => {
    const tabs = document.querySelectorAll("[data-pet-tab]");
    const panels = document.querySelectorAll("[data-pet-panel]");
    const validTabs = new Set(
      Array.from(tabs).map((tab) => tab.dataset.petTab),
    );

    const activateTab = (selected) => {
      if (!validTabs.has(selected)) return;

      tabs.forEach((candidate) => {
        const active = candidate.dataset.petTab === selected;
        candidate.setAttribute("aria-selected", String(active));
        candidate.classList.toggle("bg-[#315b7e]", active);
        candidate.classList.toggle("text-white", active);
        candidate.classList.toggle("shadow-sm", active);
        candidate.classList.toggle("text-[#2f4b66]", !active);
        candidate.classList.toggle("hover:bg-slate-50", !active);
      });

      panels.forEach((panel) => {
        panel.classList.toggle("hidden", panel.dataset.petPanel !== selected);
      });

      if (selected === "medical") {
        loadMedicalRecords();
      }
      if (selected === "vaccinations") {
        loadVaccinations();
      }
      if (selected === "notifications") {
        loadConcernNotifications();
      }
    };

    tabs.forEach((tab) => {
      tab.addEventListener("click", () => activateTab(tab.dataset.petTab));
    });

    activateTab(validTabs.has(requestedTab) ? requestedTab : "overview");
  };

  const loadProfile = async () => {
    try {
      const { user } = await API.getMe("customer");
      const firstName = user?.first_name || "";
      const lastName = user?.last_name || "";
      const fullName = `${firstName} ${lastName}`.trim() || "Pet Owner";
      const initials = `${firstName.charAt(0)}${lastName.charAt(0)}`.toUpperCase() || "--";

      document.getElementById("clientProfileName").textContent = fullName;
      document.getElementById("clientProfileInitials").textContent = initials;
    } catch {
      // The central API layer handles expired customer sessions.
    }
  };

  const renderOverview = (pet) => {
    const archived = isTrue(pet.is_archived);
    const details = [
      ["Pet Name", displayValue(pet.pet_name)],
      ["Species", titleCase(pet.species)],
      ["Breed", displayValue(pet.breed)],
      ["Gender", titleCase(pet.gender)],
      ["Birthdate", formatDate(pet.birthdate)],
      ["Size", titleCase(pet.size)],
      ["Weight", formatWeight(pet.weight)],
      ["Fur Type", titleCase(pet.fur_type)],
      ["Color", displayValue(pet.color)],
      ["Neutered / Spayed", formatBoolean(pet.is_neutered)],
      ["Profile Status", archived ? "Archived" : "Active"],
    ];

    overviewGrid.innerHTML = details.map(([label, value]) => `
      <div class="rounded-2xl border border-slate-100 bg-slate-50 px-4 py-4">
        <p class="text-xs font-bold uppercase tracking-[0.12em] text-slate-400">${escapeHtml(label)}</p>
        <p class="mt-1.5 break-words font-semibold text-slate-700">${escapeHtml(value)}</p>
      </div>
    `).join("");

    document.getElementById("petMedicalConditions").textContent = displayValue(pet.medical_conditions);
  };

  const statusDetails = (status) => {
    const statuses = {
      waiting_to_arrive: ["Scheduled", "bg-blue-50 text-blue-700"],
      checked_in: ["Checked In", "bg-amber-50 text-amber-700"],
      in_progress: ["Being Groomed", "bg-violet-50 text-violet-700"],
      grooming_finished: ["Grooming Finished", "bg-emerald-50 text-emerald-700"],
      for_payment: ["For Payment", "bg-orange-50 text-orange-700"],
      for_pickup: ["Ready for Pickup", "bg-cyan-50 text-cyan-700"],
      released: ["Released", "bg-emerald-50 text-emerald-700"],
      archived: ["Completed", "bg-slate-100 text-slate-700"],
      cancelled: ["Cancelled", "bg-red-50 text-red-700"],
      no_show: ["No Show", "bg-red-50 text-red-700"],
    };
    return statuses[status] || [titleCase(status), "bg-slate-100 text-slate-700"];
  };

  const renderGroomingRecord = ({ booking, pet }) => {
    const [statusLabel, statusClasses] = statusDetails(pet.grooming_status || booking.status);
    const services = Array.isArray(pet.services)
      ? pet.services.map((service) => service.service_name).filter((name) => !isMissing(name))
      : [];

    const items = [
      ["Grooming Date", formatDate(booking.booking_date)],
      ["Arrival Window", displayValue(booking.time_window?.window_label)],
      ["Grooming Started", displayValue(pet.grooming_started_at)],
      ["Grooming Finished", displayValue(pet.grooming_finished_at)],
      ["Selected Services", services.length ? services.join(", ") : "Not provided."],
      ["Payment Status", booking.paid ? "Paid" : "Unpaid"],
    ];

    return `
      <article class="rounded-2xl border border-slate-200 bg-slate-50 p-5">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
          <div>
            <p class="text-xs font-bold uppercase tracking-[0.12em] text-slate-400">Booking Reference</p>
            <h4 class="mt-1 text-lg font-bold text-[#2f4b66]">${escapeHtml(displayValue(booking.booking_reference))}</h4>
          </div>
          <span class="w-fit rounded-full px-3 py-1 text-xs font-bold ${statusClasses}">${escapeHtml(statusLabel)}</span>
        </div>
        <dl class="mt-5 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
          ${items.map(([label, value]) => `
            <div>
              <dt class="text-xs font-semibold uppercase tracking-wide text-slate-400">${escapeHtml(label)}</dt>
              <dd class="mt-1 break-words text-sm font-medium text-slate-700">${escapeHtml(value)}</dd>
            </div>
          `).join("")}
        </dl>
      </article>
    `;
  };

  const loadGrooming = async () => {
    try {
      const data = await API.getBookingHistory({ petId });
      const bookings = [...(data.bookings || []), ...(data.history || [])];
      const records = bookings.map((booking) => {
        const pet = (booking.pets || []).find((item) => Number(item.pet_id) === petId);
        return pet ? { booking, pet } : null;
      }).filter(Boolean);

      records.sort((left, right) => {
        const byDate = String(right.booking.booking_date || "").localeCompare(String(left.booking.booking_date || ""));
        return byDate || Number(right.booking.booking_id || 0) - Number(left.booking.booking_id || 0);
      });

      if (!records.length) {
        groomingRecords.innerHTML = `
          <div class="rounded-2xl border border-dashed border-slate-200 px-6 py-12 text-center">
            <i data-lucide="scissors" class="mx-auto h-8 w-8 text-slate-300"></i>
            <h4 class="mt-3 font-bold text-slate-700">No grooming records found</h4>
            <p class="mt-1 text-sm text-slate-500">This pet does not have grooming activity yet.</p>
          </div>
        `;
      } else {
        groomingRecords.innerHTML = `<div class="space-y-4">${records.map(renderGroomingRecord).join("")}</div>`;
      }
    } catch (error) {
      groomingRecords.innerHTML = `
        <div class="rounded-2xl border border-red-100 bg-red-50 px-6 py-10 text-center">
          <i data-lucide="circle-alert" class="mx-auto h-8 w-8 text-red-400"></i>
          <h4 class="mt-3 font-bold text-red-800">Grooming records could not be loaded</h4>
          <p class="mt-1 text-sm text-red-600">${escapeHtml(error?.message || "Please try again later.")}</p>
        </div>
      `;
    }

    renderIcons();
  };

  const renderMedicalRecord = (record) => {
    const summaryItems = [
      ["Reason for Visit", displayValue(record.chief_complaint)],
      ["Final Diagnosis", displayValue(record.diagnosis)],
      ["Treatment", displayValue(record.treatment_given)],
    ];
    const vitalItems = [
      ["Weight", isMissing(record.vitals?.weight_kg) ? null : `${record.vitals.weight_kg} kg`],
      ["Temperature", isMissing(record.vitals?.temperature_c) ? null : `${record.vitals.temperature_c} °C`],
      ["Heart Rate", isMissing(record.vitals?.heart_rate_bpm) ? null : `${record.vitals.heart_rate_bpm} bpm`],
      ["Respiratory Rate", isMissing(record.vitals?.respiratory_rate_bpm) ? null : `${record.vitals.respiratory_rate_bpm} breaths/min`],
      ["Body Condition Score", isMissing(record.vitals?.body_condition_score) ? null : `${record.vitals.body_condition_score} / 9`],
    ].filter(([, value]) => !isMissing(value));
    const medications = Array.isArray(record.medications) ? record.medications : [];
    const hasFollowUp = !isMissing(record.follow_up_date) || !isMissing(record.follow_up_notes);

    return `
      <article class="overflow-hidden rounded-2xl border border-slate-200 bg-slate-50">
        <div class="border-b border-slate-200 bg-white px-5 py-4">
          <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div>
              <p class="text-xs font-bold uppercase tracking-[0.12em] text-slate-400">Appointment Reference</p>
              <h4 class="mt-1 text-lg font-bold text-[#2f4b66]">${escapeHtml(displayValue(record.appointment_reference))}</h4>
              <p class="mt-1 flex items-center gap-1.5 text-sm text-slate-500">
                <i data-lucide="calendar-days" class="h-4 w-4"></i>
                <span>${escapeHtml(formatDate(record.appointment_date))}</span>
              </p>
            </div>
            <span class="w-fit rounded-full bg-emerald-50 px-3 py-1 text-xs font-bold text-emerald-700">${escapeHtml(titleCase(record.status))}</span>
          </div>
        </div>

        <div class="space-y-5 p-5">
          <dl class="grid grid-cols-1 gap-4 lg:grid-cols-3">
            ${summaryItems.map(([label, value]) => `
              <div>
                <dt class="text-xs font-semibold uppercase tracking-wide text-slate-400">${escapeHtml(label)}</dt>
                <dd class="mt-1 whitespace-pre-line break-words text-sm leading-6 text-slate-700">${escapeHtml(value)}</dd>
              </div>
            `).join("")}
          </dl>

          ${hasFollowUp ? `
            <section class="rounded-2xl border border-[#cfe0ee] bg-[#eef5fb] p-4">
              <div class="flex items-start gap-3">
                <i data-lucide="calendar-clock" class="mt-0.5 h-5 w-5 shrink-0 text-[#315b7e]"></i>
                <div>
                  <h5 class="font-bold text-[#2f4b66]">Follow-up</h5>
                  <p class="mt-1 text-sm text-slate-600"><span class="font-semibold">Date:</span> ${escapeHtml(formatDate(record.follow_up_date))}</p>
                  <p class="mt-1 whitespace-pre-line text-sm leading-6 text-slate-600">${escapeHtml(displayValue(record.follow_up_notes))}</p>
                </div>
              </div>
            </section>
          ` : ""}

          ${vitalItems.length ? `
            <section>
              <h5 class="flex items-center gap-2 font-bold text-[#2f4b66]">
                <i data-lucide="activity" class="h-4 w-4"></i>
                Vital Signs
              </h5>
              <dl class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-5">
                ${vitalItems.map(([label, value]) => `
                  <div class="rounded-xl border border-slate-200 bg-white px-3 py-3">
                    <dt class="text-xs font-semibold text-slate-400">${escapeHtml(label)}</dt>
                    <dd class="mt-1 text-sm font-bold text-slate-700">${escapeHtml(value)}</dd>
                  </div>
                `).join("")}
              </dl>
            </section>
          ` : ""}

          ${medications.length ? `
            <section>
              <h5 class="flex items-center gap-2 font-bold text-[#2f4b66]">
                <i data-lucide="pill" class="h-4 w-4"></i>
                Prescribed Medications
              </h5>
              <div class="mt-3 grid grid-cols-1 gap-3 lg:grid-cols-2">
                ${medications.map((medication) => {
                  const details = [
                    ["Dosage", medication.dosage],
                    ["Frequency", medication.frequency],
                    ["Duration", medication.duration],
                    ["Instructions", medication.instructions],
                  ].filter(([, value]) => !isMissing(value));

                  return `
                    <div class="rounded-xl border border-slate-200 bg-white p-4">
                      <p class="font-bold text-slate-800">${escapeHtml(displayValue(medication.drug_name))}</p>
                      ${details.length ? `
                        <dl class="mt-3 space-y-2">
                          ${details.map(([label, value]) => `
                            <div class="text-sm">
                              <dt class="inline font-semibold text-slate-500">${escapeHtml(label)}:</dt>
                              <dd class="inline whitespace-pre-line text-slate-700"> ${escapeHtml(value)}</dd>
                            </div>
                          `).join("")}
                        </dl>
                      ` : ""}
                    </div>
                  `;
                }).join("")}
              </div>
            </section>
          ` : ""}
        </div>
      </article>
    `;
  };

  const loadMedicalRecords = async () => {
    if (!medicalRecords || medicalLoadState === "loading" || medicalLoadState === "loaded") return;

    medicalLoadState = "loading";
    medicalRecords.innerHTML = `
      <div class="py-12 text-center">
        <i data-lucide="loader" class="mx-auto h-8 w-8 animate-spin text-slate-300"></i>
        <p class="mt-3 text-sm text-slate-500">Loading completed medical records...</p>
      </div>
    `;
    renderIcons();

    try {
      const data = await API.getPetMedicalRecords(petId);
      const records = Array.isArray(data.medical_records) ? data.medical_records : [];

      if (!records.length) {
        medicalRecords.innerHTML = `
          <div class="rounded-2xl border border-dashed border-slate-200 px-6 py-12 text-center">
            <i data-lucide="clipboard-heart" class="mx-auto h-8 w-8 text-slate-300"></i>
            <h4 class="mt-3 font-bold text-slate-700">No completed medical records</h4>
            <p class="mt-1 text-sm text-slate-500">No completed medical records are available for this pet yet.</p>
          </div>
        `;
      } else {
        medicalRecords.innerHTML = `<div class="space-y-4">${records.map(renderMedicalRecord).join("")}</div>`;
      }

      medicalLoadState = "loaded";
    } catch (error) {
      medicalLoadState = "error";

      if (error?.status === 404) {
        medicalRecords.innerHTML = `
          <div class="rounded-2xl border border-amber-100 bg-amber-50 px-6 py-10 text-center" role="alert">
            <i data-lucide="shield-alert" class="mx-auto h-8 w-8 text-amber-500"></i>
            <h4 class="mt-3 font-bold text-amber-900">Pet profile not found</h4>
            <p class="mt-1 text-sm text-amber-700">This pet does not exist or is not available for your account.</p>
          </div>
        `;
      } else {
        medicalRecords.innerHTML = `
          <div class="rounded-2xl border border-red-100 bg-red-50 px-6 py-10 text-center" role="alert">
            <i data-lucide="circle-alert" class="mx-auto h-8 w-8 text-red-400"></i>
            <h4 class="mt-3 font-bold text-red-800">Medical records could not be loaded</h4>
            <p class="mt-1 text-sm text-red-600">${escapeHtml(error?.message || "Please try again later.")}</p>
          </div>
        `;
      }
    }

    renderIcons();
  };

  const vaccinationStatusDetails = (status) => {
    const statuses = {
      current: {
        label: "Current",
        classes: "border-emerald-200 bg-emerald-50 text-emerald-700",
        message: "",
      },
      due_soon: {
        label: "Due soon",
        classes: "border-amber-200 bg-amber-50 text-amber-800",
        message: "",
      },
      overdue: {
        label: "Overdue",
        classes: "border-red-200 bg-red-50 text-red-700",
        message: "The recorded next-due date has passed. Contact the clinic for guidance.",
      },
      unknown: {
        label: "Unknown",
        classes: "border-slate-200 bg-slate-50 text-slate-600",
        message: "The clinic did not provide a next-due date for this vaccination.",
      },
    };

    return statuses[status] || statuses.unknown;
  };

  const vaccinationDose = (record) => {
    if (isMissing(record.dose_amount)) return null;
    return `${record.dose_amount}${isMissing(record.dose_unit) ? "" : ` ${record.dose_unit}`}`;
  };

  const renderVaccinationRecord = (record) => {
    const status = vaccinationStatusDetails(record.due_status);
    const optionalDetails = [
      ["Product Name", record.product_name],
      ["Manufacturer", record.manufacturer],
      ["Dose", vaccinationDose(record)],
      ["Route", isMissing(record.route) ? null : titleCase(record.route)],
      ["Batch Number", record.batch_number],
      ["Administration Site", record.administration_site],
      ["Appointment Reference", record.appointment_reference],
      ["Vaccine Product / Batch Expiration Date", isMissing(record.product_expiry_date) ? null : formatDate(record.product_expiry_date)],
    ].filter(([, value]) => !isMissing(value));

    return `
      <article class="overflow-hidden rounded-2xl border border-slate-200 bg-slate-50">
        <div class="border-b border-slate-200 bg-white px-5 py-4">
          <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div>
              <p class="text-xs font-bold uppercase tracking-[0.12em] text-slate-400">Vaccine</p>
              <h4 class="mt-1 break-words text-lg font-bold text-[#2f4b66]">${escapeHtml(displayValue(record.vaccine_name))}</h4>
            </div>
            <span
              class="w-fit rounded-full border px-3 py-1 text-xs font-bold ${status.classes}"
              aria-label="Vaccination due status: ${escapeHtml(status.label)}"
            >${escapeHtml(status.label)}</span>
          </div>
          ${status.message ? `
            <p class="mt-3 rounded-xl border border-slate-100 bg-slate-50 px-3 py-2 text-sm leading-6 text-slate-600">
              ${escapeHtml(status.message)}
            </p>
          ` : ""}
        </div>

        <div class="space-y-5 p-5">
          <dl class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <div>
              <dt class="text-xs font-semibold uppercase tracking-wide text-slate-400">Administration Date</dt>
              <dd class="mt-1 text-sm font-medium text-slate-700">${escapeHtml(formatDate(record.administered_date))}</dd>
            </div>
            <div>
              <dt class="text-xs font-semibold uppercase tracking-wide text-slate-400">Next Due Date</dt>
              <dd class="mt-1 text-sm font-medium text-slate-700">${escapeHtml(formatDate(record.next_due_date))}</dd>
            </div>
            <div>
              <dt class="text-xs font-semibold uppercase tracking-wide text-slate-400">Administering Provider</dt>
              <dd class="mt-1 break-words text-sm font-medium text-slate-700">${escapeHtml(displayValue(record.administering_provider))}</dd>
            </div>
          </dl>

          ${optionalDetails.length ? `
            <div class="border-t border-slate-200 pt-4">
              <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
                ${optionalDetails.map(([label, value]) => `
                  <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-slate-400">${escapeHtml(label)}</dt>
                    <dd class="mt-1 break-words text-sm text-slate-700">${escapeHtml(value)}</dd>
                  </div>
                `).join("")}
              </dl>
            </div>
          ` : ""}
        </div>
      </article>
    `;
  };

  const loadVaccinations = async ({ retry = false } = {}) => {
    if (
      !vaccinationRecords
      || vaccinationLoadState === "loading"
      || (!retry && vaccinationLoadState === "loaded")
    ) {
      return;
    }

    vaccinationLoadState = "loading";
    vaccinationRecords.innerHTML = `
      <div class="py-12 text-center" aria-live="polite">
        <i data-lucide="loader" class="mx-auto h-8 w-8 animate-spin text-slate-300"></i>
        <p class="mt-3 text-sm text-slate-500">Loading vaccination history...</p>
      </div>
    `;
    renderIcons();

    try {
      const data = await API.getPetVaccinations(petId);
      const records = Array.isArray(data.vaccinations) ? data.vaccinations : [];

      if (!records.length) {
        vaccinationRecords.innerHTML = `
          <div class="rounded-2xl border border-dashed border-slate-200 px-6 py-12 text-center">
            <i data-lucide="syringe" class="mx-auto h-8 w-8 text-slate-300"></i>
            <h4 class="mt-3 font-bold text-slate-700">No published vaccination records</h4>
            <p class="mt-1 text-sm text-slate-500">No published vaccination records are available for this pet yet.</p>
          </div>
        `;
      } else {
        vaccinationRecords.innerHTML = `<div class="space-y-4">${records.map(renderVaccinationRecord).join("")}</div>`;
      }

      vaccinationLoadState = "loaded";
    } catch (error) {
      vaccinationLoadState = "error";

      if (error?.status === 404) {
        vaccinationRecords.innerHTML = `
          <div class="rounded-2xl border border-amber-100 bg-amber-50 px-6 py-10 text-center" role="alert">
            <i data-lucide="shield-alert" class="mx-auto h-8 w-8 text-amber-500"></i>
            <h4 class="mt-3 font-bold text-amber-900">Pet profile not found</h4>
            <p class="mt-1 text-sm text-amber-700">This pet does not exist or is not available for your account.</p>
          </div>
        `;
      } else {
        vaccinationRecords.innerHTML = `
          <div class="rounded-2xl border border-red-100 bg-red-50 px-6 py-10 text-center" role="alert">
            <i data-lucide="circle-alert" class="mx-auto h-8 w-8 text-red-400"></i>
            <h4 class="mt-3 font-bold text-red-800">Vaccination history could not be loaded</h4>
            <p class="mt-1 text-sm text-red-600">${escapeHtml(error?.message || "Please try again later.")}</p>
            <button
              type="button"
              data-retry-vaccinations
              class="mt-4 inline-flex items-center gap-2 rounded-xl border border-red-200 bg-white px-4 py-2 text-sm font-semibold text-red-700 transition hover:bg-red-50"
            >
              <i data-lucide="refresh-cw" class="h-4 w-4"></i>
              Retry
            </button>
          </div>
        `;
        vaccinationRecords
          .querySelector("[data-retry-vaccinations]")
          ?.addEventListener("click", () => loadVaccinations({ retry: true }));
      }
    }

    renderIcons();
  };

  const concernSeverityClasses = (severity) => ({
    low: "border-sky-200 bg-sky-50 text-sky-700",
    moderate: "border-amber-200 bg-amber-50 text-amber-800",
    urgent: "border-red-200 bg-red-50 text-red-700",
  })[severity] || "border-slate-200 bg-slate-50 text-slate-600";

  const concernStatusClasses = (status) => ({
    open: "border-sky-200 bg-sky-50 text-sky-700",
    awaiting_customer: "border-amber-200 bg-amber-50 text-amber-800",
    referred_to_clinic: "border-violet-200 bg-violet-50 text-violet-700",
    under_clinic_review: "border-indigo-200 bg-indigo-50 text-indigo-700",
    resolved: "border-emerald-200 bg-emerald-50 text-emerald-700",
    cancelled: "border-slate-200 bg-slate-100 text-slate-600",
  })[status] || "border-slate-200 bg-slate-50 text-slate-600";

  const concernActionLabel = (action, apiLabel = null) => apiLabel || ({
    continue_with_observation: "Continue with observation",
    pause_grooming: "Pause grooming",
    stop_grooming: "Stop grooming",
  })[action] || titleCase(action);

  const requiredConcernAction = (concern) => {
    if (concern.required_customer_action === "consent") {
      return {
        title: "Your decision is required",
        message: "Open the concern details to approve or decline using your typed signature.",
      };
    }
    if (concern.required_customer_action === "acknowledgment") {
      return {
        title: "Acknowledgment required",
        message: "Open the concern details to confirm receipt and understanding. Reading this notice does not count as acknowledgment.",
      };
    }
    return null;
  };

  const concernUrgencyGuidance = (concern) => concern.severity === "urgent"
    ? `
      <p class="mt-3 rounded-xl border border-red-100 bg-red-50 px-4 py-3 text-sm leading-6 text-red-700">
        This concern has urgent workflow priority. It is not a final veterinary diagnosis. Contact the clinic if you need prompt guidance.
      </p>
    `
    : `
      <p class="mt-3 text-xs leading-5 text-slate-500">
        Severity describes workflow urgency and is not a final veterinary diagnosis.
      </p>
    `;

  const renderConcernSummary = (concern) => {
    const requiredAction = requiredConcernAction(concern);

    return `
      <article class="rounded-2xl border border-slate-200 bg-slate-50 p-4 sm:p-5">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
          <div class="min-w-0">
            <p class="text-xs font-bold uppercase tracking-[0.12em] text-slate-400">Concern notification</p>
            <p class="mt-1 text-sm text-slate-500">${escapeHtml(formatDateTime(concern.concern_date))}</p>
          </div>
          <div class="flex flex-wrap gap-2">
            <span class="rounded-full border px-3 py-1 text-xs font-bold ${concernSeverityClasses(concern.severity)}">
              ${escapeHtml(concern.severity_label || titleCase(concern.severity))}
            </span>
            <span class="rounded-full border px-3 py-1 text-xs font-bold ${concernStatusClasses(concern.status)}">
              ${escapeHtml(concern.status_label || titleCase(concern.status))}
            </span>
          </div>
        </div>

        <p class="mt-4 whitespace-pre-line break-words text-sm leading-6 text-slate-700">${escapeHtml(displayValue(concern.customer_message))}</p>

        <dl class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2">
          <div>
            <dt class="text-xs font-semibold uppercase tracking-wide text-slate-400">Recommended action</dt>
            <dd class="mt-1 text-sm font-semibold text-slate-700">${escapeHtml(concernActionLabel(concern.recommended_grooming_action, concern.recommended_grooming_action_label))}</dd>
            <p class="mt-1 text-xs text-slate-500">A recommendation only; it does not confirm the action was applied.</p>
          </div>
          <div>
            <dt class="text-xs font-semibold uppercase tracking-wide text-slate-400">Customer response</dt>
            <dd class="mt-1 text-sm font-semibold text-slate-700">${escapeHtml(concern.customer_response_status_label || titleCase(concern.customer_response_status))}</dd>
          </div>
        </dl>

        ${requiredAction ? `
          <div class="mt-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3">
            <p class="text-sm font-bold text-amber-900">${escapeHtml(requiredAction.title)}</p>
            <p class="mt-1 text-sm leading-6 text-amber-800">${escapeHtml(requiredAction.message)}</p>
          </div>
        ` : ""}

        ${concernUrgencyGuidance(concern)}

        <button
          type="button"
          data-open-concern="${escapeHtml(concern.public_id)}"
          class="mt-4 inline-flex min-h-11 items-center gap-2 rounded-xl bg-[#315b7e] px-4 py-2 text-sm font-semibold text-white transition hover:bg-[#274b69]"
        >
          <i data-lucide="eye" class="h-4 w-4"></i>
          View concern details
        </button>
      </article>
    `;
  };

  const concernDetailFields = (concern) => [
    ["Concern date", formatDateTime(concern.concern_date)],
    ["Customer notified", formatDateTime(concern.customer_notified_at)],
    ["Severity", concern.severity_label || titleCase(concern.severity)],
    ["Status", concern.status_label || titleCase(concern.status)],
    ["Recommended action", concernActionLabel(concern.recommended_grooming_action, concern.recommended_grooming_action_label)],
    ["Applied action", isMissing(concern.applied_grooming_action)
      ? "No applied action has been recorded."
      : concernActionLabel(concern.applied_grooming_action, concern.applied_grooming_action_label)],
    ["Acknowledgment required", formatBoolean(concern.acknowledgment_required)],
    ["Consent required", formatBoolean(concern.consent_required)],
    ["Customer response", concern.customer_response_status_label || titleCase(concern.customer_response_status)],
    ["Clinic appointment reference", displayValue(concern.clinic_appointment_reference)],
    ["Resolution time", formatDateTime(concern.resolved_at)],
  ];

  const concernResponseError = (error) => {
    const validationMessages = Object.values(error?.errors || {})
      .flat()
      .filter(Boolean);
    return validationMessages[0] || error?.message || "Your response could not be submitted. Please try again.";
  };

  const renderConcernResponseSection = (concern) => {
    const submitted = concern.submitted_response;

    if (submitted) {
      return `
        <section class="rounded-xl border border-emerald-200 bg-emerald-50 p-4" aria-live="polite">
          <h5 class="font-bold text-emerald-900">Response recorded</h5>
          <dl class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-3">
            <div>
              <dt class="text-xs font-semibold uppercase tracking-wide text-emerald-700">Response</dt>
              <dd class="mt-1 text-sm font-semibold text-emerald-900">${escapeHtml(submitted.decision_label || titleCase(submitted.decision))}</dd>
            </div>
            <div>
              <dt class="text-xs font-semibold uppercase tracking-wide text-emerald-700">Submitted by</dt>
              <dd class="mt-1 text-sm font-semibold text-emerald-900">${escapeHtml(displayValue(submitted.responded_by_name))}</dd>
            </div>
            <div>
              <dt class="text-xs font-semibold uppercase tracking-wide text-emerald-700">Submitted at</dt>
              <dd class="mt-1 text-sm font-semibold text-emerald-900">${escapeHtml(formatDateTime(submitted.responded_at))}</dd>
            </div>
          </dl>
          <p class="mt-3 text-sm leading-6 text-emerald-800">
            This response is permanent and cannot be edited. Staff must still apply any operational grooming action separately.
          </p>
        </section>
      `;
    }

    if (
      concern.required_customer_action !== "acknowledgment"
      && concern.required_customer_action !== "consent"
    ) {
      return "";
    }

    const statement = displayValue(concern.response_statement);
    const commonNotice = `
      <p class="mt-3 text-sm leading-6 text-amber-800">
        Opening or reading this notification is not a response. Once submitted, your response cannot be edited.
        No grooming action is applied automatically.
      </p>
      <div
        data-concern-response-error
        class="mt-3 hidden rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700"
        role="alert"
        tabindex="-1"
      ></div>
    `;

    if (concern.required_customer_action === "acknowledgment") {
      return `
        <section class="rounded-xl border border-amber-200 bg-amber-50 p-4">
          <h5 class="font-bold text-amber-900">Acknowledgment required</h5>
          <p class="mt-1 text-sm leading-6 text-amber-800">
            Acknowledgment confirms that you received and understood this notice.
          </p>
          <blockquote class="mt-3 whitespace-pre-line rounded-lg border border-amber-200 bg-white p-4 text-sm leading-6 text-slate-700">${escapeHtml(statement)}</blockquote>
          ${commonNotice}
          <button
            type="button"
            data-submit-concern-acknowledgment
            class="mt-4 inline-flex min-h-11 items-center justify-center rounded-xl bg-[#315b7e] px-5 py-2 text-sm font-semibold text-white transition hover:bg-[#274b69] disabled:cursor-not-allowed disabled:opacity-60"
          >
            Acknowledge notice
          </button>
        </section>
      `;
    }

    return `
      <section class="rounded-xl border border-amber-200 bg-amber-50 p-4">
        <h5 class="font-bold text-amber-900">Your consent decision is required</h5>
        <p class="mt-1 text-sm leading-6 text-amber-800">
          Approval accepts the proposed action. Decline refuses it. Neither choice means the action has already occurred.
        </p>
        <blockquote class="mt-3 whitespace-pre-line rounded-lg border border-amber-200 bg-white p-4 text-sm leading-6 text-slate-700">${escapeHtml(statement)}</blockquote>
        <label class="mt-4 block" for="concernSignatureName">
          <span class="text-sm font-bold text-amber-900">Typed signature name <span aria-hidden="true">*</span></span>
          <input
            id="concernSignatureName"
            data-concern-signature
            type="text"
            maxlength="200"
            autocomplete="name"
            required
            class="mt-2 min-h-11 w-full rounded-xl border border-amber-300 bg-white px-3 py-2 text-sm text-slate-700 outline-none transition focus:border-[#315b7e] focus:ring-2 focus:ring-[#315b7e]/20"
            aria-describedby="concernSignatureHelp"
          />
          <span id="concernSignatureHelp" class="mt-1 block text-xs leading-5 text-amber-800">
            Type your name for either approval or refusal.
          </span>
        </label>
        ${commonNotice}
        <div class="mt-4 flex flex-col gap-2 sm:flex-row">
          <button
            type="button"
            data-submit-concern-consent="approved"
            class="inline-flex min-h-11 items-center justify-center rounded-xl bg-emerald-700 px-5 py-2 text-sm font-semibold text-white transition hover:bg-emerald-800 disabled:cursor-not-allowed disabled:opacity-60"
          >
            Approve proposed action
          </button>
          <button
            type="button"
            data-submit-concern-consent="declined"
            class="inline-flex min-h-11 items-center justify-center rounded-xl border border-red-300 bg-white px-5 py-2 text-sm font-semibold text-red-700 transition hover:bg-red-50 disabled:cursor-not-allowed disabled:opacity-60"
          >
            Decline proposed action
          </button>
        </div>
      </section>
    `;
  };

  const attachConcernResponseActions = (concern) => {
    const errorBox = concernDetail.querySelector("[data-concern-response-error]");
    const responseButtons = Array.from(concernDetail.querySelectorAll(
      "[data-submit-concern-acknowledgment], [data-submit-concern-consent]",
    ));
    const signatureInput = concernDetail.querySelector("[data-concern-signature]");

    const showError = (message) => {
      if (!errorBox) return;
      errorBox.textContent = message;
      errorBox.classList.remove("hidden");
      errorBox.focus();
    };
    const setBusy = (busy) => {
      concernResponseSubmitting = busy;
      responseButtons.forEach((button) => {
        if (!button.dataset.defaultLabel) {
          button.dataset.defaultLabel = button.textContent.trim();
        }
        button.disabled = busy;
        button.setAttribute("aria-busy", String(busy));
        button.textContent = busy ? "Submitting..." : button.dataset.defaultLabel;
      });
      if (signatureInput) signatureInput.disabled = busy;
    };

    concernDetail
      .querySelector("[data-submit-concern-acknowledgment]")
      ?.addEventListener("click", async () => {
        if (concernResponseSubmitting) return;
        const confirmed = window.confirm(
          "Submit this acknowledgment? It will permanently confirm that you received and understood the notice.",
        );
        if (!confirmed) return;

        errorBox?.classList.add("hidden");
        setBusy(true);
        try {
          const response = await API.acknowledgePetMedicalConcern(
            petId,
            concern.public_id,
          );
          concernLoadState = "idle";
          await openConcernDetail(concern.public_id, {
            updateHistory: false,
            successMessage: response.message,
          });
        } catch (error) {
          setBusy(false);
          showError(concernResponseError(error));
        }
      });

    responseButtons
      .filter((button) => button.hasAttribute("data-submit-concern-consent"))
      .forEach((button) => {
        button.addEventListener("click", async () => {
          if (concernResponseSubmitting) return;
          const signatureName = signatureInput?.value.trim() || "";
          const decision = button.dataset.submitConcernConsent;

          if (!signatureName) {
            showError("Type your signature name before submitting your decision.");
            signatureInput?.focus();
            return;
          }

          const decisionLabel = decision === "approved" ? "Approve" : "Decline";
          const confirmed = window.confirm(
            `${decisionLabel} the proposed action? This decision and typed signature cannot be edited after submission.`,
          );
          if (!confirmed) return;

          errorBox?.classList.add("hidden");
          setBusy(true);
          try {
            const response = await API.submitPetMedicalConcernConsent(
              petId,
              concern.public_id,
              decision,
              signatureName,
            );
            concernLoadState = "idle";
            await openConcernDetail(concern.public_id, {
              updateHistory: false,
              successMessage: response.message,
            });
          } catch (error) {
            setBusy(false);
            showError(concernResponseError(error));
          }
        });
      });
  };

  const renderConcernDetail = (concern, successMessage = "") => {
    const requiredAction = requiredConcernAction(concern);
    concernResponseSubmitting = false;

    concernDetail.innerHTML = `
      <button
        type="button"
        data-close-concern-detail
        class="inline-flex min-h-11 items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-[#315b7e] hover:bg-slate-50"
      >
        <i data-lucide="arrow-left" class="h-4 w-4"></i>
        Back to notifications
      </button>

      <article class="mt-4 overflow-hidden rounded-2xl border border-slate-200 bg-slate-50">
        <header class="border-b border-slate-200 bg-white p-4 sm:p-5">
          <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div>
              <p class="text-xs font-bold uppercase tracking-[0.12em] text-slate-400">Medical-concern notification</p>
              <h4 class="mt-1 text-xl font-bold text-[#2f4b66]">${escapeHtml(displayValue(concern.pet_name))}</h4>
            </div>
            <div class="flex flex-wrap gap-2">
              <span class="rounded-full border px-3 py-1 text-xs font-bold ${concernSeverityClasses(concern.severity)}">
                ${escapeHtml(concern.severity_label || titleCase(concern.severity))}
              </span>
              <span class="rounded-full border px-3 py-1 text-xs font-bold ${concernStatusClasses(concern.status)}">
                ${escapeHtml(concern.status_label || titleCase(concern.status))}
              </span>
            </div>
          </div>
          ${concernUrgencyGuidance(concern)}
        </header>

        <div class="space-y-5 p-4 sm:p-5">
          ${successMessage ? `
            <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-800" role="status" aria-live="polite">
              ${escapeHtml(successMessage)}
            </div>
          ` : ""}

          <section>
            <h5 class="font-bold text-[#2f4b66]">Message from the grooming team</h5>
            <p class="mt-2 whitespace-pre-line break-words text-sm leading-6 text-slate-700">${escapeHtml(displayValue(concern.customer_message))}</p>
          </section>

          <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
            ${concernDetailFields(concern).map(([label, value]) => `
              <div>
                <dt class="text-xs font-semibold uppercase tracking-wide text-slate-400">${escapeHtml(label)}</dt>
                <dd class="mt-1 break-words text-sm font-medium leading-6 text-slate-700">${escapeHtml(value)}</dd>
              </div>
            `).join("")}
          </dl>

          <section class="rounded-xl border border-[#cfe0ee] bg-[#eef5fb] p-4">
            <h5 class="font-bold text-[#2f4b66]">Recommended and applied actions</h5>
            <p class="mt-2 text-sm leading-6 text-slate-600">
              The recommended action is staff guidance. It should not be treated as completed unless an applied action is shown above.
            </p>
          </section>

          ${requiredAction ? `
            <section class="rounded-xl border border-amber-200 bg-amber-50 p-4">
              <h5 class="font-bold text-amber-900">${escapeHtml(requiredAction.title)}</h5>
              <p class="mt-1 text-sm leading-6 text-amber-800">${escapeHtml(requiredAction.message)}</p>
            </section>
          ` : ""}

          ${renderConcernResponseSection(concern)}

          ${!isMissing(concern.customer_resolution_summary) ? `
            <section class="rounded-xl border border-emerald-200 bg-emerald-50 p-4">
              <h5 class="font-bold text-emerald-900">Customer-safe resolution</h5>
              <p class="mt-2 whitespace-pre-line text-sm leading-6 text-emerald-800">${escapeHtml(concern.customer_resolution_summary)}</p>
            </section>
          ` : ""}

          <p class="text-xs leading-5 text-slate-500">
            Opening or reading this view does not record acknowledgment or consent.
          </p>
        </div>
      </article>
    `;

    concernDetail.querySelector("[data-close-concern-detail]")
      ?.addEventListener("click", closeConcernDetail);
    attachConcernResponseActions(concern);
  };

  const closeConcernDetail = () => {
    concernDetail?.classList.add("hidden");
    concernNotifications?.classList.remove("hidden");
    const url = new URL(window.location.href);
    url.searchParams.set("tab", "notifications");
    url.searchParams.delete("concern");
    window.history.replaceState({}, "", url);
    if (concernLoadState === "idle") {
      loadConcernNotifications({ retry: true });
    }
    renderIcons();
  };

  const openConcernDetail = async (
    publicId,
    { updateHistory = true, successMessage = "" } = {},
  ) => {
    if (!concernDetail || !concernNotifications || !publicId) return;

    concernNotifications.classList.add("hidden");
    concernDetail.classList.remove("hidden");
    concernDetail.innerHTML = `
      <div class="py-12 text-center" role="status">
        <i data-lucide="loader" class="mx-auto h-8 w-8 animate-spin text-slate-300"></i>
        <p class="mt-3 text-sm text-slate-500">Loading concern details...</p>
      </div>
    `;
    renderIcons();

    try {
      const response = await API.getPetMedicalConcern(petId, publicId);
      renderConcernDetail(response.concern || {}, successMessage);

      if (updateHistory) {
        const url = new URL(window.location.href);
        url.searchParams.set("tab", "notifications");
        url.searchParams.set("concern", publicId);
        window.history.pushState({}, "", url);
      }
    } catch (error) {
      concernResponseSubmitting = false;
      const notFound = error?.status === 404;
      concernDetail.innerHTML = `
        <div class="rounded-2xl border ${notFound ? "border-amber-100 bg-amber-50" : "border-red-100 bg-red-50"} px-6 py-10 text-center" role="alert">
          <i data-lucide="${notFound ? "file-question" : "circle-alert"}" class="mx-auto h-8 w-8 ${notFound ? "text-amber-500" : "text-red-400"}"></i>
          <h4 class="mt-3 font-bold ${notFound ? "text-amber-900" : "text-red-800"}">${notFound ? "Medical concern not found" : "Concern details could not be loaded"}</h4>
          <p class="mt-1 text-sm ${notFound ? "text-amber-700" : "text-red-600"}">${notFound ? "This concern does not exist or is not available for this pet." : escapeHtml(error?.message || "Please try again later.")}</p>
          <div class="mt-4 flex flex-wrap justify-center gap-2">
            <button type="button" data-close-concern-detail class="inline-flex min-h-11 items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Back</button>
            ${notFound ? "" : `
              <button type="button" data-retry-concern-detail class="inline-flex min-h-11 items-center gap-2 rounded-xl bg-[#315b7e] px-4 py-2 text-sm font-semibold text-white hover:bg-[#274b69]">
                <i data-lucide="refresh-cw" class="h-4 w-4"></i>
                Retry
              </button>
            `}
          </div>
        </div>
      `;
      concernDetail.querySelector("[data-close-concern-detail]")
        ?.addEventListener("click", closeConcernDetail);
      concernDetail.querySelector("[data-retry-concern-detail]")
        ?.addEventListener("click", () => openConcernDetail(publicId, { updateHistory: false }));
    }

    renderIcons();
  };

  const attachConcernSummaryActions = () => {
    concernNotifications
      ?.querySelectorAll("[data-open-concern]")
      .forEach((button) => {
        button.addEventListener("click", () => {
          openConcernDetail(button.dataset.openConcern);
        });
      });
  };

  const loadConcernNotifications = async ({ retry = false } = {}) => {
    if (
      !concernNotifications
      || concernLoadState === "loading"
      || (!retry && concernLoadState === "loaded")
    ) {
      return;
    }

    concernLoadState = "loading";
    concernDetail?.classList.add("hidden");
    concernNotifications.classList.remove("hidden");
    concernNotifications.innerHTML = `
      <div class="py-12 text-center" role="status">
        <i data-lucide="loader" class="mx-auto h-8 w-8 animate-spin text-slate-300"></i>
        <p class="mt-3 text-sm text-slate-500">Loading medical-concern notifications...</p>
      </div>
    `;
    renderIcons();

    try {
      const response = await API.getPetMedicalConcerns(petId);
      const concerns = Array.isArray(response.concerns) ? response.concerns : [];

      if (!concerns.length) {
        concernNotifications.innerHTML = `
          <div class="rounded-2xl border border-dashed border-slate-200 px-6 py-12 text-center">
            <i data-lucide="bell-off" class="mx-auto h-8 w-8 text-slate-300"></i>
            <h4 class="mt-3 font-bold text-slate-700">No medical-concern notifications</h4>
            <p class="mt-1 text-sm text-slate-500">No medical-concern notifications are available for this pet.</p>
          </div>
        `;
      } else {
        concernNotifications.innerHTML = `
          <div class="space-y-4">
            ${concerns.map(renderConcernSummary).join("")}
          </div>
        `;
        attachConcernSummaryActions();
      }

      concernLoadState = "loaded";

      if (requestedConcernPublicId && !requestedConcernHandled) {
        requestedConcernHandled = true;
        await openConcernDetail(requestedConcernPublicId, {
          updateHistory: false,
        });
      }
    } catch (error) {
      concernLoadState = "error";
      const notFound = error?.status === 404;
      concernNotifications.innerHTML = `
        <div class="rounded-2xl border ${notFound ? "border-amber-100 bg-amber-50" : "border-red-100 bg-red-50"} px-6 py-10 text-center" role="alert">
          <i data-lucide="${notFound ? "shield-alert" : "circle-alert"}" class="mx-auto h-8 w-8 ${notFound ? "text-amber-500" : "text-red-400"}"></i>
          <h4 class="mt-3 font-bold ${notFound ? "text-amber-900" : "text-red-800"}">${notFound ? "Pet profile not found" : "Medical-concern notifications could not be loaded"}</h4>
          <p class="mt-1 text-sm ${notFound ? "text-amber-700" : "text-red-600"}">${notFound ? "This pet does not exist or is not available for your account." : escapeHtml(error?.message || "Please try again later.")}</p>
          ${notFound ? "" : `
            <button type="button" data-retry-concerns class="mt-4 inline-flex min-h-11 items-center gap-2 rounded-xl border border-red-200 bg-white px-4 py-2 text-sm font-semibold text-red-700 hover:bg-red-50">
              <i data-lucide="refresh-cw" class="h-4 w-4"></i>
              Retry
            </button>
          `}
        </div>
      `;
      concernNotifications
        .querySelector("[data-retry-concerns]")
        ?.addEventListener("click", () => loadConcernNotifications({ retry: true }));
    }

    renderIcons();
  };

  const loadPet = async () => {
    if (!petIdParam || !Number.isInteger(petId) || petId < 1) {
      setError("Invalid pet profile link", "Choose a pet from My Pets to open its profile.");
      return;
    }

    try {
      const { pet } = await API.getPet(petId);
      const archived = isTrue(pet.is_archived);
      const petName = displayValue(pet.pet_name);

      document.getElementById("pageTitle").textContent = isMissing(pet.pet_name) ? "Pet Profile" : `${pet.pet_name}'s Profile`;
      document.getElementById("pageSubtitle").textContent = "Grooming and clinic information in one shared profile.";
      document.getElementById("petHeroName").textContent = petName;
      document.getElementById("petHeroSpecies").textContent = titleCase(pet.species);
      document.getElementById("petStatusBadge").textContent = archived ? "Archived" : "Active";

      if (!archived) {
        bookGroomingLink?.classList.remove("hidden");
        bookGroomingLink?.classList.add("inline-flex");
      }

      renderOverview(pet);
      loadingState?.classList.add("hidden");
      errorState?.classList.add("hidden");
      profileContent?.classList.remove("hidden");
      renderIcons();
      await loadGrooming();
    } catch (error) {
      if (error?.status === 404) {
        setError(
          "Pet profile not found",
          "This pet does not exist or is not available for your account."
        );
      } else {
        setError(
          "Pet profile could not be loaded",
          error?.message || "Please try again later."
        );
      }
      renderIcons();
    }
  };

  document.getElementById("clientLogoutBtn")?.addEventListener("click", async () => {
    await API.logout("customer");
    window.location.href = "./sign-in.html";
  });

  setupSidebar();
  setupTabs();
  renderIcons();
  loadProfile();
  loadPet();
});
