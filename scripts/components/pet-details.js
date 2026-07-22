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
  const bookGroomingLink = document.getElementById("bookGroomingLink");

  const petIdParam = new URLSearchParams(window.location.search).get("pet_id");
  const petId = Number(petIdParam);

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

    tabs.forEach((tab) => {
      tab.addEventListener("click", () => {
        const selected = tab.dataset.petTab;

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
      });
    });
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
