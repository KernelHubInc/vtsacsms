# ADR-0033: Temporary passwords for administrator enrollment

Accepted on 2026-11-11. Extends [ADR-0032](0032-initial-administrator-enrollment.md) and replaces its email-only enrollment requirement. FR-IDN-006 applies.

An authorized platform operator can issue a random 128-bit temporary password for an existing pending enrollment in an active tenant. The trusted command shows it once in a private interactive terminal. The operator delivers it directly to the intended account owner. Bootstrap can prepare identities for this method without dispatching email. Issuance records the subject and approval reference in the audit ledger and stores a SHA-256 verifier in the existing enrollment record. Reissue replaces the previous verifier and starts a new 24-hour validity period.

The public setup page requires the bound email and temporary password. Under row locks it validates the credential and current authority, sets a different personal password, activates the account and consumes the verifier. Email ownership is recorded only when the recipient completes the existing email-link flow. Both paths preserve MFA requirements and leave sign-in to the usual panel entry point. This reuses the existing schema and permits a rolling deployment. Issue credentials after all nodes serve the new setup page.
