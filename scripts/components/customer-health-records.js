// Owner-facing fields from the clinic record. Internal clinical data is never rendered.
export const hasHealthValue = (value) => value !== null && value !== undefined && String(value).trim() !== "";

export function healthDate(value) {
  if (!hasHealthValue(value)) return "Not recorded";
  const date = new Date(`${String(value).slice(0, 10)}T00:00:00`);
  return Number.isNaN(date.getTime()) ? String(value) : date.toLocaleDateString("en-PH", {
    year: "numeric", month: "long", day: "numeric",
  });
}

export function clinicVisitFields(record) {
  const medications = (Array.isArray(record.prescriptions) ? record.prescriptions : [])
    .map((item) => [
      [item.medicine, item.strength_concentration].filter(hasHealthValue).join(" "),
      item.dose, item.route, item.frequency, item.duration, item.instructions,
    ].filter(hasHealthValue).join(" · ")).filter(hasHealthValue).join("\n");

  return [
    ["Reason for visit", record.chief_complaint],
    ["Diagnosis", record.diagnosis],
    ["Care provided", record.treatment_given],
    ["Medications", medications],
    ["Follow-up", [
      hasHealthValue(record.follow_up_date) ? healthDate(record.follow_up_date) : "",
      record.follow_up_notes,
    ].filter(hasHealthValue).join("\n")],
  ].filter(([, value]) => hasHealthValue(value));
}
