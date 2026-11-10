# CSMS security audit - 10 November 2026

Assessment: source, dependency and limited deployment review. This is not a penetration test or a certification. Source baseline: `test_env` at `d5fc3ce28d244f5513816188305c2a29605cfe1f`; production baseline: `63ef65456a53c42f188ce388e2871be7b9415d25`. Dates use Asia/Taipei; persisted event timestamps remain UTC.

## Scope and evidence

Reviewed Laravel/Livewire configuration and identity gates, Google identity, tenant boundaries, payment entry points, OCPP registration policy, gateway/KYC dependencies, deployment scripts, CI and current runbooks. Queried package advisory services and public readiness/release manifests. Used the existing authorized load-balancer console to check app-node release markers, roles and selected non-secret settings. No customer records, payment operations, physical charger commands, exploitation or secret values were collected.

The earlier 8 November infrastructure report had no live configuration evidence. Its unverified CVE claims are not treated as confirmed vulnerabilities here. Full OS inventories, firewall enforcement, data-server configuration, disk encryption, restore tests, complete Git history secret scanning, container vulnerability scanning and independent tenant penetration testing remain outside verified coverage.

## AUD-001 - Panel MFA requirement bypass

Severity: High. Status: fixed in this change; deployment must be verified separately. Owner: Identity.

`PanelAccessService::tenantIdFor` checked account state, memberships and permissions but did not reject `mfa_required`. Filament login and persistent panel middleware reused this eligibility query. An otherwise eligible account requiring MFA could enter the admin/operator panel using password authentication, although mobile token issuance correctly refused it. An enrollment timestamp is not evidence of a successful browser challenge.

Fix: panel eligibility now denies MFA-required users until a verified browser challenge flow exists. Existing panel sessions are subject to the same check. Regression: `PanelTenantContextTest::test_panels_deny_mfa_required_users_without_a_verified_challenge` failed before the change and passed afterward, including both panel HTTP boundaries. This does not implement production MFA or automatically require MFA for all privileged roles. Before rollout, review aggregate affected-user counts and retain an approved recovery path; never clear an MFA requirement merely to restore access.

## AUD-002 - Vulnerable contract build dependencies

Severity: High, as reported by npm; exposure is build/CI tooling, not demonstrated production HTTP exploitability. Status: patched in the lockfile. Owner: Platform Engineering.

The initial contract npm audit reported four high-severity package records, including the parent Redocly record. These are not four distinct CVEs. Updated `@redocly/openapi-core` 1.34.17 to 1.34.24, `js-yaml` 4.2.0 to 4.3.2, `brace-expansion` 2.1.2 to 2.1.7 and `fast-uri` 3.1.4 to 3.1.9. Compatible updates resolved YAML/brace denial-of-service and URI normalization advisories. Post-fix audit returned zero findings; contract lint and regeneration passed without generated-contract changes.

References: https://github.com/advisories/GHSA-52cp-r559-cp3m ; https://github.com/advisories/GHSA-mh99-v99m-4gvg ; https://github.com/advisories/GHSA-w74x-v6f9-j3xg . The complete raw scan contains additional related advisory IDs.

## AUD-003 - Missing npm security gates

Severity: Medium. Status: fixed in CI. Owner: Platform Engineering.

CI audited Composer and Python dependencies but omitted npm auditing for the platform and contracts. Added `npm audit --audit-level=high` immediately after their clean installs. High/critical findings now fail these jobs and therefore prevent release image publishing. Keep moderate findings under review; a passing audit is limited to the advisory database and dependency graph at scan time. Browser-test npm audit was also checked during this assessment and returned zero findings.

## AUD-004 - URL-only charger impersonation risk

Severity: High when reachable by an untrusted source. Status: existing accepted design exception; deployment exposure not fully established. Owners: Assets, Identity and Infrastructure.

ADR 0024 explicitly allows an active registered charge-point ID to establish a connection without a device password or client certificate. IDs are not secrets. A party knowing one can impersonate its telemetry; TLS protects transport but does not establish device identity in this mode. Registration, tenant/protocol checks and service bearer tokens do not remove this weakness.

Fix path: use per-device authenticated enrollment or manufacturer-supported mutual TLS. Where firmware requires URL-only operation, place it behind a verified VPN or narrow network/source restrictions, document the residual exception and test rejection outside that path. Do not silently reverse ADR 0024 or invent manufacturer capabilities. Keep production charging disabled until a supervised physical acceptance test and the exposure decision are recorded.

## AUD-005 - All proxies trusted

Severity: Medium, conditional on network reachability and edge header handling. Status: open. Owner: Infrastructure and Identity.

`bootstrap/app.php` trusts `*`; the shared Nginx include appends `X-Forwarded-For`. If an untrusted requester can reach an app node, forwarded-header spoofing can affect apparent source IP, rate limits, audit attribution or URL generation. Internet exploitability was not demonstrated.

Fix path: inventory the actual trusted load-balancer/container hops, overwrite untrusted forwarded headers at the public edge and configure Laravel with the verified proxy addresses/CIDRs. Preserve required App02 double-proxy behavior. Test hostile forwarded headers, secure URL generation and distributed rate limits through both nodes. Verify Docker-aware firewall restrictions as well as UFW; never invent trusted CIDRs.

## AUD-006 - Unpinned gateway runtime graph

Severity: Medium, supply-chain reproducibility. Status: open. Owner: Integrations.

The gateway Dockerfile installs version ranges from `pyproject.toml` without a runtime lock or hashes. Rebuilding the same source at a different time can produce different dependencies. Add a reviewed Python 3.13-compatible runtime lock, audit it in CI, install it consistently, and scan the built image/SBOM. Existing SHA tags help identify artifacts but do not make a fresh build deterministic. Image signatures/digests and OS package exposure require separate verification.

## AUD-007 - Vulnerable local Python tooling

Severity: advisory-specific; local development exposure only. Status: local environment patched, no claim about production. Owner: Developer Tooling.

The installed gateway virtual environment contained certifi 2026.6.17, filelock 3.32.0 and msgpack 1.2.1. The scanner returned 15 records, including one duplicated certifi identifier: 14 unique advisory IDs across three packages. Patched locally to certifi 2026.10.23, filelock 4.2.10 and msgpack 1.2.4; rescan found no known vulnerabilities. These versions were not established as production image contents. KYC's pinned runtime requirements scan returned zero findings.

## AUD-008 - Operational assurance gaps

Severity: review priority, not a confirmed exploit. Status: open. Owners: Operations and Security.

Production MFA/step-up, external audit anchoring, backup recovery evidence, retention/erasure, image/OS scans and actual network isolation need owner evidence. Hostinger's edge overview showed zero provider firewall rules and a weekly backup schedule; this does not establish absent host-level filtering or absent independent backups. Record effective host and Docker firewall rules, encrypted off-host backups and a timed isolated restore. Set measured RPO/RTO and named owners. Public Nginx version headers alone cannot prove CVE exposure because vendor backports and build provenance matter.

## Deployment observations and decision gates

At the initial check, both production app nodes reported `sha-63ef65456a53c42f188ce388e2871be7b9415d25`. App01 was primary; App02 was web-only. Both configured HTTPS, secure session cookies, SMTP, remote charging disabled and real payments disabled. These were selected environment-file observations, not complete effective-runtime proof. Public staging and production readiness both returned database/Redis OK. The staging mobile manifest identified source `d5fc3ce2` and older backend `fb94eca3`; a full backend staging rollout remains necessary. A staging preflight passed through the load-balancer console.

Automatic prepaid stopping and native Google login still require the physical acceptance described in ADRs 0029/0030. Deploying inactive code is separate from authorizing either feature. Preserve all existing production business flags; never enable Google with staging clients or claim a physical cutoff guarantee.

## Verification record

The PDF release record records final run counts, commit IDs, deployment results and outstanding work. Local raw evidence is under `tmp/csms-audit-20261110/`. Baseline CI run 42654159815 passed for `d5fc3ce2`; new fixes require a fresh CI run and image publish. Test skips and environment-only failures must be disclosed, not counted as passes. The initial Windows PHP run failed RSA fixture generation because OPENSSL_CONF was absent; rerun with the installed configuration, without weakening tests.

## Priority and closure

P0 before activating live charging: close the device-authentication/network decision and physical acceptance; retain stop/monitor behavior during failures. P1 for this code release: deploy AUD-001/002/003 and verify both nodes, staging parity, migration backup and client bundle provenance. P1 security hardening: implement actual privileged MFA and verify proxy boundaries. P2: lock gateway dependencies, enforce image/OS scans, verify backup restore/retention and external audit anchoring. Owners and dates are proposed responsibilities, not assigned commitments. A partial review cannot certify that the system has no other vulnerabilities.
