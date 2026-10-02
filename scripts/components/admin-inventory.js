// The shell owns navigation and resource loading; each workflow keeps its own state.
const InventorySections = {
  overview: { script: "admin-inventory-dashboard.js", factory: "adminInventoryDashboard", dependencies: ["inventory-alerts.js"] },
  products: { script: "admin-inventory-items.js", factory: "adminInventoryItems", dependencies: ["product-form.js", "inventory-alerts.js", "scanner"] },
  "stock-in": { script: "admin-stock-in.js", factory: "adminStockIn", dependencies: ["product-form.js", "success-toast.js", "scanner"] },
  "stock-out": { script: "admin-stock-out.js", factory: "adminStockOut", dependencies: ["product-form.js", "success-toast.js", "scanner"] },
  history: { script: "admin-inventory-transactions.js", factory: "adminInventoryTransactions", dependencies: [] },
};

function inventoryRoute(hash = window.location.hash) {
  const [name, query = ""] = hash.replace(/^#/, "").split("?");
  const section = Object.hasOwn(InventorySections, name) ? name : "overview";
  const filter = new URLSearchParams(query).get("filter");
  return { section, filter: section === "products" && ["low-stock", "expiry"].includes(filter) ? filter : "" };
}

const inventoryScripts = new Map();
function loadInventoryScript(name) {
  if (!inventoryScripts.has(name)) {
    const promise = new Promise((resolve, reject) => {
      const script = document.createElement("script");
      script.src = name === "scanner"
        ? "https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"
        : `../../../scripts/components/${name}?v=product-details-20261002`;
      script.onload = resolve;
      script.onerror = () => {
        script.remove();
        inventoryScripts.delete(name);
        reject(new Error("Unable to load this inventory section. Please try again."));
      };
      document.head.appendChild(script);
    });
    inventoryScripts.set(name, promise);
  }
  return inventoryScripts.get(name);
}

function adminInventory() {
  const pendingPanels = new Map();
  return {
    activeSection: "",
    sectionLoading: false,
    sectionError: "",
    inventoryRevision: 0,
    _hashListener: null,
    _changeListener: null,

    init() {
      if (API.enforceAdminPageAccess && !API.enforceAdminPageAccess()) return;
      this._hashListener = () => this.showSection();
      this._changeListener = () => { this.inventoryRevision += 1; };
      window.addEventListener("hashchange", this._hashListener);
      window.addEventListener("inventory:changed", this._changeListener);
      if (!window.location.hash) history.replaceState(null, "", "#overview");
      return this.showSection();
    },

    destroy() {
      window.removeEventListener("hashchange", this._hashListener);
      window.removeEventListener("inventory:changed", this._changeListener);
    },

    async mountSection(section) {
      const existing = this.$refs.panels.querySelector(`[data-inventory-section="${section}"]`);
      if (existing) return existing;
      if (!pendingPanels.has(section)) {
        const mount = async () => {
          const config = InventorySections[section];
          const [response] = await Promise.all([
            fetch(`./sections/${section}.html?v=product-details-20261002`),
            ...config.dependencies.map(loadInventoryScript),
            loadInventoryScript(config.script),
          ]);
          if (!response.ok) throw new Error("Unable to load this inventory section. Please try again.");
          const markup = await response.text();
          const panel = document.createElement("section");
          panel.dataset.inventorySection = section;
          panel.setAttribute("x-data", `adminInventorySection('${section}')`);
          panel.setAttribute("x-show", `activeSection === '${section}'`);
          panel.style.display = "none";
          if (section === "stock-in" || section === "stock-out") panel.className = "pb-28";
          panel.innerHTML = markup;
          Alpine.mutateDom(() => {
            this.$refs.panels.appendChild(panel);
            Alpine.initTree(panel);
          });
          return panel;
        };
        pendingPanels.set(section, mount().finally(() => pendingPanels.delete(section)));
      }
      return pendingPanels.get(section);
    },

    async showSection() {
      const route = inventoryRoute();
      if (!Object.hasOwn(InventorySections, window.location.hash.slice(1).split("?")[0])) {
        history.replaceState(null, "", "#overview");
      }
      const previous = this.$refs.panels.querySelector(`[data-inventory-section="${this.activeSection}"]`);
      if (previous && this.activeSection !== route.section) Alpine.$data(previous).leave();
      if (this.activeSection !== route.section) window.scrollTo(0, 0);
      this.activeSection = route.section;
      this.sectionError = "";
      this.sectionLoading = !this.$refs.panels.querySelector(`[data-inventory-section="${route.section}"]`);
      document.title = `${({ overview: "Inventory", products: "Products", "stock-in": "Stock In", "stock-out": "Stock Out", history: "Inventory History" })[route.section]} | Bethlehem Animal Clinic`;
      try {
        const panel = await this.mountSection(route.section);
        // A slower first visit must not override a more recent navigation.
        const current = inventoryRoute();
        if (current.section !== route.section || current.filter !== route.filter) return;
        this.sectionLoading = false;
        await Alpine.$data(panel).activate(route, this.inventoryRevision);
      } catch (err) {
        if (inventoryRoute().section === route.section) {
          this.sectionError = err.message || "Unable to open this inventory section.";
        }
      } finally {
        if (inventoryRoute().section === route.section) this.sectionLoading = false;
      }
    },
  };
}

function adminInventorySection(section) {
  const component = window[InventorySections[section].factory]();
  const originalDestroy = component.destroy;
  return Object.assign(component, {
    _loadedRevision: -1,
    _activation: null,

    // Alpine calls this on mount. Loading is deferred to activate(), so hidden
    // sections never fetch data or run a second initialization.
    init() {},

    async activate(route, revision) {
      if (section === "stock-in") {
        const now = new Date();
        now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
        this.today = now.toISOString().slice(0, 10);
      }
      if (this._activation) {
        await this._activation;
        if (inventoryRoute().section !== section) return;
        route = inventoryRoute();
      }
      const filterChanged = section === "products" && this.alertFilter !== route.filter;
      if (section === "products") this.alertFilter = route.filter;
      if (this._loadedRevision === revision && !filterChanged) return;
      const refresh = async () => {
        if (typeof this.load === "function") {
          await this.load(filterChanged ? 1 : (this.currentPage || 1));
        } else {
          // Keep unsent entries intact, but update the selected product's stock
          // and price after a mutation in another inventory section.
          this.searchResults = [];
          this.noResults = false;
          if (this.selected) {
            const response = await InventoryAPI.getItem(this.selected.item_id);
            this.selected = { ...response.data, _newly_created: this.selected._newly_created };
            if (section === "stock-out") this.sellingPrice = this.selected.selling_price ?? "";
          }
          if (this.searchQuery.length >= 2) this.onSearchInput();
        }
        if (!this.error) this._loadedRevision = revision;
      };
      this._activation = refresh().finally(() => { this._activation = null; });
      await this._activation;
    },

    leave() {
      clearTimeout(this._searchTimer);
      if (this.scannerActive) this.closeScanner();
      if (this.details?.open) this.closeDetails(false);
    },

    destroy() {
      this.leave();
      originalDestroy?.call(this);
    },
  });
}
