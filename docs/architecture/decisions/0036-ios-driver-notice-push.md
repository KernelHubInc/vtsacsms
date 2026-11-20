# ADR 0036: Apple delivery for driver notice push

Status: Accepted

Date: 2026-11-20

Owners: Notifications and mobile application; Identity and CMS contracts retained.

## Context

Native push initialization was restricted to staging Android. The notification registry and sender supported Android and browser installations only. iOS requires APNs registration, an Apple-capable provisioning profile and platform-specific delivery options. The existing Firebase Messaging 17 installation-ID contract remains valid.

## Decision

Extend the existing authenticated, verified, tenant-bound mobile device API with `platform=ios`. No schema migration is required: the existing platform column is a bounded string, with no database enum. Notifications retains ownership of encrypted installations and durable delivery attempts; CMS remains the source of published driver notices. Identity still checks live authentication and membership eligibility before sending. Do not introduce a second registration registry or bypass those contracts.

The client accepts only the Firebase project selected for its build environment. Android and iOS production use `evcs-power-solutions`; staging uses its separate project. Browser support remains restricted to the existing staging host. iOS waits a bounded ten seconds for APNs before FCM registration and retries on a later authenticated resume. Authorized and provisional permission are supported. Foreground Apple alerts use the operating system presentation API, with sound and no invented unread badge. Background alerts use the OS; no background handler changes charging or financial state. Tapping opens Updates through the existing authenticated navigation flow. Logout cancels pending client registration and revokes server registration through the existing token lifecycle.

FCM sends an explicit Apple alert payload with bundle topic, default sound and expiration bounded by the notice TTL. It never sends Android-only package/channel options to iOS. APNs authentication rejection is recorded as a safe configuration failure, without deleting a valid device registration or logging provider details. Existing deduplication, tenant isolation, retry bounds, expired-auth cancellation and unknown-outcome semantics remain in force.

Client Firebase plist files are ignored and injected privately during a signed build. A preparation check enforces Firebase project and bundle identity; an archive check requires production APNs entitlement for TestFlight/App Store. The APNs private key remains in Firebase, and its server service-account credential remains on the server. Build settings and acceptance steps are documented in the [Codemagic runbook](../../runbooks/codemagic-production-ios.md).

## Consequences

Deploy the backwards-compatible server before distributing the iOS build. Preserve the existing Firebase credential mount and enabled flag in each environment. Configuration checks use Firebase validation-only requests; they cannot prove APNs key acceptance or physical iPhone display. Real-device TestFlight acceptance remains required. This release covers the existing published driver-notice push flow; it does not create a new charging-event push trigger or a recorded voice announcement.

An independent Apple registry or a second notification library was rejected: both would duplicate permission, ownership and delivery lifecycle rules. Follow-up: record the resolved Xcode version, signed archive check and physical iPhone foreground/background/tap/logout results before calling the store release verified.
