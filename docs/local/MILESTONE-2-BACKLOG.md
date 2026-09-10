# Milestone 2 backlog

This backlog preserves deferred scope without enabling or simulating it by default.

- Validate real charger identities and certificates, operate the separate OCPP 1.6J/2.0.1 gateway, perform concurrency/reconnect trials, and integrate real physical chargers.
- Enable remote start/stop only after end-to-end command correlation, authorization, transaction-event confirmation, expiry, recovery, observability and safety UAT.
- Integrate approved sandbox then production payment providers through the existing abstraction; complete PCI scope review, webhook/reconciliation/capture/refund failure testing and secret management.
- Complete real operator/site-host settlement approvals, bank-file/provider contracts, reconciliation and finance controls.
- Implement jurisdiction-approved electronic invoicing/tax configuration only after registration and legal decisions.
- Implement versioned OCPI roaming contracts, credentials, party/endpoint lifecycle, tariff/location/session/CDR exchange, retries and certification.
- Complete production push, SLOs, backup/restore, disaster recovery, security assessment, retention/privacy controls, capacity tests and deployment architecture.

Each item requires a dedicated flag, rollout/rollback plan, audit coverage, tenant leakage tests, idempotency tests, operator runbook, and explicit acceptance evidence.
