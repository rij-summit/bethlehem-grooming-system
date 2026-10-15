// ── Shared attachment helpers ─────────────────────────────────────────────────

async function downloadClinicAttachment(apptId, attachment) {
  const result = await API.clinicDownloadAttachment(apptId, attachment.id);
  const objectUrl = URL.createObjectURL(result.blob);
  const link = document.createElement("a");
  link.href = objectUrl;
  link.download = result.fileName || attachment.file_name || "clinic-attachment";
  document.body.appendChild(link);
  link.click();
  link.remove();
  setTimeout(() => URL.revokeObjectURL(objectUrl), 1000);
}

async function viewClinicAttachment(apptId, attachment) {
  // Open the tab before awaiting so popup blockers treat it as user-initiated.
  const win = window.open("", "_blank");
  try {
    const result = await API.clinicDownloadAttachment(apptId, attachment.id);
    const type = result.blob.type || result.contentType || "";
    if (!win || !(type.startsWith("image/") || type === "application/pdf")) {
      // Browsers can't render DICOM; fall back to a download.
      win?.close();
      await downloadClinicAttachment(apptId, attachment);
      return;
    }
    const objectUrl = URL.createObjectURL(result.blob);
    win.location.href = objectUrl;
    setTimeout(() => URL.revokeObjectURL(objectUrl), 60000);
  } catch (error) {
    win?.close();
    throw error;
  }
}

