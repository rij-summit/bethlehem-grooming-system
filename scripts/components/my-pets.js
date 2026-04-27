// Connected to pages/client/my-pets.html
// Depends on: api.js

document.addEventListener("DOMContentLoaded", () => {
  // ── Auth guard ───────────────────────────────────────
  if (!API.getCustomerToken()) {
    window.location.href = "../sign-in/sign_in.html";
    return;
  }

  // ── State ────────────────────────────────────────────
  let allPets = [];
  let showingArchived = false;

  // ── DOM refs ─────────────────────────────────────────
  const petsGrid         = document.getElementById("petsGrid");
  const petSearch        = document.getElementById("petSearch");
  const filterActiveBtn  = document.getElementById("filterActiveBtn");
  const filterArchivedBtn= document.getElementById("filterArchivedBtn");
  const addPetBtn        = document.getElementById("addPetBtn");
  const petModal         = document.getElementById("petModal");
  const closePetModal    = document.getElementById("closePetModal");
  const petForm          = document.getElementById("petForm");
  const petModalTitle    = document.getElementById("petModalTitle");
  const petFormError     = document.getElementById("petFormError");
  const petFormSubmit    = document.getElementById("petFormSubmit");
  const logoutBtn        = document.getElementById("clientLogoutBtn");

  // ── Icons ─────────────────────────────────────────────
  if (window.lucide) window.lucide.createIcons();

  // ── Profile ───────────────────────────────────────────
  (async () => {
    try {
      const { user } = await API.getMe("customer");
      const name = `${user.first_name} ${user.last_name}`;
      document.getElementById("clientProfileName").textContent = name;
      document.getElementById("clientProfileInitials").textContent =
        (user.first_name[0] + user.last_name[0]).toUpperCase();
    } catch {
      // silently fail — not critical
    }
  })();

  // ── Logout ────────────────────────────────────────────
  logoutBtn?.addEventListener("click", async () => {
    await API.logout("customer");
    window.location.href = "../sign-in/sign_in.html";
  });

  // ── Sidebar toggle (mobile) ───────────────────────────
  const sidebarToggle   = document.getElementById("clientSidebarToggle");
  const sidebarBackdrop = document.getElementById("clientSidebarBackdrop");
  const sidebarClose    = document.getElementById("clientSidebarClose");
  const sidebar         = document.getElementById("clientSidebar");
  const mobileSidebarQuery = window.matchMedia("(max-width: 1180px)");
  const sidebarLinks = sidebar?.querySelectorAll("a") || [];

  function setSidebarState(isOpen) {
    document.body.classList.toggle("client-sidebar-open", isOpen);
    sidebarToggle?.setAttribute("aria-expanded", String(isOpen));
    sidebarToggle?.setAttribute(
      "aria-label",
      isOpen ? "Close navigation menu" : "Open navigation menu",
    );
  }

  function closeSidebar() {
    setSidebarState(false);
  }

  function toggleSidebar() {
    if (!mobileSidebarQuery.matches) return;
    const isOpen = document.body.classList.contains("client-sidebar-open");
    setSidebarState(!isOpen);
  }

  setSidebarState(false);
  sidebarToggle?.addEventListener("click", toggleSidebar);
  sidebarBackdrop?.addEventListener("click", closeSidebar);
  sidebarClose?.addEventListener("click", closeSidebar);
  sidebarLinks.forEach((link) => link.addEventListener("click", closeSidebar));
  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape") closeSidebar();
  });
  mobileSidebarQuery.addEventListener("change", (event) => {
    if (!event.matches) closeSidebar();
  });

  // ── Load pets ─────────────────────────────────────────
  async function loadPets() {
    renderGrid(null); // loading state
    try {
      const data = await API.getUserPets({ archived: showingArchived ? 1 : 0 });
      allPets = data.pets || [];
    } catch {
      allPets = [];
    }
    applyFilter();
  }

  // ── Filter + search ───────────────────────────────────
  function applyFilter() {
    const q = petSearch.value.trim().toLowerCase();
    const filtered = q
      ? allPets.filter((p) => p.pet_name.toLowerCase().includes(q))
      : allPets;
    renderGrid(filtered);
  }

  petSearch.addEventListener("input", applyFilter);

  filterActiveBtn.addEventListener("click", () => {
    showingArchived = false;
    filterActiveBtn.className =
      "rounded-full px-5 py-2.5 text-sm font-semibold bg-[#355c84] text-white transition";
    filterArchivedBtn.className =
      "rounded-full px-5 py-2.5 text-sm font-semibold bg-white border border-slate-200 text-slate-600 hover:bg-slate-50 transition";
    loadPets();
  });

  filterArchivedBtn.addEventListener("click", () => {
    showingArchived = true;
    filterArchivedBtn.className =
      "rounded-full px-5 py-2.5 text-sm font-semibold bg-[#355c84] text-white transition";
    filterActiveBtn.className =
      "rounded-full px-5 py-2.5 text-sm font-semibold bg-white border border-slate-200 text-slate-600 hover:bg-slate-50 transition";
    loadPets();
  });

  // ── Render grid ───────────────────────────────────────
  function renderGrid(pets) {
    if (pets === null) {
      petsGrid.innerHTML = `
        <div class="col-span-full text-center py-16">
          <i data-lucide="loader" class="w-8 h-8 mx-auto text-slate-300 animate-spin"></i>
          <p class="mt-3 text-slate-400 text-sm">Loading...</p>
        </div>`;
      window.lucide?.createIcons();
      return;
    }

    if (pets.length === 0) {
      const msg = showingArchived
        ? "No archived pets."
        : "No pets yet. Add your first pet using the button above.";
      petsGrid.innerHTML = `
        <div class="col-span-full text-center py-16">
          <i data-lucide="paw-print" class="w-10 h-10 mx-auto text-slate-200"></i>
          <p class="mt-3 text-slate-400 text-sm">${msg}</p>
        </div>`;
      window.lucide?.createIcons();
      return;
    }

    petsGrid.innerHTML = pets.map((pet) => buildCard(pet)).join("");
    window.lucide?.createIcons();

    petsGrid.querySelectorAll("[data-edit]").forEach((btn) => {
      btn.addEventListener("click", () => openEditModal(Number(btn.dataset.edit)));
    });
    petsGrid.querySelectorAll("[data-archive]").forEach((btn) => {
      btn.addEventListener("click", () => handleArchive(Number(btn.dataset.archive)));
    });
    petsGrid.querySelectorAll("[data-unarchive]").forEach((btn) => {
      btn.addEventListener("click", () => handleUnarchive(Number(btn.dataset.unarchive)));
    });
  }

  function buildCard(pet) {
    const sizeLabel = { small: "Small", medium: "Medium", large: "Large", extra_large: "Extra Large" };
    const furLabel  = { short: "Short", medium: "Medium", long: "Long", wire: "Wire", curl: "Curl" };

    const rows = [
      ["Species",    pet.species],
      ["Breed",      pet.breed],
      ["Size",       sizeLabel[pet.size]],
      ["Fur Type",   furLabel[pet.fur_type]],
      ["Weight",     pet.weight ? `${pet.weight} kg` : null],
      ["Color",      pet.color],
      ["Medical",    pet.medical_conditions],
    ].filter(([, v]) => v);

    const detailsHtml = rows.length
      ? rows.map(([label, val]) => `
          <div class="flex gap-2 text-sm">
            <span class="text-slate-400 shrink-0 w-20">${label}</span>
            <span class="text-slate-700 font-medium">${escHtml(String(val))}</span>
          </div>`).join("")
      : `<p class="text-sm text-slate-400">No additional details.</p>`;

    const archiveBtn = pet.is_archived
      ? `<button type="button" data-unarchive="${pet.pet_id}"
            class="flex items-center gap-1.5 rounded-xl border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-600 hover:bg-slate-50 transition">
            <i data-lucide="rotate-ccw" class="w-3.5 h-3.5"></i> Restore
          </button>`
      : `<button type="button" data-archive="${pet.pet_id}"
            class="flex items-center gap-1.5 rounded-xl border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-500 hover:bg-slate-50 transition">
            <i data-lucide="archive" class="w-3.5 h-3.5"></i> Archive
          </button>`;

    const editBtn = pet.is_archived ? "" : `
      <button type="button" data-edit="${pet.pet_id}"
        class="flex items-center gap-1.5 rounded-xl bg-[#dbe8f5] px-3 py-2 text-xs font-semibold text-[#2f4b66] hover:bg-[#ccddf0] transition">
        <i data-lucide="pencil" class="w-3.5 h-3.5"></i> Edit
      </button>`;

    return `
      <div class="rounded-3xl bg-white border border-slate-200 p-5 shadow-sm flex flex-col gap-4">
        <div class="flex items-start justify-between gap-2">
          <div class="flex items-center gap-3">
            <div class="h-12 w-12 rounded-2xl bg-[#dbe8f5] flex items-center justify-center shrink-0">
              <i data-lucide="paw-print" class="w-5 h-5 text-[#355c84]"></i>
            </div>
            <div>
              <p class="font-bold text-[#2f4b66] text-base leading-tight">${escHtml(pet.pet_name)}</p>
              <p class="text-xs text-slate-400 mt-0.5">${escHtml(pet.species || "Dog")}</p>
            </div>
          </div>
          ${pet.is_archived ? `<span class="shrink-0 rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-400">Archived</span>` : ""}
        </div>

        <div class="space-y-1.5 flex-1">
          ${detailsHtml}
        </div>

        <div class="flex gap-2 pt-2 border-t border-slate-100">
          ${editBtn}
          ${archiveBtn}
        </div>
      </div>`;
  }

  // ── Modal ─────────────────────────────────────────────
  function openAddModal() {
    petModalTitle.textContent = "Add Pet";
    petForm.reset();
    document.getElementById("petId").value = "";
    hideFormError();
    showModal();
  }

  function openEditModal(id) {
    const pet = allPets.find((p) => p.pet_id === id);
    if (!pet) return;

    petModalTitle.textContent = "Edit Pet";
    document.getElementById("petId").value    = pet.pet_id;
    document.getElementById("petName").value   = pet.pet_name || "";
    document.getElementById("petSpecies").value= pet.species || "Dog";
    document.getElementById("petBreed").value  = pet.breed || "";
    document.getElementById("petColor").value  = pet.color || "";
    document.getElementById("petSize").value   = pet.size || "";
    document.getElementById("petFurType").value= pet.fur_type || "";
    document.getElementById("petWeight").value = pet.weight || "";
    document.getElementById("petMedical").value= pet.medical_conditions || "";
    hideFormError();
    showModal();
  }

  function showModal() {
    petModal.classList.remove("hidden");
    petModal.classList.add("flex");
  }

  function closeModal() {
    petModal.classList.add("hidden");
    petModal.classList.remove("flex");
  }

  addPetBtn.addEventListener("click", openAddModal);
  closePetModal.addEventListener("click", closeModal);
  petModal.addEventListener("click", (e) => { if (e.target === petModal) closeModal(); });

  // ── Form submit ───────────────────────────────────────
  petForm.addEventListener("submit", async (e) => {
    e.preventDefault();
    hideFormError();

    const id = document.getElementById("petId").value;
    const payload = {
      pet_name:           document.getElementById("petName").value.trim(),
      species:            document.getElementById("petSpecies").value,
      breed:              document.getElementById("petBreed").value.trim() || null,
      color:              document.getElementById("petColor").value.trim() || null,
      size:               document.getElementById("petSize").value || null,
      fur_type:           document.getElementById("petFurType").value || null,
      weight:             document.getElementById("petWeight").value || null,
      medical_conditions: document.getElementById("petMedical").value.trim() || null,
    };

    petFormSubmit.disabled = true;
    petFormSubmit.textContent = "Saving...";

    try {
      if (id) {
        await API.updatePet(id, payload);
      } else {
        await API.addPet(payload);
      }
      closeModal();
      await loadPets();
    } catch (err) {
      showFormError(err.errors
        ? Object.values(err.errors).flat()[0]
        : (err.message || "Something went wrong."));
    } finally {
      petFormSubmit.disabled = false;
      petFormSubmit.textContent = "Save Pet";
    }
  });

  // ── Archive / Unarchive ───────────────────────────────
  async function handleArchive(id) {
    const pet = allPets.find((p) => p.pet_id === id);
    if (!pet) return;
    if (!confirm(`Archive "${pet.pet_name}"? It will be hidden from the booking form.`)) return;
    try {
      await API.archivePet(id);
      await loadPets();
    } catch (err) {
      alert(err.message || "Could not archive pet.");
    }
  }

  async function handleUnarchive(id) {
    const pet = allPets.find((p) => p.pet_id === id);
    if (!pet) return;
    if (!confirm(`Restore "${pet.pet_name}"?`)) return;
    try {
      await API.unarchivePet(id);
      await loadPets();
    } catch (err) {
      alert(err.message || "Could not restore pet.");
    }
  }

  // ── Helpers ───────────────────────────────────────────
  function showFormError(msg) {
    petFormError.textContent = msg;
    petFormError.classList.remove("hidden");
  }

  function hideFormError() {
    petFormError.textContent = "";
    petFormError.classList.add("hidden");
  }

  function escHtml(str) {
    return str.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;");
  }

  // ── Init ──────────────────────────────────────────────
  loadPets();
});
