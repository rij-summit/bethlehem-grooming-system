function adminInventory() {
  return {
    inventoryItems: [],
    searchQuery: "",
    categoryFilter: "",
    statusFilter: "all",
    itemForm: {
      open: false,
      busy: false,
      isEdit: false,
      id: null,
      name: "",
      sku: "",
      category: "Supplies",
      quantity: 0,
      reorderLevel: 0,
      description: "",
    },
    itemFormError: "",
    deleteModal: {
      open: false,
      busy: false,
      item: null,
      error: "",
    },

    init() {
      this.loadInventory();
    },

    get filteredInventory() {
      return this.inventoryItems
        .filter((item) => {
          const query = this.searchQuery.trim().toLowerCase();
          if (!query) return true;
          return [item.name, item.sku, item.category, item.description]
            .filter(Boolean)
            .some((value) => value.toLowerCase().includes(query));
        })
        .filter((item) => {
          if (this.statusFilter === "all") return true;
          return this.statusKey(item) === this.statusFilter;
        })
        .filter((item) => {
          if (!this.categoryFilter) return true;
          return item.category === this.categoryFilter;
        });
    },

    get categoryOptions() {
      const categories = this.inventoryItems.map((item) => item.category || "Uncategorized");
      return [...new Set(categories)].sort();
    },

    get totalSKUs() {
      return this.inventoryItems.length;
    },

    get lowStockCount() {
      return this.inventoryItems.filter((item) => this.statusKey(item) === "low_stock").length;
    },

    loadInventory() {
      const raw = localStorage.getItem("bethlehem.admin.inventory");
      if (raw) {
        try {
          this.inventoryItems = JSON.parse(raw) || [];
        } catch {
          this.inventoryItems = [];
        }
      }

      if (!this.inventoryItems.length) {
        this.inventoryItems = [
          {
            id: this.generateId(),
            name: "Premium Grooming Shampoo",
            sku: "GMP-001",
            category: "Supplies",
            quantity: 18,
            reorderLevel: 10,
            description: "Gentle formula for sensitive skin.",
          },
          {
            id: this.generateId(),
            name: "Sanitary Ear Cleanser",
            sku: "GMP-002",
            category: "Supplies",
            quantity: 6,
            reorderLevel: 8,
            description: "Reduces wax and itching after grooming.",
          },
          {
            id: this.generateId(),
            name: "Large Pet Brush",
            sku: "GMP-011",
            category: "Tools",
            quantity: 30,
            reorderLevel: 5,
            description: "Tangle-free coat maintenance brush.",
          },
        ];
        this.saveInventory();
      }
    },

    saveInventory() {
      localStorage.setItem("bethlehem.admin.inventory", JSON.stringify(this.inventoryItems));
    },

    openItemForm(item = null) {
      this.itemFormError = "";
      if (item) {
        this.itemForm = {
          open: true,
          busy: false,
          isEdit: true,
          id: item.id,
          name: item.name,
          sku: item.sku,
          category: item.category,
          quantity: item.quantity,
          reorderLevel: item.reorderLevel,
          description: item.description || "",
        };
      } else {
        this.itemForm = {
          open: true,
          busy: false,
          isEdit: false,
          id: null,
          name: "",
          sku: "",
          category: "Supplies",
          quantity: 0,
          reorderLevel: 0,
          description: "",
        };
      }

      this.$nextTick(() => {
        if (window.lucide) window.lucide.createIcons();
      });
    },

    closeItemForm() {
      this.itemForm.open = false;
      this.itemFormError = "";
    },

    saveItem() {
      this.itemFormError = "";
      const form = this.itemForm;
      if (!form.name.trim()) {
        this.itemFormError = "Item name is required.";
        return;
      }
      if (!form.sku.trim()) {
        this.itemFormError = "SKU is required.";
        return;
      }
      if (form.quantity < 0) {
        this.itemFormError = "Quantity cannot be negative.";
        return;
      }
      if (form.reorderLevel < 0) {
        this.itemFormError = "Reorder threshold cannot be negative.";
        return;
      }

      form.busy = true;
      const payload = {
        id: form.isEdit ? form.id : this.generateId(),
        name: form.name.trim(),
        sku: form.sku.trim(),
        category: form.category.trim() || "Uncategorized",
        quantity: Number(form.quantity),
        reorderLevel: Number(form.reorderLevel),
        description: form.description.trim(),
      };

      if (form.isEdit) {
        this.inventoryItems = this.inventoryItems.map((item) =>
          item.id === payload.id ? payload : item,
        );
      } else {
        this.inventoryItems.unshift(payload);
      }

      this.saveInventory();
      form.busy = false;
      this.closeItemForm();
    },

    confirmDelete(item) {
      this.deleteModal = {
        open: true,
        busy: false,
        item,
        error: "",
      };

      this.$nextTick(() => {
        if (window.lucide) window.lucide.createIcons();
      });
    },

    executeDelete() {
      if (!this.deleteModal.item) return;
      this.deleteModal.busy = true;

      this.inventoryItems = this.inventoryItems.filter(
        (item) => item.id !== this.deleteModal.item.id,
      );
      this.saveInventory();
      this.deleteModal.busy = false;
      this.deleteModal.open = false;
      this.deleteModal.item = null;
      this.deleteModal.error = "";
    },

    resetFilters() {
      this.searchQuery = "";
      this.categoryFilter = "";
      this.statusFilter = "all";
    },

    generateId() {
      return `inv_${Date.now()}_${Math.floor(Math.random() * 10000)}`;
    },

    statusKey(item) {
      if (item.quantity <= 0) return "out_of_stock";
      if (item.quantity <= item.reorderLevel) return "low_stock";
      return "in_stock";
    },

    statusLabel(item) {
      const key = this.statusKey(item);
      return {
        in_stock: "In stock",
        low_stock: "Low stock",
        out_of_stock: "Out of stock",
      }[key];
    },

    statusClass(item) {
      const key = this.statusKey(item);
      return {
        in_stock: "bg-emerald-100 text-emerald-700",
        low_stock: "bg-amber-100 text-amber-700",
        out_of_stock: "bg-red-100 text-red-700",
      }[key];
    },

    formatNumber(value) {
      return Number(value).toLocaleString("en-PH");
    },
  };
}
