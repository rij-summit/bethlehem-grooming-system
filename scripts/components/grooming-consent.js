const termsUrl = new URL('../data/grooming-consent.json?v=20261016', import.meta.url);
let agreementRequest;

export function createSignaturePad(canvas, onChange) {
  const context = canvas.getContext('2d');
  let strokes = [];
  let activePointer = null;
  let currentStroke = null;
  canvas.width = 900;
  canvas.height = 360;
  canvas.style.touchAction = 'none';
  context.strokeStyle = '#1e293b';
  context.lineWidth = 3;
  context.lineCap = 'round';
  context.lineJoin = 'round';

  function redraw() {
    context.clearRect(0, 0, canvas.width, canvas.height);
    for (const stroke of strokes) {
      context.beginPath();
      stroke.forEach(([x, y], index) => index ? context.lineTo(x, y) : context.moveTo(x, y));
      context.stroke();
    }
  }

  function point(event) {
    const rect = canvas.getBoundingClientRect();
    return [
      Math.round(Math.max(0, Math.min(900, (event.clientX - rect.left) * 900 / rect.width)) * 100) / 100,
      Math.round(Math.max(0, Math.min(360, (event.clientY - rect.top) * 360 / rect.height)) * 100) / 100,
    ];
  }

  function finish(event) {
    if (event.pointerId !== activePointer) return;
    if (currentStroke.length < 2) strokes.pop();
    const pointer = activePointer;
    activePointer = null;
    currentStroke = null;
    if (canvas.hasPointerCapture(pointer)) canvas.releasePointerCapture(pointer);
    redraw();
    onChange();
  }

  canvas.addEventListener('pointerdown', (event) => {
    if (activePointer !== null || event.button !== 0 || strokes.length >= 100) return;
    event.preventDefault();
    activePointer = event.pointerId;
    currentStroke = [point(event)];
    strokes.push(currentStroke);
    canvas.setPointerCapture(event.pointerId);
    onChange();
  });
  canvas.addEventListener('pointermove', (event) => {
    if (event.pointerId !== activePointer || currentStroke.length >= 1000) return;
    event.preventDefault();
    const next = point(event);
    const previous = currentStroke.at(-1);
    if (Math.hypot(next[0] - previous[0], next[1] - previous[1]) < 1) return;
    currentStroke.push(next);
    context.beginPath();
    context.moveTo(...previous);
    context.lineTo(...next);
    context.stroke();
  });
  ['pointerup', 'pointercancel', 'lostpointercapture'].forEach((name) => canvas.addEventListener(name, finish));

  return {
    get strokes() { return strokes.map(stroke => stroke.map(point => [...point])); },
    get drawing() { return activePointer !== null; },
    get drawn() {
      let length = 0;
      for (const stroke of strokes) {
        for (let i = 1; i < stroke.length; i++) length += Math.hypot(stroke[i][0] - stroke[i - 1][0], stroke[i][1] - stroke[i - 1][1]);
      }
      return length >= 3;
    },
    clear() {
      const pointer = activePointer;
      activePointer = null;
      currentStroke = null;
      if (pointer !== null && canvas.hasPointerCapture(pointer)) canvas.releasePointerCapture(pointer);
      strokes = [];
      redraw();
      onChange();
    },
    restore(saved) {
      strokes = Array.isArray(saved) ? saved : [];
      redraw();
    },
  };
}

export function initGroomingConsent({ storageKey, signerName = '', onChange = () => {} }) {
  const host = document.getElementById('groomingConsentFields');
  host.innerHTML = `
    <section class="rounded-2xl border border-[#91aeca] bg-white p-5">
      <h3 class="mb-4 text-lg font-semibold text-[#2f4b66]">Grooming Agreement</h3>
      <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
        <p data-consent-intro class="text-sm leading-7 text-slate-700"></p>
        <ul data-consent-conditions class="mt-4 space-y-3 text-sm leading-7 text-slate-700"></ul>
      </div>
      <label class="mt-4 flex items-start gap-3 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3">
        <input id="mainConsentCheckbox" type="checkbox" class="mt-1 h-4 w-4 shrink-0 accent-[#315b7e]" aria-describedby="groomingConsentError" />
        <span class="text-sm text-slate-700"><span data-consent-grooming></span> <span class="text-red-500">*</span></span>
      </label>
      <p id="groomingConsentError" class="mt-2 text-sm text-red-600" hidden>Agree to the grooming agreement.</p>
    </section>
    <section class="rounded-2xl border border-[#91aeca] bg-white p-5">
      <h3 class="mb-4 text-lg font-semibold text-[#2f4b66]">Consent for Sedation</h3>
      <label class="flex items-start gap-3 text-sm text-slate-700">
        <input id="sedationConsentCheckbox" type="checkbox" class="mt-1 h-4 w-4 shrink-0 accent-[#315b7e]" />
        <span data-consent-sedation></span>
      </label>
    </section>
    <section class="rounded-2xl border border-[#91aeca] bg-white p-5">
      <h3 id="customerSignatureLabel" class="mb-3 text-lg font-semibold text-[#2f4b66]">Customer Signature <span class="text-red-500">*</span></h3>
      <canvas id="customerSignaturePad" role="img" aria-labelledby="customerSignatureLabel" aria-describedby="customerSignatureError" class="w-full rounded-xl border border-slate-300 bg-slate-50" style="aspect-ratio:5/2; max-width:560px; height:auto; touch-action:none"></canvas>
      <button id="clearCustomerSignature" type="button" class="mt-2 text-sm font-medium text-[#315b7e] hover:underline focus-visible:underline">Clear signature</button>
      <p id="customerSignatureError" class="mt-2 text-sm text-red-600" hidden>Please draw your signature.</p>
      <label for="consentSignerName" class="mb-2 mt-4 block text-sm font-medium text-slate-700">Signer full name</label>
      <input id="consentSignerName" type="text" autocomplete="name" maxlength="200" required aria-describedby="consentSignerError" class="w-full rounded-2xl border border-slate-300 px-4 py-3 text-sm outline-none transition focus:border-[#315b7e] focus:ring-2 focus:ring-[#315b7e]/20" />
      <p id="consentSignerError" class="mt-2 text-sm text-red-600" hidden>Enter the signer’s full name.</p>
    </section>`;
  const grooming = host.querySelector('#mainConsentCheckbox');
  const sedation = host.querySelector('#sedationConsentCheckbox');
  const name = host.querySelector('#consentSignerName');
  let agreement = null;
  let saved = null;
  let busy = false;
  try { saved = JSON.parse(sessionStorage.getItem(storageKey)); } catch { /* Ignore an invalid draft. */ }
  name.value = saved?.signer_name ?? signerName;
  const pad = createSignaturePad(host.querySelector('canvas'), changed);
  const payload = () => ({
    terms_agreed: grooming.checked,
    sedation_consent: sedation.checked,
    signer_name: name.value.trim(),
    signature_strokes: pad.strokes,
    consent_version: agreement?.version,
  });
  function validate() {
    const errors = [
      ['groomingConsentError', !grooming.checked, grooming],
      ['customerSignatureError', !pad.drawn, host.querySelector('canvas')],
      ['consentSignerError', !name.value.trim(), name],
    ];
    for (const [id, invalid, field] of errors) {
      host.querySelector('#' + id).hidden = !invalid;
      field.setAttribute('aria-invalid', String(invalid));
    }
    return Boolean(agreement && grooming.checked && pad.drawn && !pad.drawing && name.value.trim());
  }
  function changed() {
    if (agreement) sessionStorage.setItem(storageKey, JSON.stringify(payload()));
    onChange(validate());
  }
  grooming.addEventListener('change', changed);
  sedation.addEventListener('change', changed);
  name.addEventListener('input', changed);
  host.querySelector('#clearCustomerSignature').addEventListener('click', () => pad.clear());
  agreementRequest ??= fetch(termsUrl).then(response => {
    if (!response.ok) throw new Error('Unable to load the agreement. Please reload.');
    return response.json();
  }).catch(error => { agreementRequest = null; throw error; });
  const ready = agreementRequest.then(terms => {
    agreement = terms;
    host.querySelector('[data-consent-intro]').textContent = terms.grooming_intro;
    for (const condition of terms.grooming_conditions) {
      const item = document.createElement('li');
      item.textContent = '• ' + condition;
      host.querySelector('[data-consent-conditions]').append(item);
    }
    host.querySelector('[data-consent-grooming]').textContent = terms.grooming_action;
    host.querySelector('[data-consent-sedation]').textContent = terms.sedation_action;
    if (saved?.consent_version === terms.version) {
      grooming.checked = saved.terms_agreed === true;
      sedation.checked = saved.sedation_consent === true;
      pad.restore(saved.signature_strokes);
    }
    changed();
  }).catch(error => {
    const status = document.getElementById('consentStatusMessage');
    status.hidden = false;
    status.textContent = error.message;
    onChange(false);
  });
  return {
    ready, payload, validate,
    get valid() { return validate() && !busy; },
    setBusy(value) {
      busy = value;
      grooming.disabled = sedation.disabled = name.disabled = value;
      host.querySelector('#clearCustomerSignature').disabled = value;
      host.querySelector('canvas').style.pointerEvents = value ? 'none' : '';
      onChange(validate() && !busy);
    },
    prefill(fullName) {
      if (!name.value) { name.value = fullName; changed(); }
    },
  };
}
