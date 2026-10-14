# Clinic confinement workflow

`ClinicAppointment` remains the parent of Medical Record, saved Vitals, Treatment, Services Performed, Confinement, Billing and Payment. A unique `clinic_confinements.clinic_appointment_id` allows one episode per visit. Readmission uses a new case. Patient and owner information stays on existing records.

Confinement is an episode, not another appointment status. `admitted_at` with no `discharged_at` means active confinement while the case remains `in_consultation`. The Clinic page's paginated Confined list and count use this relationship; Open uses the existing case workspace. Duration refreshes for display only.

## Admission and care

Clinic Services is the pricing source of truth. Confinement services have a structured `charge_behavior`: `base_confinement`, `admission_charge`, or `additional_charge`. Existing rows are not classified by editable names; an admin must configure their behavior. The catalogue enforces one active Base Confinement and automatically sets Tiered / Pet Size / Day, with all four size prices required.

Admission requires clinical permission, an ongoing unpaid/unarchived case and the saved Medical Record's Primary Attending Veterinarian. Staff enter only a reason and optional notes. The server selects the single active Base Confinement, validates its tiers, and resolves saved case Vitals weight through `ClinicServicePriceResolver` / `PetWeightSize`. Missing/ambiguous configuration or missing weight blocks admission with an actionable message.

Admission creates one ordered base `ClinicCaseService`, with the retained UUID request key, and no base charge. All active Admission Charges are added once automatically. Fixed/tiered admission charges are finalized immediately; Range/Starting At charges remain pending for their final price at discharge. The episode preserves the base service association even after catalogue edits/deactivation.

`clinic_confinement_observations` stores append-only chronological observations with server time, recording user and a unique request key. Temperature, heart rate, respiratory rate and notes are optional individually, but at least one value is required. Monitoring neither replaces saved `ClinicVital` values nor creates stock movements or charges.

Care continues to use the existing Treatment, Medical Record and Billing paths. The Additional Charges selector contains only active Confinement / Additional Charge services. None/service units are one-time charges with quantity 1; Day units start at server time and remain pending until Stop or discharge. A stopped daily charge can be started again as a new occurrence. An already running occurrence cannot be duplicated. Staff never supply duration or quantity.

`clinic_confinement_charges` links each automatic/ad hoc charge to its existing `ClinicCaseService`, retaining behavior and start/stop timestamps. Removal cancels the source, removes its generated bill line, and retains a tombstone so a retried creation cannot recreate it. Admission charges and confinement sources cannot be changed through ordinary Services Performed. Monitoring remains optional.

## Discharge

Discharge locks the case and episode, checks eligibility and existing completion requirements, calculates the server discharge timestamp and final quantity, automatically stops all running daily extras at that same timestamp, then performs all pending confinement services through `ClinicCaseServiceRecorder` and `ClinicServicePriceResolver`.

- Billing unit `day`: `max(1, ceil(elapsed_seconds / 86400))`. Six hours and exactly 24 hours each bill one day; 24 hours plus one minute bills two days. Calendar dates do not determine quantity.
- Additional Charge with unset/service unit and Admission Charge: quantity 1.
- Fixed: exact catalogue price, no manual input.
- Tiered: current saved case Vitals weight and existing `PetWeightSize` rules, no manual tier/price.
- Range/Starting At: final applied unit price required, using the existing catalogue bounds. Billable quantity remains automatic.

Each finalization creates one uniquely linked charge through the existing recorder/resolver, updates the case total, retains the performance snapshot, marks the episode discharged and calls existing `prepareForPayment()`. The same case moves to `for_payment`, with the existing payment-due behavior. A failure rolls back all these effects. Ordinary Complete/Finish actions fail while an episode remains active, including Treatment's Save and Finish path.

Discharged episodes and monitoring remain visible in the case; active Confined lists exclude them. Catalogue changes and subsequent payment do not reprice finalized charges. Pending charges use the associated catalogue service at finalization, including if it was deactivated after it was started; they never switch to a replacement service. Price/method/tier/weight snapshots remain on the existing case-service record.

## Permissions and retries

Admins and veterinarians can admit, record monitoring, treat and discharge. Clinic receptionists can view confinement, monitoring and clinical information, review Billing and record Payment at the existing payment stage. They cannot perform clinical mutations. Veterinarians retain Billing permission and have no payment-recording permission.

Case locks serialize admission/discharge against clinical and payment writes. Unique episode, source-charge and treatment stock-movement links prevent duplicates. Admission and monitoring retries reuse their UUIDs; a discharged episode returns its historical result on repeated discharge. The UI retains exact unconfirmed requests in session storage scoped by user/case, disables competing actions during saving and offers a safe retry after an uncertain response. No daily charge job, second invoice or copied Billing records are created.

## API

- `GET /api/admin/clinic-confinements`: paginated active confined cases (Clinic access).
- `GET /api/admin/clinic-confinement-service-options`: active Additional Charge options only (clinical).
- `GET` / `POST /api/admin/clinic-cases/{id}/confinement`: view / admit.
- `GET /api/admin/clinic-cases/{id}/confinement/admission-preview`: automatically selected base and resolved rate.
- `POST /api/admin/clinic-cases/{id}/confinement/charges`: add an optional charge using a retained UUID.
- `POST /api/admin/clinic-cases/{id}/confinement/charges/{chargeId}/stop`: finalize a running daily charge.
- `DELETE /api/admin/clinic-cases/{id}/confinement/charges/{chargeId}`: remove an extra while the case is editable.
- `GET` / `POST /api/admin/clinic-cases/{id}/confinement/observations`: paginated history / record.
- `GET /api/admin/clinic-cases/{id}/confinement/discharge-preview`: catalogue pricing and read-only derived quantity (clinical).
- `POST /api/admin/clinic-cases/{id}/confinement/discharge`: finalize and send to Billing (clinical).

Read endpoints require Clinic access; write endpoints require existing clinical permission. Billing and Payment retain their existing separate permissions.

Apply `2026_10_14_000002_configure_confinement_charges.php` before serving the updated workspace. It adds nullable catalogue behavior and confinement-charge links without classifying services by name or changing historical bills. Configure existing Confinement catalogue entries explicitly; no prices are embedded in the workflow.
