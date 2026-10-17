# Bethlehem Animal Clinic & Grooming System

## Email verification setup

Registration verification is a one-time email ownership check. Entering a
valid signup verification code creates the customer's first signed-in
session. Later customer, admin, and staff sign-ins confirm the password first, then send a
separate six-digit email code that must be entered in the original browser tab.
The code is bound to that tab's private login challenge, and a successful entry
creates the session and continues to the appropriate dashboard.

For local development, use Brevo SMTP or a local SMTP catcher in `.env`, set
`FRONTEND_URL` to the browser-facing application root, and use a valid sender.

Queued signup-verification and login-confirmation mail require a running worker:

```powershell
php artisan queue:work
```

The provided `composer run dev` command starts the queue listener together with
the web server. Production must not use the `log` mailer or an `example.com`
sender; registration rejects that unsafe configuration instead of claiming an
email was delivered. Registration codes cannot use a log mailer even locally.

See [Hostinger email authentication setup](docs/hostinger-email-auth.md) for the
Brevo environment settings, every-minute worker cron, deployment commands, and
live verification checklist. Queued messages are shown as pending until the
worker's mail transport accepts them; worker delivery failures use the existing
verification error messages. Delivery receipts are private to the signup tab or
password-confirmed login and never contain an OTP or SMTP credentials.

## Privileged seed accounts

Admin and staff seeders do not contain default passwords. Set the
`ADMIN_SEED_*` and `STAFF_SEED_*` values shown in `.env.example` before running
`php artisan db:seed`; passwords must contain at least 12 characters. Changing
the seeders does not rotate credentials already stored in an existing database.

Rotate an existing privileged account with an interactive, hidden password
prompt (the command also revokes that account's current sessions):

```powershell
php artisan account:rotate-privileged-password admin@example.com
```
