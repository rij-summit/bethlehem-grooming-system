# Bethlehem Animal Clinic & Grooming System

## Email verification setup

Registration verification is a one-time email ownership check. Following a
successful signup verification link creates the customer's first signed-in
session. Later customer sign-ins confirm the password first, then send a
separate six-digit email code that must be entered in the original browser tab.
The code is bound to that tab's private login challenge, and a successful entry
creates the session and continues to the dashboard. Admin and staff sign-ins
continue to use their existing password flow.

For local development, `MAIL_MAILER=log` writes the verification link or login
code to `storage/logs/laravel.log`; it does not deliver to an inbox. To test real
delivery, configure a mail provider (or a local SMTP catcher) in `.env`, set
`FRONTEND_URL` to the browser-facing application root, and use a valid sender.

Queued signup-verification and login-confirmation mail require a running worker:

```powershell
php artisan queue:work
```

The provided `composer run dev` command starts the queue listener together with
the web server. Production must not use the `log` mailer or an `example.com`
sender; registration rejects that unsafe configuration instead of claiming an
email was delivered.

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
