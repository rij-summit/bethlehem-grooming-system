function adminStockIn() {
  return {
    // ── Item search ───────────────────────────────────────────────────────────
    searchQuery: "",
    searchResults: [],
    _searchTimer: null,
    selected: null,

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
      if (q.length < 2) { this.searchResults = []; return; }
      clearTimeout(this._searchTimer);
      this._searchTimer = setTimeout(async () => {
        try {
          const res = await InventoryAPI.searchItems(q);
          this.searchResults = res.data;
        } catch { this.searchResults = []; }
      }, 300);
    },

    async onSearchEnter() {
      if (!this.searchQuery.trim()) return;
      clearTimeout(this._searchTimer);
      try {
        const res = await InventoryAPI.findByBarcode(this.searchQuery.trim());
        this.pickItem(res.data);
      } catch {
        const res = await InventoryAPI.searchItems(this.searchQuery.trim()).catch(() => ({ data: [] }));
        this.searchResults = res.data;
      }
    },

    pickItem(item) {
      this.selected      = item;
      this.unitCost      = item.unit_cost ?? "";
      this.qty           = "";
      this.batchNumber   = "";
      this.expiryDate    = "";
      this.notes         = "";
      this.entryError    = "";
      this.searchQuery   = "";
      this.searchResults = [];
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
        // Same item but different batch or expiry — must be a separate entry
        const sameBatch   = (existing.batch_number ?? "") === (this.batchNumber.trim() ?? "");
        const sameExpiry  = (existing.expiry_date  ?? "") === (this.expiryDate         ?? "");
        if (!sameBatch || !sameExpiry) {
          this.entryError = "This item is already in the list with a different batch/expiry. Remove the existing entry first, or combine them manually.";
          return;
        }
        existing.quantity  = Math.round((existing.quantity + qty) * 100) / 100;
        // Update cost and notes from the latest entry if provided
        if (this.unitCost !== "") existing.unit_cost = parseFloat(this.unitCost);
        if (this.notes.trim())    existing.notes     = this.notes.trim();
      } else {
        this.pending.push({
          item_id:      this.selected.item_id,
          item_name:    this.selected.item_name,
          unit:         this.selected.unit,
          category:     this.selected.category,
          quantity:     qty,
          unit_cost:    this.unitCost !== "" ? parseFloat(this.unitCost) : null,
          reason:       this.reason,
          batch_number: this.batchNumber.trim() || null,
          expiry_date:  this.expiryDate || null,
          notes:        this.notes.trim() || null,
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
      this.$nextTick(() => {
        this._scanner = new Html5Qrcode("stockin-qr-reader");
        this._scanner.start(
          { facingMode: "environment" },
          { fps: 10, qrbox: { width: 260, height: 120 } },
          (decoded) => {
            this.closeScanner();
            this.searchQuery = decoded;
            this.onSearchEnter();
          },
          () => {}
        ).catch(() => {
          this.scannerActive = false;
          alert("Camera not available. Please allow camera access.");
        });
      });
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
