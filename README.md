# Bethlehem Animal Clinic & Grooming System

## Email verification setup

Registration verification is a one-time email ownership check. It does not send
an OTP on every sign-in. After verification, the customer signs in with their
password normally.

For local development, `MAIL_MAILER=log` writes the verification message and
link to `storage/logs/laravel.log`; it does not deliver to an inbox. To test real
delivery, configure a mail provider (or a local SMTP catcher) in `.env`, set
`FRONTEND_URL` to the browser-facing application root, and use a valid sender.

Queued verification mail also requires a running worker:

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
