function adminPos() {
  return {
    searchQuery: "",
    searchResults: [],
    _searchTimer: null,

    cart: [],
    amountTendered: "",
    notes: "",

    submitting: false,
    error: "",

    // Receipt modal
    receipt: null,
    receiptOpen: false,

    async init() {
      this.$nextTick(() => { if (window.lucide) lucide.createIcons(); });
    },

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

    async scanBarcode(code) {
      if (!code) return;
      try {
        const res = await InventoryAPI.findByBarcode(code);
        this.addToCart(res.data);
        this.searchQuery = "";
        this.searchResults = [];
      } catch {
        this.error = "Barcode not found.";
      }
    },

    addToCart(item) {
      const available = parseFloat(item.unexpired_quantity ?? 0);
      if (available <= 0) {
        this.error = `No unexpired stock is available for ${item.item_name}.`;
        return;
      }

      const existing = this.cart.find(c => c.item_id === item.item_id);
      if (existing) {
        existing.stock = available;
        existing.quantity = Math.min(existing.quantity + 1, available);
        existing.subtotal = parseFloat((existing.quantity * existing.price).toFixed(2));
      } else {
        this.cart.push({
          item_id:   item.item_id,
          item_name: item.item_name,
          unit:      item.unit,
          stock:     available,
          quantity:  1,
          price:     parseFloat(item.selling_price ?? 0),
          subtotal:  parseFloat(item.selling_price ?? 0),
        });
      }
      this.searchQuery = "";
      this.searchResults = [];
      this.error = "";
    },

    updateLineSubtotal(line) {
      const qty = parseFloat(line.quantity) || 0;
      line.subtotal = parseFloat((qty * line.price).toFixed(2));
    },

    removeFromCart(idx) {
      this.cart.splice(idx, 1);
    },

    clearCart() {
      this.cart = [];
      this.amountTendered = "";
      this.notes = "";
      this.error = "";
    },

    get totalAmount() {
      return this.cart.reduce((sum, l) => sum + (parseFloat(l.subtotal) || 0), 0);
    },

    get changeAmount() {
      const tendered = parseFloat(this.amountTendered) || 0;
      return Math.max(tendered - this.totalAmount, 0);
    },

    get canProcess() {
      return this.cart.length > 0 && (parseFloat(this.amountTendered) || 0) >= this.totalAmount;
    },

    async processSale() {
      this.error = "";
      if (!this.cart.length) { this.error = "Cart is empty."; return; }
      const tendered = parseFloat(this.amountTendered) || 0;
      if (tendered < this.totalAmount) { this.error = "Amount tendered is less than total."; return; }

      for (const line of this.cart) {
        const qty = parseFloat(line.quantity) || 0;
        if (qty <= 0) { this.error = `Invalid quantity for ${line.item_name}.`; return; }
        if (qty > line.stock) { this.error = `Insufficient unexpired stock for ${line.item_name}. Available: ${line.stock} ${line.unit}.`; return; }
      }

      this.submitting = true;
      try {
        const res = await InventoryAPI.processSale({
          items: this.cart.map(l => ({
            item_id:       l.item_id,
            quantity:      parseFloat(l.quantity),
            price_at_sale: parseFloat(l.price),
          })),
          total_amount:    parseFloat(this.totalAmount.toFixed(2)),
          amount_tendered: tendered,
          change_amount:   parseFloat(this.changeAmount.toFixed(2)),
          notes:           this.notes || null,
        });
        this.receipt = res.data;
        this.receiptOpen = true;
        this.clearCart();
        this.$nextTick(() => { if (window.lucide) lucide.createIcons(); });
      } catch (err) {
        this.error = err.message || "Sale failed.";
      } finally {
        this.submitting = false;
      }
    },

    fmt(n) {
      return Number(n ?? 0).toLocaleString("en-PH", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    },
  };
}
