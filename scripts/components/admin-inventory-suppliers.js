function adminInventorySuppliers() {
  return {
    suppliers: [],
    loading: true,
    error: "",
    toast: { show: false, msg: "", ok: true },

    q: "",
    currentPage: 1,
    lastPage: 1,
    total: 0,
    _searchTimer: null,

    modal: { open: false, busy: false, error: "" },
    form: {
      supplier_id: null,
      supplier_name: "", contact_person: "",
      phone: "", email: "", address: "", notes: "",
    },

    deactivateTarget: null,
    deactivateModal: { open: false, busy: false, error: "" },

    async init() {
      await this.load();
      this.$nextTick(() => { if (window.lucide) lucide.createIcons(); });
    },

    async load(page = 1) {
      this.loading = true;
      this.error   = "";
      try {
        const res = await InventoryAPI.getSuppliers({ q: this.q, paginate: true, page });
        this.suppliers   = res.data;
        this.currentPage = res.page;
        this.lastPage    = res.last_page;
        this.total       = res.total;
      } catch (err) {
        this.error = err.message || "Failed to load suppliers.";
      } finally {
        this.loading = false;
        this.$nextTick(() => { if (window.lucide) lucide.createIcons(); });
      }
    },

    onSearchInput() {
      clearTimeout(this._searchTimer);
      this._searchTimer = setTimeout(() => this.load(1), 350);
    },

    prevPage() { if (this.currentPage > 1) this.load(this.currentPage - 1); },
    nextPage() { if (this.currentPage < this.lastPage) this.load(this.currentPage + 1); },

    openAdd() {
      this.form = { supplier_id: null, supplier_name: "", contact_person: "", phone: "", email: "", address: "", notes: "" };
      this.modal = { open: true, busy: false, error: "" };
    },

    openEdit(s) {
      this.form = {
        supplier_id:    s.supplier_id,
        supplier_name:  s.supplier_name,
        contact_person: s.contact_person ?? "",
        phone:          s.phone ?? "",
        email:          s.email ?? "",
        address:        s.address ?? "",
        notes:          s.notes ?? "",
      };
      this.modal = { open: true, busy: false, error: "" };
    },

    async submitForm() {
      this.modal.error = "";
      if (!this.form.supplier_name.trim()) { this.modal.error = "Supplier name is required."; return; }

      this.modal.busy = true;
      try {
        const payload = {
          supplier_name:  this.form.supplier_name.trim(),
          contact_person: this.form.contact_person.trim() || null,
          phone:          this.form.phone.trim() || null,
          email:          this.form.email.trim() || null,
          address:        this.form.address.trim() || null,
          notes:          this.form.notes.trim() || null,
        };
        if (this.form.supplier_id) {
          await InventoryAPI.updateSupplier(this.form.supplier_id, payload);
          this.showToast("Supplier updated.");
        } else {
          await InventoryAPI.createSupplier(payload);
          this.showToast("Supplier added.");
        }
        this.modal.open = false;
        await this.load(this.currentPage);
      } catch (err) {
        this.modal.error = err.errors
          ? Object.values(err.errors).flat().join(" ")
          : (err.message || "Failed to save.");
      } finally {
        this.modal.busy = false;
      }
    },

    confirmDeactivate(s) {
      this.deactivateTarget = s;
      this.deactivateModal  = { open: true, busy: false, error: "" };
    },

    async doDeactivate() {
      if (!this.deactivateTarget) return;
      this.deactivateModal.busy = true;
      this.deactivateModal.error = "";
      try {
        await InventoryAPI.deactivateSupplier(this.deactivateTarget.supplier_id);
        this.deactivateModal.open = false;
        this.showToast("Supplier removed.");
        await this.load(this.currentPage);
      } catch (err) {
        this.deactivateModal.error = err.message || "Failed to remove supplier.";
      } finally {
        this.deactivateModal.busy = false;
      }
    },

    showToast(msg, ok = true) {
      this.toast = { show: true, msg, ok };
      setTimeout(() => { this.toast.show = false; }, 3000);
    },
  };
}
