# Grooming Hosted Checkout

Only Grooming uses PayMongo. Clinic and POS retain their existing payment flows.

## Enable

1. Run `php artisan migrate` to add `gateway_payments` and the optional provider fields on Grooming payments.
2. Configure `PAYMONGO_SECRET_KEY`, `PAYMONGO_WEBHOOK_SECRET`, and `PAYMONGO_LIVE_MODE` on the backend. Keep secrets out of frontend settings. The key and webhook must use the same test/live environment.
3. Set `PAYMONGO_RETURN_URL` to the deployed Grooming appointments page. Returning to this page never settles a payment.
4. Register the public HTTPS endpoint `POST /api/paymongo/webhook` for `checkout_session.payment.paid` in PayMongo. Use that endpoint's signing secret.
5. Set `PAYMONGO_PAYMENT_METHODS` to the methods enabled for the account (default: `qrph,gcash,paymaya,card`). Clear/rebuild Laravel's configuration cache after updating environment settings.

Creation uses PayMongo Checkout API v2. Retrieval and expiration use PayMongo's v1 Checkout Session endpoints. Customer fees are not added to the Bethlehem total.

## Staff endpoints

These require the existing authenticated Grooming/admin permissions:

- `POST /api/admin/bookings/{id}/online-payment`: accepts `flow` (`early` or `final`), confirmed `service_prices`, `pet_sizes`, `products`, and optional `notes`. Client totals and tendered money are ignored. A pending attempt is reused without changing its snapshot.
- `GET /api/admin/bookings/{id}/online-payment`: local status and recorded receipt details. Browser polling never calls PayMongo.
- `DELETE /api/admin/bookings/{id}/online-payment`: confirms provider expiration before unlocking payment. A processing/paid provider session stays locked until its signed paid webhook arrives.

## Reconciliation

`gateway_payments.reconciliation_reason` identifies exceptions:

- `product_stock_conflict`: the gateway charge and Grooming payment remain paid. Product rows and stock movements were rolled back together; stock is never made negative. The modal and transaction details flag staff reconciliation. Resolve product availability/fulfillment against the frozen `snapshot.products`; do not collect payment again.
- `checkout_creation_unconfirmed`: a create request was interrupted or had an ambiguous provider response. The attempt remains locked to prevent a second chargeable session. An administrator must locate the session using the saved Bethlehem reference/metadata, recover its ID, and establish payment or expiration with PayMongo before clearing the condition. Do not simply delete the attempt or mark it failed.
- `booking_already_paid`: a valid charge arrived for a visit already recorded as paid. The gateway charge is retained without a second Grooming payment, stock deduction, or notification. Investigate the duplicate collection with PayMongo.

Only signed paid webhooks settle online payments. Signatures use the raw body, `te` in test mode or `li` in live mode, with a five-minute delivery timestamp tolerance. Checkout/payment/event identifiers and booking row locks protect against duplicate delivery.

Focused checks: `php artisan test --filter=GroomingPayMongoTest` and `node tests/grooming-paymongo-regression.cjs`. These fake provider responses and do not charge customers.
