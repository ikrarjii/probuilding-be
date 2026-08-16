# ProBuild INTIM Registration API

Laravel 12 API for ProBuild INTIM participant registration and attendance data.

## Public endpoints

- `GET /api/v1/public/events/{event}/registration` — event and current talkshow availability.
- `POST /api/v1/public/events/{event}/registrations` — create a participant registration. Requires a UUID `Idempotency-Key` header.
- `GET /api/v1/public/e-tickets/{ticketToken}` — current web e-ticket projection and SVG QR Code.
- `GET /api/v1/public/e-tickets/{ticketToken}/pdf` — current printable PDF e-ticket.

The create response includes the registration number, individual talkshow outcomes (`confirmed`, `waitlisted`, or `unavailable`), and the canonical e-ticket URL. Its 256-bit opaque token is also the persistent QR identity. The response excludes all internal token hashes.

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
php artisan serve
php artisan notifications:process
php artisan test
vendor/bin/pint --test
```

The seed creates the event, four event-day records, ten published talkshows, roles, and permissions. It does not create default staff credentials. Talkshow capacity and waitlist settings must be reviewed with the organizer before production registration opens.

Production must execute `php artisan schedule:run` every minute. See [`../docs/PHASE-3.md`](../docs/PHASE-3.md) for provider variables, safe development tests, retry behavior, and production requirements.
