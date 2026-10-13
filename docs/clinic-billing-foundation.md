# Clinic service and billing foundation

`clinic_services` remains the configuration catalog. `clinic_case_services` records an individual clinical service occurrence for a `clinic_appointments` case. `clinic_charges` contains its financial line. Existing charges and completed cases are not backfilled or repriced by this migration.

## Recording services

- `GET /api/admin/clinic-cases/{id}/services`: paginated clinical history, including catalog references and linked charges. All users with Clinic access can view it.
- `POST /api/admin/clinic-cases/{id}/services`: create or retry an occurrence; requires clinical permission.
- `PATCH /api/admin/clinic-cases/{id}/services/{caseServiceId}`: update an ordered occurrence or transition its status; requires clinical permission.

Creation requires `clinic_service_id`, a UUID `request_key`, and `status` (`ordered`, `performed`, or `cancelled`). Optional inputs are `quantity` (defaults to 1), `notes`, and `applied_price`. PATCH requires `status` and accepts those optional inputs, but cannot change the catalog reference or retry key.

```json
{
  "clinic_service_id": 12,
  "request_key": "44a22f36-3636-4ca3-bc8c-5fbcbfab068e",
  "status": "ordered",
  "quantity": 1,
  "notes": "CBC ordered"
}
```

The clinical UI must generate and retain one request key for each intended occurrence, including across retries and reloads. A new key represents a separate occurrence; the same catalog service may legitimately be performed more than once. A key cannot be reused for another case or catalog service. A successful new occurrence returns 201; retries and updates return 200.

An ordered occurrence has no charge. Transitioning it to performed resolves its current catalog price and creates exactly one linked charge in the same transaction as the clinical record and case total. A unique database constraint on `clinic_charges.clinic_case_service_id` prevents a second financial line. Operations lock the case, matching existing clinical and payment transaction conventions.

Cancelling an order retains its original timestamp, recording user, notes, cancellation time and cancellation user, without creating a charge. Performed and cancelled occurrences are terminal. Repeated requests return the existing occurrence without rewriting timestamps, snapshots, or financial lines. A delayed POST of the original order cannot undo a terminal transition. Service-generated financial lines remain read-only in Billing.

New clinical writes follow existing case-stage rules: checked in or in consultation, plus the existing admin exception for cases awaiting payment. Paid and archived cases cannot be mutated. A retry of an already completed transition can still return the historical occurrence.

## Price resolution

`ClinicServicePriceResolver` consumes the existing Clinic catalog fields and tier configuration; it introduces no additional price configuration.

- Fixed: the exact configured price applies automatically. There is no manual final-price field; a client-supplied price cannot change the resolved price. Billing cannot reprice it.
- Tiered: the CURRENT case's saved Vitals weight is authoritative. The existing species + weight `PetWeightSize` rules derive the size, then the configured Clinic Service tier determines the price. Profile size, verified profile size, and profile weight never substitute for current case weight. Missing/invalid case weight, unsupported species, or a missing matching tier blocks performance. No manual tier or final-price selection is accepted as pricing authority.
- Range: performance requires an `applied_price` between configured minimum and maximum, inclusive.
- Starting at: performance requires an `applied_price` at least equal to the configured starting price, within the existing technical Clinic monetary limit. There are no Grooming-style +₱500 or +₱200 caps. The technical limit is not presented as a customer-facing service price range.

Range and starting-at services can be ordered before a final price is known. Performance and charge creation succeed together only when that price is supplied. Billing units remain catalog metadata and are preserved in the performance snapshot. Line amount is applied unit price multiplied by quantity, rounded to two decimal places.

The occurrence saves its applied price and historical service metadata at performance, including case weight and derived size for tiered services. Its quantity and applied price determine the linked financial line. Subsequent changes to catalogue names, prices, pricing methods, tiers, active status, species, patient weight, and payment do not recalculate historical performed-service snapshots or charges.

## Case workspace

The existing patient record dialog in `pages/admin/clinic.html` includes Services Performed and Billing tabs alongside Medical Record and Treatment. Services use a compact history table with Ordered, Performed and Cancelled badges. Performed rows display historical snapshots, never current catalogue prices. The Billing table displays all existing charge types and the server's case total without maintaining another invoice calculation.

Clinical users select active services through `GET /api/admin/clinic-service-options`, which reuses the existing catalogue query and always restricts it to active services. Catalogue administration remains admin-only. `GET /api/admin/clinic-cases/{id}/service-pricing/{clinicServiceId}` returns catalogue information and a pricing preview from the backend resolver; it requires clinical permission and does not create any records or charges.

Only Range and Starting At display a Final Price input when marking Performed. Their frontend validation provides guidance; the backend validates the configured rule authoritatively. Fixed shows the configured price read-only. Tiered shows saved case weight, derived size and resolved price read-only, or an error with a link back to Vitals. Unsaved Medical Record weight is not used for pricing. Ordered services resolve the current catalogue rule and current saved case weight when performed.

The workspace keeps a draft and request key in session storage scoped to the acting user and case. An unconfirmed submission retains its exact payload and offers a retry, including after reopening or reloading the case. Submission buttons are disabled while saving. Recoverable validation errors retain entered values. Clinical history, catalogue configuration, Billing and Payment remain separate responsibilities. Before opening the existing payment dialog, Billing includes any remaining charge pages and passes the complete reviewed breakdown with the server total. Payment continues validating that total inside its existing transaction.

## Billing and payment permissions

| Role | Clinic access / intake | Clinical mutations | Billing review / edits | Record payment | Customers / queue |
| --- | --- | --- | --- | --- | --- |
| Administrator | Yes | Yes | Yes | Yes | Yes |
| Veterinarian | Yes | Yes | Yes | No | No |
| Clinic Receptionist | Yes | No | Yes | Yes | Yes |

`/api/me` and sign-in responses include `user.clinic_permissions.billing` through the existing shared permission output. Receptionists retain access to clinical information while clinical mutation routes remain protected by `clinic:clinical`.

- `GET /api/admin/clinic-cases/{id}/charges`: billing total, paid flag, and paginated charges with original service occurrence snapshots; requires billing permission.
- `PATCH /api/admin/clinic-cases/{id}/charges/{chargeId}`: rejects edits for charges linked through `clinic_case_service_id` or `vaccination_record_id` for every role, including admin. Source-generated unit price and financial quantity cannot be changed independently from the finalized clinical action. Billing cannot waive or discount these charges by setting their price to zero. Existing edit behavior for genuinely legacy/unlinked charges remains unchanged; their line amount and case total update atomically without changing inventory usage, clinical records or payment state.
- `POST /api/admin/clinic-appointments/{id}/pay`: existing payment endpoint; still requires payment permission.

Existing edits for unlinked charges remain allowed only on unarchived, unpaid cases that are checked in, in consultation, or awaiting payment. Case-scoped queries prevent editing a charge or service belonging to another case. Billing presents generated service and Treatment lines read-only and connects payment-authorized users to the existing payment dialog, methods, transaction and total validation. Veterinarians can review Billing but cannot record payment. Receptionists can view clinical history and Billing but cannot mutate clinical sources. No discounts, arbitrary price overrides, billing adjustments or admin pricing bypasses are included.

## Treatment and vaccination sources

The existing `VaccinationRecord.treatment_type` distinguishes medicines and vaccines. Drafts consume no stock and create no charge. Publication locks the clinical record and case, validates Inventory availability, and performs publication, one stock deduction, one linked charge and `ClinicBillingService::updateTotal` within the same transaction. Published-record retries return the existing 409 conflict and cannot repeat the effects. A nullable unique foreign key from `inventory_transactions.vaccination_record_id` protects new linked stock movements; legacy movements remain unlinked.

Inventory owns product prices. `ClinicMedicineChargeService` reuses `InventoryItem::clinicPrice` and `ProductWeightPricing`. Weight-based medicines require the current case's **saved Vitals weight**; profile weight and unsaved fields do not substitute. An uncovered weight range blocks publication. Expected price/weight values only guard against stale previews. Flat medicines need no weight. Vaccines use configured Inventory selling price and existing unexpired FEFO stock selection. Clinical dose, physical quantity and financial quantity remain separate: a published product administration has financial quantity 1 under the existing pricing semantics.

Historical/external records without Inventory linkage consume no current stock, create no current charge, and cannot finish a case. Published price, weight-range and batch/expiry snapshots remain unchanged when Inventory configuration or pet weight changes. The existing published-record void is an auditable correction only; it retains the original stock movement and charge. It does not provide restocking, refunds or invoice reversals.

## Confinement handoff

One `clinic_confinements` episode belongs to the same Clinic Case and links its base `ClinicCaseService`. An active episode keeps the appointment `in_consultation`. Admission orders an eligible Boarding & Lodging service with no charge. Monitoring adds history only; existing Treatment and Services Performed continue generating their normal charges. Ordinary completion and direct performance/cancellation of the base confinement order are blocked while it is active.

Discharge resolves current catalogue pricing through the existing service resolver, derives quantity from exact elapsed duration for daily billing, performs that ordered occurrence once, snapshots its service information and generates its charge. It marks the episode discharged and reuses `prepareForPayment()` in the same transaction. There are no daily financial updates or estimated charge rows. See [Clinic confinement workflow](clinic-confinement-workflow.md).
