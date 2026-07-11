function adminStockIn() {
  return {
    // ── Item search ───────────────────────────────────────────────────────────
    searchQuery: "",
    searchResults: [],
    noResults: false,
    _searchTimer: null,
    selected: null,

    // ── Inline create product ─────────────────────────────────────────────────
    showCreateForm: false,
    createForm: {
      item_name: "", barcode: "", category: "",
      unit: "", unit_cost: "", selling_price: "", reorder_level: "",
    },
    createBusy: false,
    createError: "",

    // ── Current entry ─────────────────────────────────────────────────────────
    qty: "",
    unitCost: "",
    reason: "purchase",
    batchNumber: "",
    expiryDate: "",
    notes: "",
    entryError: "",

    // ── Supplier ──────────────────────────────────────────────────────────────
    supplierId: "",
    suppliers: [],

    // ── Pending list ──────────────────────────────────────────────────────────
    pending: [],

    // ── Submit state ──────────────────────────────────────────────────────────
    submitting: false,
    error: "",
    success: "",

    // ── Camera scanner ────────────────────────────────────────────────────────
    scannerActive: false,
    _scanner: null,

    async init() {
      await this.loadSuppliers();
      this.$nextTick(() => { if (window.lucide) lucide.createIcons(); });
    },

    async loadSuppliers() {
      try {
        const res = await InventoryAPI.getSuppliers();
        this.suppliers = res.data ?? [];
      } catch { this.suppliers = []; }
    },

    // ── Search ────────────────────────────────────────────────────────────────

    async onSearchInput() {
      const q = this.searchQuery;
      if (q.length < 2) {
        this.searchResults = [];
        this.noResults     = false;
        this.showCreateForm = false;
        return;
      }
      clearTimeout(this._searchTimer);
      this._searchTimer = setTimeout(async () => {
        try {
          const res = await InventoryAPI.searchItems(q);
          this.searchResults = res.data;
          this.noResults     = res.data.length === 0;
          if (!this.noResults) this.showCreateForm = false;
        } catch {
          this.searchResults = [];
          this.noResults     = false;
        }
      }, 300);
    },

    async onSearchEnter() {
      if (!this.searchQuery.trim()) return;
      clearTimeout(this._searchTimer);
      try {
        const res = await InventoryAPI.findByBarcode(this.searchQuery.trim());
        this.noResults = false;
        this.pickItem(res.data);
      } catch {
        const res = await InventoryAPI.searchItems(this.searchQuery.trim()).catch(() => ({ data: [] }));
        this.searchResults = res.data;
        this.noResults     = res.data.length === 0;
      }
    },

    pickItem(item) {
      this.selected       = item;
      this.unitCost       = item.unit_cost ?? "";
      this.qty            = "";
      this.batchNumber    = "";
      this.expiryDate     = "";
      this.notes          = "";
      this.entryError     = "";
      this.searchQuery    = "";
      this.searchResults  = [];
      this.noResults      = false;
      this.showCreateForm = false;
      this.$nextTick(() => {
        if (window.lucide) lucide.createIcons();
        this.$el.querySelector?.("[data-qty-input]")?.focus();
      });
    },

    clearSelected() {
      this.selected    = null;
      this.qty         = "";
      this.unitCost    = "";
      this.batchNumber = "";
      this.expiryDate  = "";
      this.notes       = "";
      this.entryError  = "";
    },

    get needsExpiry() {
      return this.selected &&
        (this.selected.category === "medicine" || this.selected.category === "vaccine");
    },

    // ── Inline create product ─────────────────────────────────────────────────

    openCreateForm() {
      this.createForm = {
        item_name:     this.searchQuery.trim(),
        barcode:       "",
        category:      "",
        unit:          "",
        unit_cost:     "",
        selling_price: "",
        reorder_level: "",
      };
      this.createError    = "";
      this.showCreateForm = true;
      this.$nextTick(() => { if (window.lucide) lucide.createIcons(); });
    },

    cancelCreate() {
      this.showCreateForm = false;
      this.createError    = "";
    },

    async createAndSelect() {
      this.createError = "";
      if (!this.createForm.item_name.trim()) { this.createError = "Product name is required."; return; }
      if (!this.createForm.category)          { this.createError = "Category is required."; return; }
      if (!this.createForm.unit.trim())        { this.createError = "Unit is required."; return; }

      this.createBusy = true;
      try {
        const payload = {
          item_name:     this.createForm.item_name.trim(),
          barcode:       this.createForm.barcode.trim() || null,
          category:      this.createForm.category,
          unit:          this.createForm.unit.trim(),
          unit_cost:     this.createForm.unit_cost     !== "" ? parseFloat(this.createForm.unit_cost)     : 0,
          selling_price: this.createForm.selling_price !== "" ? parseFloat(this.createForm.selling_price) : null,
          reorder_level: this.createForm.reorder_level !== "" ? parseFloat(this.createForm.reorder_level) : 0,
        };
        const res     = await InventoryAPI.createItem(payload);
        const newItem = { ...res.data, _newly_created: true };
        this.pickItem(newItem);
      } catch (err) {
        this.createError = err.errors
          ? Object.values(err.errors).flat().join(" ")
          : (err.message || "Failed to create product.");
      } finally {
        this.createBusy = false;
      }
    },

    // Undo while item is still in the chip (before adding to pending)
    async undoSelected() {
      if (!this.selected?._newly_created) return;
      try {
        await InventoryAPI.deactivateItem(this.selected.item_id);
      } catch { /* best-effort: item may be gone already */ }
      this.clearSelected();
      this.noResults      = false;
      this.showCreateForm = false;
      this.$nextTick(() => { if (window.lucide) lucide.createIcons(); });
    },

    // Undo after item was added to the pending list
    async undoCreatedItem(idx) {
      const p = this.pending[idx];
      if (!p._newly_created) return;
      try {
        await InventoryAPI.deactivateItem(p.item_id);
      } catch { /* best-effort */ }
      this.pending.splice(idx, 1);
      this.$nextTick(() => { if (window.lucide) lucide.createIcons(); });
    },

    // ── Add to pending ────────────────────────────────────────────────────────

    addToPending() {
      this.entryError = "";
      if (!this.selected)        { this.entryError = "Select an item first.";       return; }
      const qty = parseFloat(this.qty);
      if (!qty || qty <= 0)      { this.entryError = "Enter a valid quantity.";      return; }
      if (this.needsExpiry && !this.expiryDate) {
        this.entryError = "Expiry date is required for medicine and vaccine.";
        return;
      }

      const existing = this.pending.find(p => p.item_id === this.selected.item_id);
      if (existing) {
        const sameBatch  = (existing.batch_number ?? "") === (this.batchNumber.trim() ?? "");
        const sameExpiry = (existing.expiry_date  ?? "") === (this.expiryDate         ?? "");
        if (!sameBatch || !sameExpiry) {
          this.entryError = "This item is already in the list with a different batch/expiry. Remove the existing entry first, or combine them manually.";
          return;
        }
        existing.quantity = Math.round((existing.quantity + qty) * 100) / 100;
        if (this.unitCost !== "") existing.unit_cost = parseFloat(this.unitCost);
        if (this.notes.trim())    existing.notes     = this.notes.trim();
      } else {
        this.pending.push({
          item_id:        this.selected.item_id,
          item_name:      this.selected.item_name,
          unit:           this.selected.unit,
          category:       this.selected.category,
          quantity:       qty,
          unit_cost:      this.unitCost !== "" ? parseFloat(this.unitCost) : null,
          reason:         this.reason,
          batch_number:   this.batchNumber.trim() || null,
          expiry_date:    this.expiryDate || null,
          notes:          this.notes.trim() || null,
          _newly_created: this.selected._newly_created ?? false,
        });
      }

      this.clearSelected();
      this.$nextTick(() => { if (window.lucide) lucide.createIcons(); });
    },

    removePending(idx) {
      this.pending.splice(idx, 1);
    },

    // ── Submit ────────────────────────────────────────────────────────────────

    async submit() {
      this.error = this.success = "";
      if (!this.pending.length) { this.error = "Add at least one item first."; return; }

      this.submitting = true;
      try {
        await InventoryAPI.stockIn(
          this.pending.map(p => ({
            item_id:      p.item_id,
            quantity:     p.quantity,
            reason:       p.reason,
            unit_cost:    p.unit_cost,
            batch_number: p.batch_number,
            expiry_date:  p.expiry_date,
            notes:        p.notes,
          })),
          this.supplierId || null
        );
        this.success    = `Stock-in recorded for ${this.pending.length} item(s).`;
        this.pending    = [];
        this.supplierId = "";
        this.clearSelected();
        this.$nextTick(() => { if (window.lucide) lucide.createIcons(); });
      } catch (err) {
        this.error = err.message || "Failed to record stock-in.";
      } finally {
        this.submitting = false;
      }
    },

    // ── Camera scanner ────────────────────────────────────────────────────────

    openScanner() {
      if (typeof Html5Qrcode === "undefined") {
        alert("Camera scanner library not loaded. Try refreshing the page.");
        return;
      }
      this.scannerActive = true;
      // Double rAF: waits until the modal is fully painted (not just in the DOM)
      // so Html5Qrcode can measure the element's real dimensions.
      requestAnimationFrame(() => requestAnimationFrame(() => {
        const cfg     = { fps: 10, qrbox: { width: 260, height: 120 } };
        const onDecode = (decoded) => {
          this.closeScanner();
          this.searchQuery = decoded;
          this.onSearchEnter();
        };
        const tryStart = (constraints) => {
          this._scanner = new Html5Qrcode("stockin-qr-reader");
          return this._scanner.start(constraints, cfg, onDecode, () => {});
        };
        tryStart({
          facingMode: { ideal: "environment" },
          advanced: [{ focusMode: "continuous" }],
        }).catch(() =>
          // Fallback: drop focus constraint if device doesn't support it
          tryStart({ facingMode: "environment" })
        ).catch(() => {
          this.scannerActive = false;
          alert("Camera not available. Make sure the app is served on localhost or HTTPS, and that camera permission is granted.");
        });
      }));
    },

    closeScanner() {
      if (this._scanner) {
        this._scanner.stop().catch(() => {});
        this._scanner = null;
      }
      this.scannerActive = false;
    },
  };
}
