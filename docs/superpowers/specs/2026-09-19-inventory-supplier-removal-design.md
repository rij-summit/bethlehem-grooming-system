# Inventory Supplier Removal Design

## Goal

Remove the Supplier feature from the Inventory module, prevent decimal Stock In quantities, and confirm successful Stock In operations with a top-right toast.

## Scope

- Remove Inventory Supplier pages, routes, API methods, state, models, controllers, transaction fields, and references.
- Remove the selected supplier from the Stock In request and stock movement service.
- Delete the Supplier table and the `inventory_transactions.supplier_id` foreign key and column with one forward migration.
- Require Stock In quantities to be positive whole numbers in both the browser and API validation while retaining the existing decimal quantity columns.
- Show `Stock-in recorded successfully.` in an upper-right toast only after the Stock In API request resolves successfully.

## Database Change

The user explicitly approved only this database change:

1. Drop the foreign key and `supplier_id` column from `inventory_transactions`.
2. Drop the `suppliers` table.

The migration must drop the transaction dependency before dropping the table. It deletes supplier records and their historical transaction associations. No quantity column type changes or other database changes are in scope.

## Design

The Inventory API will no longer register Supplier endpoints, accept `supplier_id` on Stock In, eager load the Supplier relation, or return supplier fields from transaction responses. The Supplier controller and model, Supplier page, Supplier page script, dashboard link, and client API methods will be removed. The stock movement service will receive only the actor and entries.

Stock In’s numeric control will use a whole-number step and minimum. Its client validation will reject non-integers before an item is queued; server-side validation will use integer validation so direct API callers cannot submit a decimal. Stock Out and all persisted quantity columns remain unchanged.

The Stock In component will set a fixed success state only after `InventoryAPI.stockIn` succeeds. The page will bind that state to a fixed upper-right toast and clear it after a short timeout. Failed requests retain the existing error path and never show the toast.

## Verification

- PHP feature tests verify Stock In rejects decimal quantities and Supplier routes are absent.
- JavaScript regression tests verify client decimal rejection, supplier-free request payloads, and toast state after a successful request.
- Run the focused PHP and JavaScript tests, then the project test command.
