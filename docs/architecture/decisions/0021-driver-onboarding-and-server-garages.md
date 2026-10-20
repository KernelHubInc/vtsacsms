# ADR 0021: Adult driver onboarding and account garages

Status: Accepted for staging testing, 2026-10-08.

Owners: Identity, Assets, Locations, mobile client.

## Context and decision

The feature review requires separate names, a validated birth date, and a default vehicle at signup. The product owner confirmed a minimum age of 18 and allowed a pending plate. Device-only garages cannot carry that vehicle to another installation.

Identity owns nullable first/middle/last names and encrypted date of birth on existing users. New registration requires first/last names, a valid date from 1900-01-01 through the eighteenth-birthday cutoff on the UTC calendar date, and a plate or an explicit pending-plate choice. Existing accounts are not backfilled with guessed names or dates.

Assets owns `driver_garages` and `driver_vehicles`. Garages reference the Identity subject ULID without a cross-context foreign key. Registration calls the public `DriverVehicles` application contract inside its existing transaction, so account and initial garage commit together. Email sending remains outside that transaction. Vehicle access requires verified authentication, active tenant membership, and the same subject; tenant scope alone is insufficient.

The garage row lock serializes default selection. PostgreSQL additionally enforces one default per garage with a partial unique index. Repeated PUT uses the same client ULID; changing a vehicle cannot change its owner. A conflicting identifier in another tenant returns a safe 409. Deleting a default promotes the next saved vehicle. An empty garage has no default.

## API migration

New required signup fields are exposed through `POST /api/v2/auth/register`. The old v1 signup returns 426 `app_update_required`; it cannot bypass the adult rule. Existing v1 sign-in and authenticated APIs continue to work. Deploy the additive migration and backend before distributing APK 1.0.6. The mobile client does not fall back to the retired signup contract.

Existing local vehicles remain visible to the same account on the same device alongside the server garage. Editing one with a plate or pending-plate choice saves it to the server and removes its local copy. They are not silently uploaded with invented plates or compatibility. New plate-only vehicles have unknown compatibility until the driver supplies connector standards.

## Privacy and operations

Birth date and plate are encrypted using Laravel's application key. Preserve that key in deployment and backups. Neither value appears in garage audit metadata. Self-profile and garage responses use no-store. The new fields are retained while needed for the account/vehicle; removing a vehicle deletes its plate. Account erasure must clear the Identity fields and invoke the Assets garage-erasure contract in each membership tenant. Audit records retain only non-personal resource IDs and action outcomes. Backup expiry follows the existing deployment retention policy; this change does not shorten it or claim immediate backup erasure.

This release does not add a public account-erasure endpoint. Operators must use the approved account-erasure process; a disabled login alone does not erase profile data. KYC evidence retains its separate consent and erasure lifecycle.

## Consequences and limitations

- Server garage availability is required for edits; failures are shown rather than reported as saved locally.
- Mobile dates and country selectors guide entry; KYC verification still occurs on the server after submission. OCR autofill is not implemented because the current evidence workflow performs OCR after submission.
- Discovery exposes individual connector IDs and observed states, not deduplicated connector types. Compatibility, power, current, and availability filters must match one connector.
- Published operating hours use site-local time and support overnight intervals. Missing hours are unknown. Equal opening and closing times do not imply 24-hour access.
- Public search retains its existing bounded candidate scan; it is not an unbounded directory or queue-length service.
- PostgreSQL locking/partial-index behavior requires staging verification; local regression tests use SQLite.

Alternatives rejected: device-only defaults, guessed profile splitting, manufactured connector compatibility from a plate, and weakening old registration to bypass the age rule.
