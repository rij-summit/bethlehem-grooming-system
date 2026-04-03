document.addEventListener("DOMContentLoaded", () => {
  const form = document.getElementById("bookingServicesForm");
  const scheduleSummaryText = document.getElementById("scheduleSummaryText");
  const petSummaryText = document.getElementById("petSummaryText");
  const serviceNotice = document.getElementById("serviceNotice");

  const bookingDateInput = document.getElementById("bookingDate");
  const bookingTimeInput = document.getElementById("bookingTime");
  const petIdInput = document.getElementById("petId");
  const petTypeInput = document.getElementById("petType");

  const serviceRadios = document.querySelectorAll(
    'input[name="service_package"]',
  );
  const dogServicesSection = document.getElementById("dogServicesSection");
  const catServicesSection = document.getElementById("catServicesSection");

  /*
    BACKEND NOTE:
    For now this page uses sessionStorage as a front-end placeholder.
    Replace this with real API/session/database data when backend is connected.

    Suggested payload from previous step:
    {
      bookingDate: "2026-04-05",
      bookingTime: "8:00 AM - 9:00 AM",
      petId: "123",
      petName: "Max",
      petType: "dog",
      petBreed: "Shih Tzu"
    }
  */
  const bookingDraft = getBookingDraft();

  populateHiddenInputs(bookingDraft);
  populateSummary(bookingDraft);
  filterServicesByPetType(bookingDraft);
  restoreSavedSelections();
  updateServiceNotice();

  serviceRadios.forEach((radio) => {
    radio.addEventListener("change", () => {
      updateServiceNotice();
      saveCurrentStepDraft();
    });
  });

  const addOns = document.querySelectorAll('input[name="add_ons"]');
  addOns.forEach((checkbox) => {
    checkbox.addEventListener("change", saveCurrentStepDraft);
  });

  const specialInstructions = document.getElementById("specialInstructions");
  if (specialInstructions) {
    specialInstructions.addEventListener("input", saveCurrentStepDraft);
  }

  form.addEventListener("submit", (event) => {
    event.preventDefault();

    const selectedService = document.querySelector(
      'input[name="service_package"]:checked',
    );

    if (!selectedService) {
      serviceNotice.textContent =
        "Please select one grooming service package before continuing.";
      serviceNotice.className =
        "mb-6 rounded-2xl border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-600";
      return;
    }

    const stepThreeData = {
      servicePackage: selectedService.value,
      addOns: Array.from(
        document.querySelectorAll('input[name="add_ons"]:checked'),
      ).map((item) => item.value),
      specialInstructions: specialInstructions.value.trim(),
    };

    /*
      BACKEND NOTE:
      Save step 3 booking data here.
      Example:
      POST /api/bookings/draft/services

      Recommended fields to send:
      - booking_date
      - booking_time
      - pet_id
      - pet_type
      - servicePackage
      - addOns[]
      - specialInstructions
    */
    sessionStorage.setItem("bookingStep3", JSON.stringify(stepThreeData));

    // Temporary navigation for front-end flow.
    // Replace this with your actual step 4 page.
    window.location.href = "./booking-consent.html";
  });

  function getBookingDraft() {
    const savedStepTwoDraft = readSessionJson("bookingStep2");
    if (savedStepTwoDraft) {
      return normalizeBookingDraft(savedStepTwoDraft);
    }

    const legacySchedule = readSessionJson("bookingSchedule");
    const legacyPets = readSessionJson("bookingPets");

    if (!legacySchedule && !Array.isArray(legacyPets)) {
      return null;
    }

    const normalizedDraft = normalizeBookingDraft({
      bookingDate: legacySchedule?.date || "",
      bookingTime: legacySchedule?.time || "",
      pets: Array.isArray(legacyPets) ? legacyPets : [],
    });

    sessionStorage.setItem("bookingStep2", JSON.stringify(normalizedDraft));
    return normalizedDraft;
  }

  function readSessionJson(key) {
    try {
      const rawValue = sessionStorage.getItem(key);
      return rawValue ? JSON.parse(rawValue) : null;
    } catch (error) {
      console.error(`Failed to parse ${key} sessionStorage:`, error);
      return null;
    }
  }

  function normalizeBookingDraft(data) {
    const petsFromDraft = Array.isArray(data?.pets) ? data.pets.filter(Boolean) : [];
    const legacyPet =
      data?.petId || data?.petName || data?.petType || data?.petBreed
        ? [
            {
              id: data.petId || "",
              petName: data.petName || "",
              petType: data.petType || "",
              breed: data.petBreed || "",
            },
          ]
        : [];

    const pets = petsFromDraft.length > 0 ? petsFromDraft : legacyPet;
    const firstPet = pets[0] || null;
    const petTypes = [
      ...new Set(
        pets.map((pet) => normalizePetType(pet.petType)).filter(Boolean),
      ),
    ];

    return {
      bookingDate: data?.bookingDate || data?.date || "",
      bookingTime: data?.bookingTime || data?.time || "",
      pets,
      petIds: pets.map((pet) => pet.id).filter(Boolean),
      petTypes,
      petId: data?.petId || firstPet?.id || "",
      petType: data?.petType || firstPet?.petType || "",
      petName: data?.petName || firstPet?.petName || "",
      petBreed: data?.petBreed || firstPet?.breed || "",
    };
  }

  function populateHiddenInputs(data) {
    bookingDateInput.value = data?.bookingDate || "";
    bookingTimeInput.value = data?.bookingTime || "";
    petIdInput.value = data?.petIds?.join(",") || data?.petId || "";
    petTypeInput.value = data?.petTypes?.join(",") || data?.petType || "";
  }

  function populateSummary(data) {
    if (data?.bookingDate || data?.bookingTime) {
      scheduleSummaryText.textContent = `${data.bookingDate || "No date"} | ${
        data.bookingTime || "No time"
      }`;
    }

    const selectedPets = Array.isArray(data?.pets) ? data.pets : [];
    if (selectedPets.length > 1) {
      petSummaryText.textContent = `${selectedPets.length} pets selected: ${selectedPets
        .map((pet) => {
          const petName = pet.petName || "Unnamed Pet";
          const petType = capitalizeFirstLetter(pet.petType || "Unknown Type");
          return `${petName} (${petType})`;
        })
        .join(", ")}`;
      return;
    }

    if (data?.petName || data?.petType || data?.petBreed) {
      petSummaryText.textContent = `${data.petName || "Unnamed Pet"} | ${capitalizeFirstLetter(
        data.petType || "Unknown Type",
      )} | ${data.petBreed || "Breed not specified"}`;
    }
  }

  function filterServicesByPetType(data) {
    dogServicesSection.classList.remove("hidden");
    catServicesSection.classList.remove("hidden");

    const selectedPetTypes =
      Array.isArray(data?.petTypes) && data.petTypes.length > 0
        ? data.petTypes
        : [normalizePetType(data?.petType)].filter(Boolean);

    if (selectedPetTypes.length !== 1) {
      return;
    }

    if (selectedPetTypes[0] === "dog") {
      catServicesSection.classList.add("hidden");
    } else if (selectedPetTypes[0] === "cat") {
      dogServicesSection.classList.add("hidden");
    }
    /*
      If no pet type is available yet, both sections remain visible.
      This is useful for empty state and front-end demo purposes.
    */
  }

  function restoreSavedSelections() {
    try {
      const rawStep3 = sessionStorage.getItem("bookingStep3");
      if (!rawStep3) return;

      const step3Data = JSON.parse(rawStep3);

      if (step3Data.servicePackage) {
        const savedRadio = document.querySelector(
          `input[name="service_package"][value="${step3Data.servicePackage}"]`,
        );
        if (savedRadio) savedRadio.checked = true;
      }

      if (Array.isArray(step3Data.addOns)) {
        step3Data.addOns.forEach((value) => {
          const savedAddOn = document.querySelector(
            `input[name="add_ons"][value="${value}"]`,
          );
          if (savedAddOn) savedAddOn.checked = true;
        });
      }

      if (typeof step3Data.specialInstructions === "string") {
        specialInstructions.value = step3Data.specialInstructions;
      }
    } catch (error) {
      console.error("Failed to restore bookingStep3 sessionStorage:", error);
    }
  }

  function saveCurrentStepDraft() {
    const selectedService = document.querySelector(
      'input[name="service_package"]:checked',
    );

    const stepThreeData = {
      servicePackage: selectedService ? selectedService.value : "",
      addOns: Array.from(
        document.querySelectorAll('input[name="add_ons"]:checked'),
      ).map((item) => item.value),
      specialInstructions: specialInstructions.value.trim(),
    };

    sessionStorage.setItem("bookingStep3", JSON.stringify(stepThreeData));
  }

  function updateServiceNotice() {
    const selectedService = document.querySelector(
      'input[name="service_package"]:checked',
    );

    if (!selectedService) {
      serviceNotice.textContent = "No grooming service selected yet.";
      serviceNotice.className =
        "mb-6 rounded-2xl border border-[#9bb9d3] bg-white px-4 py-3 text-sm text-slate-600";
      return;
    }

    const labelTitle =
      selectedService.closest("label")?.querySelector("h4")?.textContent ||
      "Selected service";

    serviceNotice.textContent = `Selected service: ${labelTitle}`;
    serviceNotice.className =
      "mb-6 rounded-2xl border border-green-300 bg-green-50 px-4 py-3 text-sm text-green-700";
  }

  function capitalizeFirstLetter(value) {
    if (!value) return "";
    return value.charAt(0).toUpperCase() + value.slice(1);
  }

  function normalizePetType(value) {
    return String(value || "").trim().toLowerCase();
  }
});
