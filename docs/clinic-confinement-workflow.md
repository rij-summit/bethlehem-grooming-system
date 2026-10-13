# Clinic confinement workflow

`ClinicAppointment` remains the parent of Medical Record, saved Vitals, Treatment, Services Performed, Confinement, Billing and Payment. A unique `clinic_confinements.clinic_appointment_id` allows one episode per visit. Readmission uses a new case. Patient and owner information stays on existing records.

Confinement is an episode, not another appointment status. `admitted_at` with no `discharged_at` means active confinement while the case remains `in_consultation`. The Clinic page's paginated Confined list and count use this relationship; Open uses the existing case workspace. Duration refreshes for display only.

## Admission and care

Admission requires clinical permission, an ongoing unpaid/unarchived case and the saved Medical Record's Primary Attending Veterinarian. The episode snapshots that veterinarian, recording actor and server admission time. Staff supply the admission reason, optional notes and an active service from the existing **Boarding & Lodging** category. Eligible units are unset, service, visit, pet or day. One eligible service is selected automatically; multiple services require selection. No service ID, product name or price is hard-coded.

Admission creates one ordered base `ClinicCaseService`, with a retained UUID request key, and no charge. Its pricing configuration remains in the catalogue. It cannot be performed or cancelled through ordinary Services Performed; discharge owns finalization.

`clinic_confinement_observations` stores append-only chronological observations with server time, recording user and a unique request key. Temperature, heart rate, respiratory rate and notes are optional individually, but at least one value is required. Monitoring neither replaces saved `ClinicVital` values nor creates stock movements or charges.

Care uses the existing Treatment tab (`VaccinationRecord` for medicines/vaccines), Inventory stock validation and generated charges. Additional professional services use existing Services Performed. There is no confinement-specific treatment table or product-pricing rule. Billing shows the server's generated charges; the base confinement charge remains pending until discharge.

## Discharge

Discharge locks the case and episode, checks eligibility and existing completion requirements, calculates the server discharge timestamp and final quantity, then performs the ordered base service through `ClinicCaseServiceRecorder` and `ClinicServicePriceResolver`.

- Billing unit `day`: `max(1, ceil(elapsed_seconds / 86400))`. Six hours and exactly 24 hours each bill one day; 24 hours plus one minute bills two days. Calendar dates do not determine quantity.
- Unset/service/visit/pet: quantity 1 regardless of duration.
- Fixed: exact catalogue price, no manual input.
- Tiered: current saved case Vitals weight and existing `PetWeightSize` rules, no manual tier/price.
- Range/Starting At: final applied unit price required, using the existing catalogue bounds. Billable quantity remains automatic.

The transaction creates one linked charge, updates the case total, retains the performance snapshot, marks the episode discharged and calls existing `prepareForPayment()`. The same case moves to `for_payment`, with the existing payment-due behavior. A failure rolls back all these effects. Ordinary Complete/Finish actions fail while an episode remains active, including Treatment's Save and Finish path.

Discharged episodes and monitoring remain visible in the case; active Confined lists exclude them. Catalogue changes and subsequent payment do not reprice finalized charges.

## Permissions and retries

Admins and veterinarians can admit, record monitoring, treat and discharge. Clinic receptionists can view confinement, monitoring and clinical information, review Billing and record Payment at the existing payment stage. They cannot perform clinical mutations. Veterinarians retain Billing permission and have no payment-recording permission.

Case locks serialize admission/discharge against clinical and payment writes. Unique episode, source-charge and treatment stock-movement links prevent duplicates. Admission and monitoring retries reuse their UUIDs; a discharged episode returns its historical result on repeated discharge. The UI retains exact unconfirmed requests in session storage scoped by user/case, disables competing actions during saving and offers a safe retry after an uncertain response. No daily charge job, second invoice or copied Billing records are created.

## API

- `GET /api/admin/clinic-confinements`: paginated active confined cases (Clinic access).
- `GET /api/admin/clinic-confinement-service-options`: eligible active catalogue options (clinical).
- `GET` / `POST /api/admin/clinic-cases/{id}/confinement`: view / admit.
- `GET` / `POST /api/admin/clinic-cases/{id}/confinement/observations`: paginated history / record.
- `GET /api/admin/clinic-cases/{id}/confinement/discharge-preview`: catalogue pricing and read-only derived quantity (clinical).
- `POST /api/admin/clinic-cases/{id}/confinement/discharge`: finalize and send to Billing (clinical).

Read endpoints require Clinic access; write endpoints require existing clinical permission. Billing and Payment retain their existing separate permissions.

The new migrations add nullable unique treatment stock-source links and confinement tables; they do not backfill or reprice legacy clinical/financial history. Apply migrations before serving the new workspace.
