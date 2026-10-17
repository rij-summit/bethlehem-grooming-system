# Services Management Implementation Plan

**Goal:** One Services area for Grooming and Clinic, with a database-backed grooming catalog shared by both booking flows.

**Architecture:** Extend the existing `services` table without changing IDs/slugs or booking price snapshots. Keep the Clinic service component/API and lazily mount it under Services. Keep the existing grooming card renderers and replace their static catalog with the API response.

**Tech Stack:** Laravel 12, PHP, Alpine, vanilla JavaScript, existing Tailwind/CSS.

**Spec:** User request in this chat, 2026-10-17; repository AGENTS.md.

## Constraints

- Keep Clinic named Clinic and retain existing case routes and functionality.
- Grooming and Clinic are tabs; Grooming contains Grooming Packages and Ala carte.
- Preserve booking card layout, pricing modes, pet applicability, estimates, and historical bookings.
- Keep one editable database catalog; remove the redundant Settings pricing UI and its unused handlers.
- Reuse existing styles and Clinic components; no unrelated redesign or new dependencies.

## Tasks

1. Add focused API/behavior tests for managed catalogs, new identifiers, explicit included IDs, validation, deactivation, and unchanged price snapshots. Observe failures before implementation.
2. Extend `Service`, add an additive/backfill migration, and extend `GroomingPricingController` and routes for listing all, creating, editing, and toggling status. Reuse existing pricing validation/resolver. Keep legacy pricing API compatibility.
3. Replace static definitions in `scripts/services/grooming-service.js` with complete API metadata. Extend estimate/catalog consumers so new services work through forecast, submission, billing, and summaries.
4. Add `pages/admin/services.html` and focused Services components. Reuse Clinic section, dialogs, feedback, and styling. Change shared sidebar to direct Clinic plus top-level Services. Redirect old `clinic.html#services` links to Services Clinic.
5. Remove only Grooming Pricing management from Settings. Verify targeted PHP/JS tests, compile CSS, inspect admin desktop and responsive UI, and exercise both booking selection renderers.

## Review Focus

- Inactive services disappear from new selections but historical service/price records remain.
- Renames never change slugs or IDs; inclusions use IDs rather than description text.
- New packages retain size pricing and work with booking estimates and billing classification.
- Existing cat packages retain their supported sizes; legacy payment rules stay intact.
- Staff permissions for Clinic Services remain enforced; management is admin-only for Grooming.

## Verification

Run the new managed-catalog tests plus existing grooming pricing/payment, estimate, and Clinic catalog/billing tests. Run relevant JavaScript booking and navigation regressions, updating only obsolete expectations. Inspect the running UI with existing data and use isolated fixtures for mutation checks where possible.

## Completed verification

- Applied the additive catalog migration to the running local database; existing IDs, service prices, and historical booking rows were retained.
- Affected PHP suite: 563 tests, 7415 assertions, no failures; one isolated MySQL concurrency check skipped on SQLite.
- JavaScript regression suite: 63/63 scripts passed. CSS compilation and Git whitespace checks passed.
- Browser: Services tables and both editors, invalid pricing feedback, direct Clinic navigation, existing case/patient records, Clinic billing service search, and Settings without duplicate pricing.
- Browser: existing Walk-in owner/pet selection, unchanged package cards, explicit included-service disabling/badges, optional Ala carte totals, and grooming estimates.
- Responsive layout checked at 320, 375, 414, 768, and desktop widths; tables scroll within their containers without document overflow.
- Customer Pre-registration requires a customer sign-in in this browser. Its real card renderers and customer submission path passed automated checks, including newly managed services and unchanged historical price snapshots.
- Inactive services remain available to staff billing for existing bookings, including size corrections, while new booking selectors remain active-only.
