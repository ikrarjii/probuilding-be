# ProBuild INTIM Registration API

Laravel 12 API for ProBuild INTIM participant registration and attendance data.

## Public endpoints

- `GET /api/v1/public/events/{event}/registration` — event and current talkshow availability.
- `POST /api/v1/public/events/{event}/registrations` — create a participant registration. Requires a UUID `Idempotency-Key` header.
- `GET /api/v1/public/e-tickets/{ticketToken}` — current web e-ticket projection and SVG QR Code.
- `GET /api/v1/public/e-tickets/{ticketToken}/pdf` — current printable PDF e-ticket.

The create response includes the registration number, individual talkshow outcomes (`confirmed`, `waitlisted`, or `unavailable`), and the canonical e-ticket URL. Its 256-bit opaque token is also the persistent QR identity. The response excludes all internal token hashes.

## Staff access

Staff login is available at `POST /api/v1/staff/auth/login`. Protected requests use the returned opaque bearer token. Only a SHA-256 hash of that token is stored, tokens expire, logout revokes the current token, and deactivating a user revokes all of that user's active tokens.

The first Super Admin must be created interactively after roles have been seeded:

```bash
php artisan staff:create-super-admin --name="Operations Admin" --email="admin@example.com"
```

The command prompts securely for the password and intentionally has no password command-line argument. Subsequent accounts and role changes are managed through the authenticated Super Admin API or `/staff` web workspace.

Panitia event access is always checked against an active `event_user_assignments` record. Vendor access is limited to aggregate statistics and never passes through participant serializers or queries.

## Domain model

- `participants` holds participant contact/profile data.
- `registrations` joins one participant to one event and stores the immutable registration number and canonical encrypted QR/e-ticket identity.
- `registration_talkshows` records every requested session and its resolution.
- `event_days` provides the four-day attendance boundary.
- `daily_event_checkins` permits one successful check-in per registration and event day.
- `talkshow_attendances` is independent of event check-in and stores any Super Admin prerequisite override.
- `roles`, `permissions`, and `event_user_assignments` provide global and event-scoped access boundaries.
- `audit_logs` records sensitive staff actions.
- `outbox_messages` and `ticket_deliveries` provide reliable, idempotent WhatsApp notification delivery.

Database uniqueness constraints enforce event-scoped WhatsApp registration, event/participant registration, registration number, QR/e-ticket hash, idempotency key, daily check-in, and talkshow attendance invariants.

## Configuration

Copy `.env.example` to `.env`, generate `APP_KEY`, and configure PostgreSQL. The safe default WhatsApp driver is a mock that writes to a local log and never contacts an external service. Production rejects the mock driver. WhatsApp remains disabled in production until a vendor adapter is selected.

Set `PUBLIC_WEB_URL` to the participant-facing website origin (`http://localhost:5173` locally and HTTPS in production). Phase 2 adds `endroid/qr-code` for vector QR output and `dompdf/dompdf` for PDF rendering. PHP DOM and SimpleXML are required; GD is not required because QR Codes are rendered as SVG.

## Commands

```bash
composer install
php artisan key:generate
php artisan migrate --seed
php artisan staff:create-super-admin --name="Operations Admin" --email="admin@example.com"
php artisan serve
php artisan notifications:process
php artisan test
vendor/bin/pint --test
```

## Production preflight

Do not copy the local `.env` to production. At minimum, the server environment must use:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://backend.probuildintim.com
PUBLIC_WEB_URL=https://www.probuildintim.com
CORS_ALLOWED_ORIGINS=https://probuildintim.com,https://www.probuildintim.com
LOG_LEVEL=warning
DB_CONNECTION=mysql
```

Configure a non-empty `APP_KEY` and the real database credentials separately. Never commit them. Before every production migration, take and verify a database backup, then run:

```bash
php artisan config:clear
php artisan app:production-check
php artisan migrate:status
php artisan migrate --force
php artisan db:seed --class=AccessControlSeeder --force
php artisan optimize
```

Do not run the complete `DatabaseSeeder` against an already configured production event because the event fixture seeder updates event and talkshow setup values. If migration `2026_08_16_000700_make_phase_three_whatsapp_only` is still pending, note that it intentionally removes legacy email delivery/outbox rows as part of the WhatsApp-only transition; the backup is mandatory before applying it.

The seed creates the event, four event-day records, ten published talkshows, roles, and permissions. It does not create default staff credentials. Talkshow capacity and waitlist settings must be reviewed with the organizer before production registration opens.

Production must execute `php artisan schedule:run` every minute. See [`../docs/PHASE-3.md`](../docs/PHASE-3.md) for provider variables, safe development tests, retry behavior, and production requirements.
