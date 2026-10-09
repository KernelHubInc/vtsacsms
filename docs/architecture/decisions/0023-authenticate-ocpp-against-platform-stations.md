# ADR 0023: Authenticate OCPP connections against platform stations

- Status: Accepted
- Date: 2026-10-09
- Owners: Identity, Assets, Platform Engineering

## Context and decision

The static gateway registry required manual enrollment and restart per station. Dynamic mode queries `POST /api/internal/v1/ocpp/authenticate` on each connection, returning only a verified tenant ULID, station ULID and exact identity. Assets supplies the globally unique identity query (ADR 0010), Tenancy supplies active-tenant eligibility, and Identity owns hashed machine credentials in `identity_charger_credentials`. The gateway never accesses core database tables.

Admin creation sets the OCPP version and a generated-by-default, editable password which the operator copies to the device. Station and credential writes commit together. Blank password edits preserve the credential; supplying a password rotates it. The station API accepts optional write-only `ocpp_password`. CSV imports remain draft assets and need commissioning with version and password before connecting. Credentials and hashes never enter events, asset snapshots or API responses.

Eligibility derives from current records at handshake time. Unknown/draft/retired stations, suspended tenants, incorrect protocol and credentials fail closed. Possessing a charge-point ID alone never grants access. Existing immutable identity rules remain unchanged.

## Trust boundary

The core endpoint requires the existing gateway/core service bearer token. Deployed authentication uses HTTPS with certificate verification, a three-second socket timeout, bounded response size and rate limiting. Redirects and environment proxies are disabled. Failures never fall back to the old registry. Empty `OCPP_CORE_AUTH_URL` retains explicit static mode for staged rollout/rollback. Dynamic mode initially supports Basic authentication; certificate-only legacy entries require a separate migration and are rejected by the importer.

## Rollout and consequences

Apply the additive migration and deploy both images before running `activate-enrollment` and `gateway-up`, preserving all deployment overrides. Activation imports hashes through stdin, validates asset bindings, audits without secrets, never replaces an existing credential, and backs up the private environment before setting the HTTPS URL. One restart activates the mode; later station enrollment needs none.

Core authentication becomes a dependency for new connections. Existing sockets are not reauthenticated: rotation and lifecycle restrictions affect the next handshake; emergency revocation requires disconnecting the existing socket. Charging authorization and event processing retain their independent lifecycle checks. Accepted sockets do not establish connector availability or authorize charging.

An authoritative query avoids propagating password hashes in events and stale replicated enrollment. Public unauthenticated enrollment is rejected. A successful enrolled BootNotification/Heartbeat test is required after deployment; an unknown-device 403 alone is insufficient.

## Validation

`ChargerEnrollmentTest`, gateway dynamic/security/protocol tests, and staging helper tests cover admin provisioning, rotation, blank edits, lifecycle/protocol checks, tenant isolation, secret-free audit, upstream failure and idempotent import.
