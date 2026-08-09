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
  let requestedReferralHandled = false;
  let clinicReferralResponseSubmitting = false;
  let clinicReferralSignatureDraft = "";

  const profileParams = new URLSearchParams(window.location.search);
  const petIdParam = profileParams.get("pet_id");
  const petId = Number(petIdParam);
  const requestedTab = profileParams.get("tab");
  const requestedConcernPublicId = profileParams.get("concern");
  const requestedReferralPublicId = profileParams.get("referral");

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
  const formatPeso = (value) => new Intl.NumberFormat("en-PH", {
    style: "currency",
    currency: "PHP",
  }).format(Number(value || 0));

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
      referred_to_clinic: ["Referred to Clinic", "bg-violet-50 text-violet-700"],
      grooming_finished: ["Grooming Finished", "bg-emerald-50 text-emerald-700"],
      paused: ["Grooming Paused", "bg-amber-50 text-amber-800"],
      stopped: ["Grooming Stopped", "bg-red-50 text-red-700"],
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
    const review = pet.payment_review;
    const paymentReview = review ? `
      <section class="mt-5 rounded-2xl border border-amber-200 bg-amber-50 p-4" aria-label="Stopped grooming payment review">
        <div class="flex flex-wrap items-center justify-between gap-2">
          <h5 class="font-bold text-amber-900">Payment Review Completed</h5>
          <span class="rounded-full bg-white px-3 py-1 text-xs font-bold text-amber-800">${escapeHtml(displayValue(review.decision_label))}</span>
        </div>
        <dl class="mt-3 grid grid-cols-1 gap-3 text-sm sm:grid-cols-3">
          <div><dt class="font-semibold text-amber-800">Original amount</dt><dd class="mt-1 text-slate-700">${escapeHtml(formatPeso(review.original_amount))}</dd></div>
          <div><dt class="font-semibold text-amber-800">Final reviewed amount</dt><dd class="mt-1 text-slate-700">${escapeHtml(formatPeso(review.final_amount))}</dd></div>
          <div><dt class="font-semibold text-amber-800">Adjustment</dt><dd class="mt-1 text-slate-700">${escapeHtml(formatPeso(review.adjustment))}</dd></div>
        </dl>
        <p class="mt-3 text-sm text-slate-700">${escapeHtml(displayValue(review.customer_explanation))}</p>
        <p class="mt-2 text-xs text-slate-500">Reviewed ${escapeHtml(formatDateTime(review.reviewed_at))}</p>
      </section>
    ` : "";

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
        ${paymentReview}
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

  const renderConcernConsentConfirmation = (concern) => {
    if (
      concern.submitted_response
      || concern.required_customer_action !== "consent"
    ) {
      return "";
    }

    return `
      <div
        data-concern-consent-confirmation
        class="fixed inset-0 z-[70] hidden items-center justify-center overflow-y-auto bg-slate-900/60 p-4"
        role="alertdialog"
        aria-modal="true"
        aria-labelledby="concernConsentConfirmationTitle"
        aria-describedby="concernConsentConfirmationMessage"
      >
        <div
          data-concern-consent-confirmation-card
          class="my-auto w-full max-w-md overflow-hidden rounded-3xl border border-slate-100 bg-white shadow-2xl"
          tabindex="-1"
        >
          <header class="border-b border-slate-100 bg-[#f8fbfe] px-5 py-4 sm:px-6">
            <h4 id="concernConsentConfirmationTitle" data-concern-confirm-title class="text-lg font-bold text-[#2f4b66]">
              Confirm response
            </h4>
          </header>
          <div class="px-5 py-5 sm:px-6">
            <p id="concernConsentConfirmationMessage" data-concern-confirm-message class="text-sm leading-6 text-slate-600"></p>
            <div
              data-concern-confirm-error
              class="mt-4 hidden rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"
              role="alert"
              tabindex="-1"
            ></div>
            <div class="mt-5 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
              <button
                type="button"
                data-cancel-concern-consent-confirm
                class="min-h-11 rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-60"
              >
                Go back
              </button>
              <button
                type="button"
                data-submit-concern-consent-confirm
                class="min-h-11 rounded-xl bg-[#355c84] px-5 py-2 text-sm font-semibold text-white transition hover:bg-[#2d4f73] disabled:cursor-not-allowed disabled:opacity-60"
              >
                Confirm response
              </button>
            </div>
          </div>
        </div>
      </div>
    `;
  };

  const attachConcernResponseActions = (concern) => {
    const errorBox = concernDetail.querySelector("[data-concern-response-error]");
    const responseButtons = Array.from(concernDetail.querySelectorAll(
      "[data-submit-concern-acknowledgment], [data-submit-concern-consent]",
    ));
    const signatureInput = concernDetail.querySelector("[data-concern-signature]");
    const confirmation = concernDetail.querySelector("[data-concern-consent-confirmation]");
    const confirmationCard = concernDetail.querySelector("[data-concern-consent-confirmation-card]");
    const confirmationTitle = concernDetail.querySelector("[data-concern-confirm-title]");
    const confirmationMessage = concernDetail.querySelector("[data-concern-confirm-message]");
    const confirmationError = concernDetail.querySelector("[data-concern-confirm-error]");
    const confirmSubmit = concernDetail.querySelector("[data-submit-concern-consent-confirm]");
    const cancelConfirm = concernDetail.querySelector("[data-cancel-concern-consent-confirm]");
    let selectedDecision = "";
    let originatingButton = null;

    const showError = (message, target = errorBox) => {
      if (!target) return;
      target.textContent = message;
      target.classList.remove("hidden");
      target.focus();
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
      if (cancelConfirm) cancelConfirm.disabled = busy;
      if (confirmSubmit) {
        confirmSubmit.disabled = busy;
        confirmSubmit.textContent = busy
          ? "Submitting..."
          : (confirmSubmit.dataset.defaultLabel || "Confirm response");
      }
    };
    const closeConfirmation = ({ restoreFocus = true } = {}) => {
      if (concernResponseSubmitting) return;
      confirmation?.classList.add("hidden");
      confirmation?.classList.remove("flex");
      document.body.classList.remove("overflow-hidden");
      confirmationError?.classList.add("hidden");
      if (restoreFocus) originatingButton?.focus();
    };
    const openConfirmation = (decision, button) => {
      const approval = decision === "approved";
      selectedDecision = decision;
      originatingButton = button;
      confirmationTitle.textContent = approval ? "Confirm approval" : "Confirm decline";
      confirmationMessage.textContent = approval
        ? "Approve the proposed action? This decision and typed signature cannot be edited after submission."
        : "Decline the proposed action? This decision and typed signature cannot be edited after submission.";
      confirmSubmit.dataset.defaultLabel = approval ? "Confirm approval" : "Confirm decline";
      confirmSubmit.textContent = confirmSubmit.dataset.defaultLabel;
      confirmationError?.classList.add("hidden");
      confirmation?.classList.remove("hidden");
      confirmation?.classList.add("flex");
      document.body.classList.add("overflow-hidden");
      confirmationCard?.focus();
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

          errorBox?.classList.add("hidden");
          openConfirmation(decision, button);
        });
      });

    cancelConfirm?.addEventListener("click", () => closeConfirmation());
    confirmation?.addEventListener("click", (event) => {
      if (event.target === confirmation) closeConfirmation();
    });
    confirmation?.addEventListener("keydown", (event) => {
      if (event.key === "Escape") closeConfirmation();
    });

    confirmSubmit?.addEventListener("click", async () => {
      if (concernResponseSubmitting || !selectedDecision) return;
      const signatureName = signatureInput?.value.trim() || "";

      confirmationError?.classList.add("hidden");
      setBusy(true);
      try {
        const response = await API.submitPetMedicalConcernConsent(
          petId,
          concern.public_id,
          selectedDecision,
          signatureName,
        );
        concernResponseSubmitting = false;
        closeConfirmation({ restoreFocus: false });
        concernLoadState = "idle";
        await openConcernDetail(concern.public_id, {
          updateHistory: false,
          successMessage: response.message,
        });
      } catch (error) {
        setBusy(false);
        showError(concernResponseError(error), confirmationError);
      }
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
      ${renderConcernConsentConfirmation(concern)}
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
        url.searchParams.delete("referral");
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

  const clinicReferralStatusLabel = (referral) => ({
    pending_consent: "Pending Customer Consent",
    pending_clinic_acceptance: "Pending Clinic Acceptance",
    accepted: "Accepted by Clinic",
    under_clinic_review: "Under Clinic Review",
    completed: "Completed",
    cancelled: "Cancelled",
  })[referral.status] || referral.status_label || titleCase(referral.status);

  const clinicReferralUrgencyClasses = (urgency) => ({
    routine: "border-blue-200 bg-blue-50 text-blue-800",
    urgent: "border-amber-200 bg-amber-50 text-amber-800",
    emergency: "border-red-200 bg-red-50 text-red-800",
  })[urgency] || "border-slate-200 bg-slate-50 text-slate-700";

  const clinicReferralStatusClasses = (status) => ({
    pending_consent: "border-amber-200 bg-amber-50 text-amber-800",
    pending_clinic_acceptance: "border-blue-200 bg-blue-50 text-blue-800",
    accepted: "border-violet-200 bg-violet-50 text-violet-800",
    under_clinic_review: "border-indigo-200 bg-indigo-50 text-indigo-800",
    completed: "border-emerald-200 bg-emerald-50 text-emerald-800",
    cancelled: "border-slate-300 bg-slate-100 text-slate-700",
  })[status] || "border-slate-200 bg-slate-50 text-slate-700";

  const clinicReferralTerminal = (referral) => ["completed", "cancelled"].includes(
    String(referral.status || "").toLowerCase(),
  );

  const clinicReferralConsentRecorded = (referral) =>
    referral.consent_state === "recorded";

  const renderClinicReferralConsent = (referral) => {
    if (clinicReferralConsentRecorded(referral)) {
      return `
        <section class="rounded-xl border border-emerald-200 bg-emerald-50 p-4" aria-live="polite">
          <h5 class="font-bold text-emerald-900">Permanent response recorded</h5>
          <dl class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-3">
            <div>
              <dt class="text-xs font-semibold uppercase tracking-wide text-emerald-700">Decision</dt>
              <dd class="mt-1 text-sm font-semibold text-emerald-900">${escapeHtml(titleCase(referral.consent_decision))}</dd>
            </div>
            <div>
              <dt class="text-xs font-semibold uppercase tracking-wide text-emerald-700">Responding owner</dt>
              <dd class="mt-1 text-sm font-semibold text-emerald-900">${escapeHtml(displayValue(referral.consent_responded_by_name))}</dd>
            </div>
            <div>
              <dt class="text-xs font-semibold uppercase tracking-wide text-emerald-700">Responded at</dt>
              <dd class="mt-1 text-sm font-semibold text-emerald-900">${escapeHtml(formatDateTime(referral.consent_responded_at))}</dd>
            </div>
          </dl>
          <details class="mt-3 text-xs text-emerald-800">
            <summary class="cursor-pointer font-semibold">Audit statement version</summary>
            <p class="mt-1">${escapeHtml(displayValue(referral.consent_statement_version))}</p>
          </details>
          <p class="mt-3 text-sm leading-6 text-emerald-800">The original response is permanent and cannot be edited, replaced, or deleted.</p>
        </section>
      `;
    }

    if (!referral.consent_required || clinicReferralTerminal(referral)) return "";

    return `
      <section class="rounded-xl border border-amber-200 bg-amber-50 p-4" data-clinic-referral-consent-panel>
        <h5 class="font-bold text-amber-900">Your clinic-referral decision is required</h5>
        <p class="mt-1 text-sm leading-6 text-amber-800">
          Read the complete server-provided statement before choosing. No decision is selected for you.
        </p>
        <blockquote class="mt-3 whitespace-pre-line rounded-lg border border-amber-200 bg-white p-4 text-sm leading-6 text-slate-700">${escapeHtml(displayValue(referral.consent_statement))}</blockquote>
        <details class="mt-3 text-xs text-amber-800">
          <summary class="cursor-pointer font-semibold">Statement version</summary>
          <p class="mt-1">${escapeHtml(displayValue(referral.consent_statement_version))}</p>
        </details>
        <div class="mt-4 rounded-lg border border-blue-200 bg-blue-50 p-3 text-sm leading-6 text-blue-900">
          Approval covers referral creation, transfer to clinic intake, and an initial veterinary assessment. It does not automatically authorize diagnostics, medication, treatment, emergency procedures, additional clinic charges, or any other veterinary service.
        </div>
        <label class="mt-4 block" for="clinicReferralSignatureName">
          <span class="text-sm font-bold text-amber-900">Typed signature name <span aria-hidden="true">*</span></span>
          <input
            id="clinicReferralSignatureName"
            data-clinic-referral-signature
            type="text"
            maxlength="200"
            autocomplete="name"
            value="${escapeHtml(clinicReferralSignatureDraft)}"
            class="mt-2 min-h-11 w-full rounded-xl border border-amber-300 bg-white px-3 py-2 text-sm text-slate-700 outline-none transition focus:border-[#315b7e] focus:ring-2 focus:ring-[#315b7e]/20"
            aria-describedby="clinicReferralSignatureHelp clinicReferralResponseError"
          />
          <span id="clinicReferralSignatureHelp" class="mt-1 block text-xs leading-5 text-amber-800">This response is permanent after submission.</span>
        </label>
        <div id="clinicReferralResponseError" data-clinic-referral-response-error class="mt-3 hidden rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700" role="alert" tabindex="-1"></div>
        <div class="mt-4 flex flex-col gap-2 sm:flex-row">
          <button type="button" data-clinic-referral-decision="approved" class="inline-flex min-h-11 items-center justify-center rounded-xl bg-emerald-700 px-5 py-2 text-sm font-semibold text-white transition hover:bg-emerald-800 disabled:cursor-not-allowed disabled:opacity-60">Approve clinic referral</button>
          <button type="button" data-clinic-referral-decision="declined" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-red-300 bg-white px-5 py-2 text-sm font-semibold text-red-700 transition hover:bg-red-50 disabled:cursor-not-allowed disabled:opacity-60">Decline clinic referral</button>
        </div>
      </section>
    `;
  };

  const clinicReferralDetailFields = (referral) => [
    ["Booking reference", displayValue(referral.booking_reference)],
    ["Referral date", formatDateTime(referral.referred_at)],
    ["Status", clinicReferralStatusLabel(referral)],
    ["Urgency", referral.urgency_label || titleCase(referral.urgency)],
    ["Consent required", formatBoolean(referral.consent_required)],
    ["Consent state", clinicReferralConsentRecorded(referral)
      ? titleCase(referral.consent_decision)
      : "Waiting for Response"],
    ["Clinic acceptance", referral.clinic_accepted ? "Accepted" : "Not yet accepted"],
    ["Clinic appointment reference", displayValue(referral.clinic_appointment_reference)],
    ["Clinic appointment status", displayValue(referral.clinic_appointment_status_label)],
    ["Clinic appointment date", displayValue(referral.clinic_appointment_date)],
    ["Clinic assessment", referral.clinic_assessment_started ? "Started" : "Not started"],
    ["Assessment started", referral.clinic_assessment_started_at
      ? formatDateTime(referral.clinic_assessment_started_at)
      : "Not started"],
    ["Assessment completed", referral.clinic_assessment_completed_at
      ? formatDateTime(referral.clinic_assessment_completed_at)
      : "Not completed"],
    ["Grooming outcome", displayValue(referral.grooming_outcome_label)],
  ];

  const renderClinicReferralDetail = (referral, successMessage = "") => {
    clinicReferralResponseSubmitting = false;
    concernDetail.innerHTML = `
      <button type="button" data-close-clinic-referral class="inline-flex min-h-11 items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-[#315b7e] hover:bg-slate-50">
        <i data-lucide="arrow-left" class="h-4 w-4"></i>
        Back to notifications
      </button>
      <article class="mt-4 overflow-hidden rounded-2xl border border-slate-200 bg-slate-50">
        <header class="border-b border-slate-200 bg-white p-4 sm:p-5">
          <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div>
              <p class="text-xs font-bold uppercase tracking-[0.12em] text-slate-400">Clinic referral request</p>
              <h4 class="mt-1 text-xl font-bold text-[#2f4b66]">${escapeHtml(displayValue(referral.pet_name))}</h4>
              <p class="mt-1 break-all text-xs text-slate-500">Reference: ${escapeHtml(displayValue(referral.public_id))}</p>
            </div>
            <div class="flex flex-wrap gap-2">
              <span class="rounded-full border px-3 py-1 text-xs font-bold ${clinicReferralUrgencyClasses(referral.urgency)}">${escapeHtml(referral.urgency_label || titleCase(referral.urgency))}</span>
              <span class="rounded-full border px-3 py-1 text-xs font-bold ${clinicReferralStatusClasses(referral.status)}">${escapeHtml(clinicReferralStatusLabel(referral))}</span>
            </div>
          </div>
        </header>
        <div class="space-y-5 p-4 sm:p-5">
          ${successMessage ? `<div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-800" role="status" aria-live="polite">${escapeHtml(successMessage)}</div>` : ""}
          <section>
            <h5 class="font-bold text-[#2f4b66]">Why this referral was requested</h5>
            <p class="mt-2 whitespace-pre-line break-words text-sm leading-6 text-slate-700">${escapeHtml(displayValue(referral.customer_explanation))}</p>
          </section>
          <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
            ${clinicReferralDetailFields(referral).map(([label, value]) => `
              <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-400">${escapeHtml(label)}</dt><dd class="mt-1 break-words text-sm font-medium leading-6 text-slate-700">${escapeHtml(value)}</dd></div>
            `).join("")}
          </dl>
          <section class="rounded-xl border border-[#cfe0ee] bg-[#eef5fb] p-4">
            <h5 class="font-bold text-[#2f4b66]">What happens next</h5>
            <p class="mt-2 text-sm leading-6 text-slate-600">${escapeHtml(referral.customer_next_step || (referral.clinic_accepted
              ? `The clinic accepted this referral and your pet has entered clinic intake. ${displayValue(referral.clinic_appointment_reference)} is currently ${displayValue(referral.clinic_appointment_status_label, "Checked In")}. Treatment, procedures, and additional charges may still require separate approval.`
              : "A clinic referral request does not itself create an appointment or authorize treatment. Clinic acceptance and appointment information will appear here after the clinic accepts the referral."))}</p>
          </section>
          ${renderClinicReferralConsent(referral)}
          ${!isMissing(referral.customer_cancellation_summary) ? `<section class="rounded-xl border border-slate-200 bg-white p-4"><h5 class="font-bold text-slate-800">Cancellation update</h5><p class="mt-2 whitespace-pre-line text-sm leading-6 text-slate-600">${escapeHtml(referral.customer_cancellation_summary)}</p></section>` : ""}
          ${!isMissing(referral.customer_resolution_summary) ? `<section class="rounded-xl border border-emerald-200 bg-emerald-50 p-4"><h5 class="font-bold text-emerald-900">Referral resolution</h5><p class="mt-2 whitespace-pre-line text-sm leading-6 text-emerald-800">${escapeHtml(referral.customer_resolution_summary)}</p></section>` : ""}
        </div>
      </article>
      <div data-clinic-referral-confirmation class="fixed inset-0 z-[70] hidden items-center justify-center overflow-y-auto bg-slate-900/60 p-4" role="alertdialog" aria-modal="true" aria-labelledby="clinicReferralConfirmationTitle">
        <div data-clinic-referral-confirmation-card class="my-auto w-full max-w-lg rounded-2xl bg-white p-5 shadow-2xl" tabindex="-1">
          <h4 id="clinicReferralConfirmationTitle" class="text-lg font-bold text-[#2f4b66]">Confirm permanent referral response</h4>
          <p class="mt-2 text-sm leading-6 text-slate-600">Your response cannot be edited, replaced, or deleted after submission.</p>
          <dl class="mt-4 grid grid-cols-1 gap-3 rounded-xl bg-slate-50 p-4 sm:grid-cols-2">
            <div><dt class="text-xs font-bold uppercase tracking-wide text-slate-400">Pet</dt><dd class="mt-1 text-sm font-semibold text-slate-700">${escapeHtml(displayValue(referral.pet_name))}</dd></div>
            <div><dt class="text-xs font-bold uppercase tracking-wide text-slate-400">Decision</dt><dd data-clinic-referral-confirm-decision class="mt-1 text-sm font-semibold text-slate-700"></dd></div>
            <div class="sm:col-span-2"><dt class="text-xs font-bold uppercase tracking-wide text-slate-400">Typed signature name</dt><dd data-clinic-referral-confirm-name class="mt-1 break-words text-sm font-semibold text-slate-700"></dd></div>
          </dl>
          <p data-clinic-referral-decline-warning class="mt-3 hidden rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm leading-6 text-amber-900">A routine referral may be cancelled. An urgent or emergency safety referral may remain pending clinic acceptance. Declining does not erase the referral and does not automatically resume grooming.</p>
          <p class="mt-3 text-xs leading-5 text-slate-500">Approval covers referral creation, clinic intake transfer, and initial assessment only—not diagnostics, medication, treatment, emergency procedures, added charges, or other services.</p>
          <div data-clinic-referral-confirm-error class="mt-3 hidden rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700" role="alert" tabindex="-1"></div>
          <div class="mt-5 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <button type="button" data-cancel-clinic-referral-confirm class="min-h-11 rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700">Back</button>
            <button type="button" data-submit-clinic-referral-confirm class="min-h-11 rounded-xl bg-[#315b7e] px-5 py-2 text-sm font-semibold text-white disabled:opacity-60">Submit Permanent Response</button>
          </div>
        </div>
      </div>
    `;
    concernDetail.querySelector("[data-close-clinic-referral]")
      ?.addEventListener("click", closeClinicReferralDetail);
    attachClinicReferralConsentActions(referral);
    renderIcons();
  };

  const attachClinicReferralConsentActions = (referral) => {
    const signatureInput = concernDetail.querySelector("[data-clinic-referral-signature]");
    const decisionButtons = Array.from(concernDetail.querySelectorAll("[data-clinic-referral-decision]"));
    const errorBox = concernDetail.querySelector("[data-clinic-referral-response-error]");
    const confirmation = concernDetail.querySelector("[data-clinic-referral-confirmation]");
    const confirmationCard = concernDetail.querySelector("[data-clinic-referral-confirmation-card]");
    const confirmSubmit = concernDetail.querySelector("[data-submit-clinic-referral-confirm]");
    let selectedDecision = "";
    let originatingButton = null;

    signatureInput?.addEventListener("input", () => {
      clinicReferralSignatureDraft = signatureInput.value;
    });

    const showError = (box, message) => {
      if (!box) return;
      box.textContent = message;
      box.classList.remove("hidden");
      box.focus();
    };
    const setBusy = (busy) => {
      clinicReferralResponseSubmitting = busy;
      decisionButtons.forEach((button) => { button.disabled = busy; });
      if (signatureInput) signatureInput.disabled = busy;
      if (confirmSubmit) {
        confirmSubmit.disabled = busy;
        confirmSubmit.textContent = busy ? "Submitting..." : "Submit Permanent Response";
      }
    };
    const closeConfirmation = () => {
      confirmation?.classList.add("hidden");
      confirmation?.classList.remove("flex");
      originatingButton?.focus();
    };

    decisionButtons.forEach((button) => {
      button.addEventListener("click", () => {
        if (clinicReferralResponseSubmitting) return;
        const signatureName = signatureInput?.value.trim() || "";
        clinicReferralSignatureDraft = signatureInput?.value || "";
        if (!signatureName) {
          showError(errorBox, "Type your signature name before choosing Approve or Decline.");
          signatureInput?.focus();
          return;
        }
        selectedDecision = button.dataset.clinicReferralDecision;
        originatingButton = button;
        errorBox?.classList.add("hidden");
        concernDetail.querySelector("[data-clinic-referral-confirm-decision]").textContent =
          selectedDecision === "approved" ? "Approve" : "Decline";
        concernDetail.querySelector("[data-clinic-referral-confirm-name]").textContent = signatureName;
        concernDetail.querySelector("[data-clinic-referral-decline-warning]")
          ?.classList.toggle("hidden", selectedDecision !== "declined");
        confirmation?.classList.remove("hidden");
        confirmation?.classList.add("flex");
        confirmationCard?.focus();
      });
    });

    concernDetail.querySelector("[data-cancel-clinic-referral-confirm]")
      ?.addEventListener("click", closeConfirmation);

    confirmSubmit?.addEventListener("click", async () => {
      if (clinicReferralResponseSubmitting || !selectedDecision) return;
      const signatureName = signatureInput?.value.trim() || "";
      const confirmError = concernDetail.querySelector("[data-clinic-referral-confirm-error]");
      confirmError?.classList.add("hidden");
      setBusy(true);
      try {
        const response = await API.submitPetGroomingClinicReferralConsent(
          petId,
          referral.public_id,
          selectedDecision,
          signatureName,
        );
        clinicReferralSignatureDraft = "";
        concernLoadState = "idle";
        await openClinicReferralDetail(referral.public_id, {
          updateHistory: false,
          successMessage: response.message || "Your permanent response was recorded.",
        });
      } catch (error) {
        if (error?.status === 409) {
          clinicReferralSignatureDraft = "";
          await openClinicReferralDetail(referral.public_id, {
            updateHistory: false,
            successMessage: "The original permanent response was preserved and reloaded.",
          });
          return;
        }
        setBusy(false);
        showError(
          confirmError,
          concernResponseError(error),
        );
      }
    });
  };

  const closeClinicReferralDetail = () => {
    concernDetail?.classList.add("hidden");
    concernNotifications?.classList.remove("hidden");
    const url = new URL(window.location.href);
    url.searchParams.set("tab", "notifications");
    url.searchParams.delete("referral");
    window.history.replaceState({}, "", url);
    clinicReferralResponseSubmitting = false;
    clinicReferralSignatureDraft = "";
    if (concernLoadState === "idle") loadConcernNotifications({ retry: true });
    renderIcons();
  };

  const openClinicReferralDetail = async (
    publicId,
    { updateHistory = true, successMessage = "" } = {},
  ) => {
    if (!concernDetail || !concernNotifications || !publicId) return;
    concernNotifications.classList.add("hidden");
    concernDetail.classList.remove("hidden");
    concernDetail.innerHTML = `
      <div class="py-12 text-center" role="status" aria-live="polite">
        <i data-lucide="loader" class="mx-auto h-8 w-8 animate-spin text-slate-300"></i>
        <p class="mt-3 text-sm text-slate-500">Loading clinic referral details...</p>
      </div>
    `;
    renderIcons();
    try {
      const response = await API.getPetGroomingClinicReferral(petId, publicId);
      renderClinicReferralDetail(response.referral || {}, successMessage);
      if (updateHistory) {
        const url = new URL(window.location.href);
        url.searchParams.set("tab", "notifications");
        url.searchParams.delete("concern");
        url.searchParams.set("referral", publicId);
        window.history.pushState({}, "", url);
      }
    } catch (error) {
      clinicReferralResponseSubmitting = false;
      const notFound = error?.status === 404;
      concernDetail.innerHTML = `
        <div class="rounded-2xl border ${notFound ? "border-amber-100 bg-amber-50" : "border-red-100 bg-red-50"} px-6 py-10 text-center" role="alert">
          <i data-lucide="${notFound ? "file-question" : "circle-alert"}" class="mx-auto h-8 w-8 ${notFound ? "text-amber-500" : "text-red-400"}"></i>
          <h4 class="mt-3 font-bold ${notFound ? "text-amber-900" : "text-red-800"}">${notFound ? "Clinic referral not found" : "Clinic referral could not be loaded"}</h4>
          <p class="mt-1 text-sm ${notFound ? "text-amber-700" : "text-red-600"}">${notFound ? "This referral does not exist or is not available for this pet and account." : escapeHtml(error?.message || "Please try again later.")}</p>
          <div class="mt-4 flex flex-wrap justify-center gap-2">
            <button type="button" data-close-clinic-referral class="inline-flex min-h-11 items-center rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700">Back</button>
            ${notFound ? "" : `<button type="button" data-retry-clinic-referral class="inline-flex min-h-11 items-center gap-2 rounded-xl bg-[#315b7e] px-4 py-2 text-sm font-semibold text-white"><i data-lucide="refresh-cw" class="h-4 w-4"></i>Retry</button>`}
          </div>
        </div>
      `;
      concernDetail.querySelector("[data-close-clinic-referral]")
        ?.addEventListener("click", closeClinicReferralDetail);
      concernDetail.querySelector("[data-retry-clinic-referral]")
        ?.addEventListener("click", () => openClinicReferralDetail(publicId, { updateHistory: false }));
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

  const clinicReferralNotificationLabel = (type) => ({
    grooming_clinic_referral_requested: "Clinic referral request",
    grooming_clinic_referral_accepted: "Clinic referral accepted",
    grooming_clinic_assessment_started: "Clinic assessment started",
    grooming_clinic_assessment_completed: "Clinic assessment completed",
  })[type] || "Clinic referral update";

  const renderClinicReferralNotificationSummary = (notification) => `
    <article class="rounded-2xl border border-blue-200 bg-blue-50 p-4 sm:p-5">
      <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div>
          <p class="text-xs font-bold uppercase tracking-[0.12em] text-blue-700">${escapeHtml(clinicReferralNotificationLabel(notification.type))}</p>
          <p class="mt-1 text-sm text-blue-800">${escapeHtml(formatDateTime(notification.created_at))}</p>
        </div>
        <span class="w-fit rounded-full border border-blue-200 bg-white px-3 py-1 text-xs font-bold text-blue-800">${notification.is_read ? "Read" : "Unread"}</span>
      </div>
      <p class="mt-3 whitespace-pre-line break-words text-sm leading-6 text-slate-700">${escapeHtml(displayValue(notification.display_message || notification.message))}</p>
      <button
        type="button"
        data-open-clinic-referral="${escapeHtml(notification.referral_public_id)}"
        data-clinic-referral-notification-id="${escapeHtml(notification.id)}"
        data-clinic-referral-notification-read="${notification.is_read ? "1" : "0"}"
        class="mt-4 inline-flex min-h-11 items-center gap-2 rounded-xl bg-[#315b7e] px-4 py-2 text-sm font-semibold text-white hover:bg-[#274b69]"
      >
        <i data-lucide="heart-pulse" class="h-4 w-4"></i>
        View clinic referral
      </button>
    </article>
  `;

  const attachClinicReferralNotificationActions = () => {
    concernNotifications
      ?.querySelectorAll("[data-open-clinic-referral]")
      .forEach((button) => {
        button.addEventListener("click", async () => {
          const publicId = button.dataset.openClinicReferral;
          if (button.dataset.clinicReferralNotificationRead !== "1") {
            try {
              await API.markCustomerNotificationRead(
                button.dataset.clinicReferralNotificationId,
              );
            } catch {
              // Detail authorization remains server-enforced; a read-state error does not fabricate a response.
            }
          }
          await openClinicReferralDetail(publicId);
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

    if (requestedReferralPublicId && !requestedReferralHandled) {
      requestedReferralHandled = true;
      await openClinicReferralDetail(requestedReferralPublicId, {
        updateHistory: false,
      });
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
      const [response, notificationResponse] = await Promise.all([
        API.getPetMedicalConcerns(petId),
        API.getCustomerNotifications().catch(() => ({ notifications: [] })),
      ]);
      const concerns = Array.isArray(response.concerns) ? response.concerns : [];
      const referralNotifications = Array.isArray(notificationResponse.notifications)
        ? notificationResponse.notifications.filter((notification) =>
            [
              "grooming_clinic_referral_requested",
              "grooming_clinic_referral_accepted",
              "grooming_clinic_assessment_started",
              "grooming_clinic_assessment_completed",
            ].includes(notification.type)
            && Number(notification.pet_id) === petId
            && notification.referral_public_id,
          )
        : [];

      if (!concerns.length && !referralNotifications.length) {
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
            ${referralNotifications.map(renderClinicReferralNotificationSummary).join("")}
            ${concerns.map(renderConcernSummary).join("")}
          </div>
        `;
        attachConcernSummaryActions();
        attachClinicReferralNotificationActions();
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
