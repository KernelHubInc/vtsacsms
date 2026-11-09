# ADR-0031: First-name hint on the secure welcome screen

- **Status:** Accepted by explicit product-owner request
- **Date:** 2026-11-09
- **Owner:** Identity / mobile
- **Requirement:** MOB-AUTH-003

## Decision

The enrolled native quick-unlock record may retain the authenticated driver's `first_name` as a display hint for the locked welcome screen. This is the only profile field displayed before local proof. It is personal information visible to anyone looking at that screen; PIN setup explains this behavior. Do not infer it from an email, full name or provider fallback name. Missing, blank or legacy records show “Your saved account.”

Store the hint with the existing platform-secure quick-unlock settings, bound to the same saved session, tenant and device. Remove control/direction-override characters and limit it to 80 Unicode scalar values. Capture it during enrollment and refresh it only after successful local proof and an authoritative Identity response. Clearing credentials on sign-out, account switching, expiry/revocation or lockout removes the hint too. No preferences, server endpoint, analytics or log field is added.

This extends ADR-0020's pre-unlock presentation without changing its credential gate. Tokens, the full profile, wallet, vehicles and charging projections remain inaccessible until local proof and the existing online Identity validation succeed. A name is never evidence of identity or permission.

## Verification

Test older settings, blank/malformed hints, restart/lock persistence, clearing credentials, PIN attempt limits, biometric cancellation and server/tenant/device rejection. Physical-device verification remains required for native biometric prompts.
