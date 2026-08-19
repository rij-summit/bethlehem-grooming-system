function adminStockOut() {
  return {
    // ── Item search ───────────────────────────────────────────────────────────
    searchQuery: "",
    searchResults: [],
    _searchTimer: null,
    selected: null,

    // ── Current entry ─────────────────────────────────────────────────────────
    qty: "",
    reason: "used",
    sellingPrice: "",
    notes: "",
    entryError: "",

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
      this.$nextTick(() => { if (window.lucide) lucide.createIcons(); });
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
      this.sellingPrice  = item.selling_price ?? "";
      this.qty           = "";
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
      this.selected     = null;
      this.qty          = "";
      this.sellingPrice = "";
      this.notes        = "";
      this.entryError   = "";
    },

    availableForReason(item = this.selected, reason = this.reason) {
      if (!item) return 0;
      if (["sold", "used"].includes(reason)) {
        return parseFloat(item.unexpired_quantity ?? 0);
      }
      if (reason === "expired") {
        return parseFloat(item.expired_quantity ?? 0);
      }
      return parseFloat(item.quantity_on_hand ?? 0);
    },

    // ── Add to pending ────────────────────────────────────────────────────────

    addToPending() {
      this.entryError = "";
      if (!this.selected) { this.entryError = "Select an item first."; return; }

      const qty = parseFloat(this.qty);
      if (!qty || qty <= 0) { this.entryError = "Enter a valid quantity."; return; }

      const available = this.availableForReason();
      const availabilityLabel = ["sold", "used"].includes(this.reason)
        ? "unexpired"
        : (this.reason === "expired" ? "expired" : "physical");
      if (qty > available) {
        this.entryError = `Only ${available} ${this.selected.unit} ${availabilityLabel} stock available.`;
        return;
      }

      const pendingForItem = this.pending.filter(p => p.item_id === this.selected.item_id);
      const pendingPhysical = pendingForItem.reduce((sum, p) => sum + p.quantity, 0);
      const physicalAvailable = parseFloat(this.selected.quantity_on_hand ?? 0);
      if (pendingPhysical + qty > physicalAvailable) {
        this.entryError = `Total would exceed physical stock (${physicalAvailable} ${this.selected.unit}).`;
        return;
      }

      const sameAvailabilityGroup = this.reason === "expired"
        ? ["expired"]
        : (["sold", "used"].includes(this.reason) ? ["sold", "used"] : []);
      const pendingForAvailability = pendingForItem
        .filter(p => sameAvailabilityGroup.includes(p.reason))
        .reduce((sum, p) => sum + p.quantity, 0);
      if (sameAvailabilityGroup.length && pendingForAvailability + qty > available) {
        this.entryError = `Total would exceed available ${availabilityLabel} stock (${available} ${this.selected.unit}).`;
        return;
      }

      const existing = this.pending.find(p =>
        p.item_id === this.selected.item_id && p.reason === this.reason
      );
      if (existing) {
        const newQty = Math.round((existing.quantity + qty) * 100) / 100;
        existing.quantity      = newQty;
        if (this.sellingPrice !== "") existing.selling_price = parseFloat(this.sellingPrice);
        if (this.notes.trim())        existing.notes         = this.notes.trim();
      } else {
        this.pending.push({
          item_id:       this.selected.item_id,
          item_name:     this.selected.item_name,
          unit:          this.selected.unit,
          category:      this.selected.category,
          quantity:      qty,
          reason:        this.reason,
          selling_price: this.sellingPrice !== "" ? parseFloat(this.sellingPrice) : null,
          notes:         this.notes.trim() || null,
        });
      }

      this.clearSelected();
      this.$nextTick(() => { if (window.lucide) lucide.createIcons(); });
    },

    removePending(idx) {
      this.pending.splice(idx, 1);
    },

    // ── Camera scanner ────────────────────────────────────────────────────────

    openScanner() {
      if (typeof Html5Qrcode === "undefined") {
        alert("Camera scanner library not loaded. Try refreshing the page.");
        return;
      }
      this.scannerActive = true;
      requestAnimationFrame(() => requestAnimationFrame(() => {
        const cfg      = { fps: 10, qrbox: { width: 260, height: 120 } };
        const onDecode = (decoded) => {
          this.closeScanner();
          this.searchQuery = decoded;
          this.onSearchEnter();
        };
        const tryStart = (constraints) => {
          this._scanner = new Html5Qrcode("stockout-qr-reader");
          return this._scanner.start(constraints, cfg, onDecode, () => {});
        };
        tryStart({
          facingMode: { ideal: "environment" },
          advanced: [{ focusMode: "continuous" }],
        }).catch(() =>
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

    // ── Submit ────────────────────────────────────────────────────────────────

    async submit() {
      this.error = this.success = "";
      if (!this.pending.length) { this.error = "Add at least one item first."; return; }

      this.submitting = true;
      try {
        await InventoryAPI.stockOut(
          this.pending.map(p => ({
            item_id:       p.item_id,
            quantity:      p.quantity,
            reason:        p.reason,
            selling_price: p.selling_price,
            notes:         p.notes,
          }))
        );
        this.success    = `Stock-out recorded for ${this.pending.length} item(s).`;
        this.pending    = [];
        this.clearSelected();
        this.$nextTick(() => { if (window.lucide) lucide.createIcons(); });
      } catch (err) {
        this.error = err.message || "Failed to record stock-out.";
      } finally {
        this.submitting = false;
      }
    },
  };
}
