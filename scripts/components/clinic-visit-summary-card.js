function escapeHtml(value) {
  return String(value ?? "")
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#039;");
}

export function getClinicVisitSummaryMarkup({
  visitType,
  dateLabel = "",
  timeLabel = "",
  pet = null,
  reason = "",
} = {}) {
  const petName = escapeHtml(pet?.petName || pet?.name || "Not selected");
  const petType = escapeHtml(pet?.petType || pet?.species || "Not specified");
  const petBreed = escapeHtml(pet?.breed || "Not specified");

  return `
    <div class="mb-6 grid gap-4 md:grid-cols-2">
      <div class="rounded-2xl border border-slate-200 bg-white p-4">
        <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Visit Type</p>
        <p class="mt-2 text-sm text-slate-600">${escapeHtml(visitType)}</p>
      </div>
      ${
        dateLabel
          ? `
            <div class="rounded-2xl border border-slate-200 bg-white p-4">
              <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Visit Date</p>
              <p class="mt-2 text-sm font-semibold text-[#2f4b66]">${escapeHtml(dateLabel)}</p>
            </div>
          `
          : `
            <div class="rounded-2xl border border-slate-200 bg-white p-4">
              <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Patient</p>
              <p class="mt-2 text-sm font-semibold text-[#2f4b66]">${petName}</p>
            </div>
          `
      }
    </div>

    ${
      dateLabel
        ? `
          <div class="mb-6 grid gap-4 md:grid-cols-2">
            ${
              timeLabel
                ? `
                  <div class="rounded-2xl border border-slate-200 bg-white p-4">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Visit Time</p>
                    <p class="mt-2 text-sm font-semibold text-[#2f4b66]">${escapeHtml(timeLabel)}</p>
                  </div>
                `
                : ""
            }
            <div class="rounded-2xl border border-slate-200 bg-white p-4">
              <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Patient</p>
              <p class="mt-2 text-sm font-semibold text-[#2f4b66]">${petName}</p>
              <p class="mt-1 text-sm text-slate-500">${petType}${petBreed !== "Not specified" ? ` &middot; ${petBreed}` : ""}</p>
            </div>
          </div>
        `
        : ""
    }

    <div class="mb-6 rounded-2xl border border-slate-200 bg-white p-4">
      <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Reason for Visit</p>
      <p class="mt-2 whitespace-pre-wrap text-sm text-slate-600">${escapeHtml(reason)}</p>
    </div>
  `;
}
