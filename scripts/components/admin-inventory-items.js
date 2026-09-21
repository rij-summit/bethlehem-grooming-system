function adminInventoryItems() {
  return {
    items: [],
    loading: true,
    error: "",
    toast: { show: false, msg: "", ok: true },

    // Filters + pagination
    q: "",
    category: "",
    showInactive: false,
    currentPage: 1,
    lastPage: 1,
    total: 0,
    perPage: 10,
    _searchTimer: null,

    // Add / edit modal
    modal: { open: false, busy: false, error: "" },
    form: {
      item_id: null,
      item_name: "", barcode: "", category: "",
      unit: "", customUnit: "", description: "",
      unit_cost: "", selling_price: "", reorder_level: "",
    },

    // Deactivate confirm
    deactivateTarget: null,
    deactivateModal: { open: false, busy: false, error: "" },

    // Camera barcode scanner
    scannerActive: false,
    _scanner: null,
    // which field the scanner result goes to: 'search' | 'form'
    _scanTarget: "search",

    async init() {
      await this.load();
      this.$nextTick(() => { if (window.lucide) lucide.createIcons(); });
    },

    // ── List ────────────────────────────────────────────────────────────────

    async load(page = 1) {
      this.loading = true;
      this.error = "";
      try {
        const res = await InventoryAPI.getItems({ q: this.q, category: this.category, inactive_only: this.showInactive, page });
        this.items       = res.data;
        this.currentPage = res.page;
        this.lastPage    = res.last_page;
        this.total       = res.total;
        if (this.items.length === 0 && page > this.lastPage) {
          await this.load(this.lastPage);
          return;
        }
      } catch (err) {
        this.error = err.message || "Failed to load items.";
      } finally {
        this.loading = false;
        this.$nextTick(() => { if (window.lucide) lucide.createIcons(); });
      }
    },

    onSearchInput() {
      clearTimeout(this._searchTimer);
      this._searchTimer = setTimeout(() => this.load(1), 350);
    },

    // On Enter in search box — try exact barcode first, fall back to current list
    async onSearchEnter() {
      if (!this.q.trim()) return;
      try {
        const res = await InventoryAPI.findByBarcode(this.q.trim(), this.showInactive);
        // Found exact match — replace list with single result
        this.items = [res.data];
        this.currentPage = 1;
        this.lastPage = 1;
        this.total = 1;
      } catch {
        // Not a barcode — do normal search
        await this.load(1);
      }
    },

    prevPage() { if (this.currentPage > 1) this.load(this.currentPage - 1); },
    nextPage() { if (this.currentPage < this.lastPage) this.load(this.currentPage + 1); },

    rowNumber(index) {
      return ((this.currentPage - 1) * this.perPage) + index + 1;
    },

    handleBarcodeInput(event) {
      const barcode = ProductForm.normalizeBarcode(event.target.value);
      this.form.barcode = barcode;
      event.target.value = barcode;
    },

    // ── Add / Edit modal ────────────────────────────────────────────────────

    openAdd() {
      this.form = ProductForm.empty();
      this.modal = { open: true, busy: false, error: "" };
      this.$nextTick(() => { if (window.lucide) lucide.createIcons(); });
    },

    openEdit(item) {
      this.form = ProductForm.empty({
        item_id:       item.item_id,
        item_name:     item.item_name,
        barcode:       item.barcode ?? "",
        category:      item.category,
        ...ProductForm.unitFields(item.unit),
        description:   item.description ?? "",
        unit_cost:     item.unit_cost ?? "",
        selling_price: item.selling_price ?? "",
        reorder_level: ProductForm.normalizeReorderLevel(item.reorder_level),
      });
      this.modal = { open: true, busy: false, error: "" };
      this.$nextTick(() => { if (window.lucide) lucide.createIcons(); });
    },

    async submitForm() {
      this.modal.error = "";
      this.modal.error = ProductForm.validate(this.form);
      if (this.modal.error) return;

      this.modal.busy = true;
      try {
        const payload = ProductForm.payload(this.form);
        if (this.form.item_id) {
          await InventoryAPI.updateItem(this.form.item_id, payload);
          this.showToast("Item updated");
        } else {
          await InventoryAPI.createItem(payload);
          this.showToast("Item added");
        }
        this.modal.open = false;
        await this.load(this.currentPage);
      } catch (err) {
        this.modal.error = err.errors
          ? Object.values(err.errors).flat().join(" ")
          : (err.message || "Failed to save");
      } finally {
        this.modal.busy = false;
      }
    },

    // ── Deactivate ──────────────────────────────────────────────────────────

    confirmDeactivate(item) {
      this.deactivateTarget = item;
      this.deactivateModal = { open: true, busy: false, error: "" };
    },

    async doDeactivate() {
      if (!this.deactivateTarget) return;
      this.deactivateModal.busy = true;
      this.deactivateModal.error = "";
      try {
        await InventoryAPI.deactivateItem(this.deactivateTarget.item_id);
        this.deactivateModal.open = false;
        this.showToast("Item deactivated");
        await this.load(this.currentPage);
      } catch (err) {
        this.deactivateModal.error = err.message || "Failed to deactivate.";
      } finally {
        this.deactivateModal.busy = false;
      }
    },

    async doReactivate(item) {
      try {
        await InventoryAPI.reactivateItem(item.item_id);
        this.showToast("Item reactivated");
        await this.load(this.currentPage);
      } catch (err) {
        this.showToast(err.message || "Failed to reactivate.", false);
      }
    },

    // ── Camera barcode scanner ──────────────────────────────────────────────

    openScanner(target = "form") {
      this._scanTarget   = target;
      ProductForm.openBarcodeScanner(this, "inv-qr-reader", (decoded) => this.onScanned(decoded));
    },

    closeScanner() {
      ProductForm.closeBarcodeScanner(this);
    },

    onScanned(barcode) {
      this.closeScanner();
      if (this._scanTarget === "form") {
        this.form.barcode = barcode;
      } else {
        this.q = barcode;
        this.onSearchEnter();
      }
    },

    // ── Helpers ─────────────────────────────────────────────────────────────

    categoryLabel(cat) {
      return {
        medicine:        "Medicine",
        vaccine:         "Vaccine",
        food:            "Food",
        grooming_supply: "Grooming Supply",
        pet_shop:        "Pet Shop",
        miscellaneous:   "Miscellaneous",
      }[cat] ?? cat;
    },

    categoryColor(cat) {
      return {
        medicine:        "background-color:#dbeafe;color:#1d4ed8",
        vaccine:         "background-color:#ede9fe;color:#6d28d9",
        food:            "background-color:#dcfce7;color:#15803d",
        grooming_supply: "background-color:#fef9c3;color:#a16207",
        pet_shop:        "background-color:#fee2e2;color:#b91c1c",
        miscellaneous:   "background-color:#f1f5f9;color:#475569",
      }[cat] ?? "background-color:#f1f5f9;color:#475569";
    },

    stockStyle(item) {
      if (parseFloat(item.quantity_on_hand) <= 0)
        return "background-color:#fee2e2;color:#b91c1c";
      if (item.low_stock)
        return "background-color:#fef3c7;color:#92400e";
      return "background-color:#dcfce7;color:#166534";
    },

    stockLabel(item) {
      if (parseFloat(item.quantity_on_hand) <= 0) return "Out of Stock";
      if (item.low_stock)                          return "Low Stock";
      return "In Stock";
    },

    fmtQty(n) {
      const v = parseFloat(n ?? 0);
      return v % 1 === 0 ? v.toLocaleString() : v.toFixed(2);
    },

    showToast(msg, ok = true) {
      this.toast = { show: true, msg, ok };
      setTimeout(() => { this.toast.show = false; }, 3000);
    },
  };
}
