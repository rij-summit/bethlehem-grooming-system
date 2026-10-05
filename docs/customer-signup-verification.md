# Customer signup verification

New registrations issue a six-digit Email code. `/api/email/verify` accepts
`{ email, code }`; `/api/email/resend` rotates the code. Codes are hashed,
expire after 15 minutes, and allow five invalid attempts. The pending row is
locked during verification, then consumed when the customer account and first
authenticated session are created. `phone_verified_at` remains null.

Apply `2026_10_05_000001_add_registration_verification_codes.php` before serving
the updated signup pages or running notification workers against this release.
The migration preserves existing account data and pending-registration cleanup.
Queue workers should be restarted with the release so they load the updated
verification notification. The notification includes HTML and plain text mail
and uses the existing encrypted, retryable queue delivery.

The legacy token columns and `{ token }` verification request remain solely to
consume links issued before this release, including notifications already queued
before deployment. Neither signup nor resend issues new link tokens. A successful
resend replaces the legacy token with an Email code. These columns and the legacy
verification branch can be removed after the existing links have expired and old
queued notifications have drained. Email delivery failure preserves the pending
registration and restores the previous verification state only if no newer
issuance has replaced it.

Signup returns a private `registration_token`, separate from the Email code.
Only its SHA-256 hash is persisted. `PUT /api/register/pending` requires that
credential, revalidates all signup fields, rotates the credential, and invalidates
the old code. It never creates an account. The browser retains signup details
and this edit credential in tab-scoped session storage to support correction;
passwords and OTPs are never stored there.

`scripts/auth/verification-code.js` and `css/components/verification-code.css` contain the
existing Forgot Password code-entry behavior shared by signup. Signup channel
actions live in `scripts/auth/client/signup-verification.js`. Connect real SMS
send and verify endpoints at its `actions.sms` boundary later. Current SMS
actions only exercise local interaction states, never call a backend, never
validate a code, and never establish a session. Keep the authenticated completion
guard restricted to Email until real server-side phone verification exists.

The nullable `users.phone_verified_at` column prepares the account data model.
Phone authentication/recovery eligibility is unchanged: an unverified phone is
never treated as proof of ownership. Enabling the future Email-or-phone rule
requires the actual SMS security workflow and a review of authorization scopes.

Run the frontend signup regressions with
`node tests/customer-signup-verification-regression.cjs`. Backend signup security,
already-issued links, and resend regressions live in `EmailVerificationTest`;
Forgot Password regressions remain in `PasswordResetTest` and
`PasswordResetInterfaceTest`.
