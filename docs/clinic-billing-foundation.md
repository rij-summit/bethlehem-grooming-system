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

Cancelling an order retains its original timestamp, recording user, notes, cancellation time and cancellation user, without creating a charge. Performed and cancelled occurrences are terminal. Repeated requests return the existing occurrence without rewriting timestamps, snapshots, or adjusted financial lines. A delayed POST of the original order cannot undo a terminal transition. Financial corrections use the billing endpoint.

New clinical writes follow existing case-stage rules: checked in or in consultation, plus the existing admin exception for cases awaiting payment. Paid and archived cases cannot be mutated. A retry of an already completed transition can still return the historical occurrence.

## Price resolution

`ClinicServicePriceResolver` consumes the existing Clinic catalog fields and tier configuration; it introduces no additional price configuration.

- Fixed: configured price applies automatically.
- Tiered: configured `pet_size` tier applies using the existing verified-size and weight-derived-size rules. Current case weight takes precedence over profile weight when deriving size. A missing matching tier rejects performance.
- Range: performance requires an `applied_price` between configured minimum and maximum, inclusive.
- Starting at: performance requires an `applied_price` at least equal to the configured starting price, within the existing Clinic price limit.

Range and starting-at services can be ordered before a final price is known. Performance and charge creation succeed together only when that price is supplied. Billing units remain catalog metadata and are preserved in the performance snapshot. Line amount is applied unit price multiplied by quantity, rounded to two decimal places.

The occurrence saves its applied price and historical service metadata at performance. Its original quantity and clinical timestamps remain separate from the financial line. Subsequent catalog edits, deactivation, and payment do not recalculate historical charges.

## Billing and payment permissions

| Role | Clinic access / intake | Clinical mutations | Billing review / edits | Record payment | Customers / queue |
| --- | --- | --- | --- | --- | --- |
| Administrator | Yes | Yes | Yes | Yes | Yes |
| Veterinarian | Yes | Yes | Yes | No | No |
| Clinic Receptionist | Yes | No | Yes | Yes | Yes |

`/api/me` and sign-in responses include `user.clinic_permissions.billing` through the existing shared permission output. Receptionists retain access to clinical information while clinical mutation routes remain protected by `clinic:clinical`.

- `GET /api/admin/clinic-cases/{id}/charges`: billing total, paid flag, and paginated charges with original service occurrence snapshots; requires billing permission.
- `PATCH /api/admin/clinic-cases/{id}/charges/{chargeId}`: edit financial `quantity` and/or `unit_price`; recalculates the line amount and case total atomically. It does not update the clinical occurrence, catalog, inventory usage, or payment state. A zero unit price can waive a financial charge while preserving the performed-service history.
- `POST /api/admin/clinic-appointments/{id}/pay`: existing payment endpoint; still requires payment permission.

Billing edits are allowed only on unarchived, unpaid cases that are checked in, in consultation, or awaiting payment. Case-scoped queries prevent editing a charge or service belonging to another case. No full Billing UI or Confinement workflow is included.
