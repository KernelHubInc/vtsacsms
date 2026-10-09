# ADR 0024: URL-only connections for registered chargers

- Status: Accepted by explicit operator request; supersedes ADR 0023's mandatory device credentials for URL-only deployments
- Date: 2026-10-10
- Owners: Identity, Assets, Platform Engineering

## Context and decision

The operator's manufacturer specifies Authorisation = 0, an OCPP URL and a charge-point ID. The operator explicitly requests connections without device authentication. URL-only deployment uses `OCPP_CORE_REGISTRATION_URL` pointing to the new service-protected `/api/internal/v1/ocpp/resolve` endpoint. It is mutually exclusive with `OCPP_CORE_AUTH_URL`; existing Basic deployments do not silently downgrade during an image update.

For every new WebSocket connection, the gateway resolves the exact URL identity and negotiated protocol against current Assets and Tenancy state. Unknown, inactive, wrong-protocol or suspended-tenant stations fail closed. Successful responses carry `authentication: registered`, which explicitly describes registration rather than authentication. Device Authorization headers and client credentials are unused in this mode. The gateway never writes core tables or accepts tenant/station bindings from the device.

Admin station creation/editing no longer requests a password. Active stations with a configured OCPP version are immediately eligible, including existing and API-created stations. Draft imports still require activation and protocol selection. No credential record, import, database migration or re-save is needed for URL-only eligibility. Legacy credential storage and Basic endpoints remain for compatibility and rollback; their validation is unchanged.

## Trust boundary and risk

This is an explicit exception to mandatory charger authentication: knowing an active charge-point ID is sufficient to impersonate it, including submitting charger telemetry. Registered IDs are not secrets. Use a private network/VPN or suitable edge source-IP restrictions where available. No network allowlist is invented or automatically installed by this change.

WSS and server certificate validation remain enabled. Backend service bearer authentication, verified HTTPS, protocol checks, tenant-derived bindings, rate limits, connection leases and charging authorization remain enforced. Core failure or malformed responses do not fall back to static or development enrollment. No client certificate is required in this mode. Existing connections retain their binding until disconnected; changing lifecycle state applies at the next handshake.

## Rollout and verification

Deploy both images, then use `activate-url-only` and `gateway-up` with existing staging overrides. Activation checks that the new platform endpoint exists, backs up private configuration, retains the static registry, clears the Basic URL and sets the registration URL. Reverting that protected environment backup and recreating the gateway restores the prior policy.

Tests cover creation without credentials, lifecycle/tenant/protocol rejection, service-token enforcement, no credential forwarding, upstream failure, incompatible configuration and a real local WebSocket BootNotification/Heartbeat without a password. This does not verify the physical device or live staging deployment.
