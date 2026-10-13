function getClinicSection(hash = window.location.hash) {
  return hash === '#services' && API.getUserRole() === 'admin' ? 'services' : 'cases';
}

let clinicServicesScript;
function loadClinicServicesScript() {
  return clinicServicesScript ??= new Promise((resolve, reject) => {
    const script = document.createElement('script');
    script.src = '../../scripts/components/admin-clinic-services.js?v=20261013-service-pricing-v3';
    script.onload = resolve;
    script.onerror = () => {
      clinicServicesScript = null;
      script.remove();
      reject(new Error('Unable to load Clinic Services. Please try again.'));
    };
    document.head.appendChild(script);
  });
}

function adminClinicShell() {
  let pendingServices;
  return {
    activeSection: getClinicSection(), sectionLoading: false, sectionError: '',
    _navigationListener: null,
    init() {
      this._navigationListener = () => this.showSection();
      window.addEventListener('hashchange', this._navigationListener);
      this.$nextTick(() => this.showSection());
    },
    destroy() { window.removeEventListener('hashchange', this._navigationListener); },
    async mountServices() {
      if (this.$refs.services.firstElementChild) return this.$refs.services.firstElementChild;
      return pendingServices ??= (async () => {
        const [response] = await Promise.all([
          fetch('./clinic/sections/services.html?v=20261013-service-pricing-v3'), loadClinicServicesScript(),
        ]);
        if (!response.ok) throw new Error('Unable to load Clinic Services. Please try again.');
        const panel = document.createElement('section');
        panel.setAttribute('x-data', 'adminClinicServices()');
        panel.setAttribute('x-show', "activeSection === 'services'");
        panel.style.display = 'none';
        panel.innerHTML = await response.text();
        Alpine.mutateDom(() => {
          this.$refs.services.appendChild(panel);
          Alpine.initTree(panel);
        });
        return panel;
      })().finally(() => { pendingServices = null; });
    },
    async showSection() {
      const section = getClinicSection();
      if (window.location.hash !== `#${section}`) history.replaceState(null, '', `#${section}`);
      if (this.activeSection !== section) {
        const servicePanel = this.$refs.services.firstElementChild;
        if (servicePanel) {
          Alpine.$data(servicePanel).closeModal(true);
          Alpine.$data(servicePanel).closeDetails(false);
        }
        window.scrollTo(0, 0);
      }
      this.activeSection = section;
      this.sectionError = '';
      this.sectionLoading = section === 'services' && !this.$refs.services.firstElementChild;
      document.title = `Clinic ${section === 'services' ? 'Services' : 'Cases'} | Bethlehem Animal Clinic`;
      try {
        const panel = section === 'services' ? await this.mountServices() : this.$refs.cases.firstElementChild;
        if (getClinicSection() !== section) return;
        this.sectionLoading = false;
        await Alpine.$data(panel).activate();
      } catch (error) {
        if (getClinicSection() === section) this.sectionError = error.message || 'Unable to open this Clinic section.';
      } finally {
        if (getClinicSection() === section) this.sectionLoading = false;
      }
    },
  };
}

// Keep Cases in the existing file. Initialize its data only on the first visit.
function adminClinicCasesSection() {
  const component = adminClinicPage();
  const initialize = component.init;
  return Object.assign(component, {
    _initialized: false,
    init() {},
    async activate() {
      if (this._initialized) return;
      this._initialized = true;
      await initialize.call(this);
    },
  });
}
