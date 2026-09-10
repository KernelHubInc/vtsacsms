# ADR 0015: Promote isolated staging and production stacks across two app nodes

- Status: Accepted
- Date: 2026-09-10
- Owners: Platform Engineering

## Context

The initial Hostinger topology provides one Nginx load balancer, two application servers, and one data server. VTSA CSMS needs a staging release gate without losing the two-node rolling availability of production. Copying mutable source or replacing both nodes together would create drift and downtime. Sharing databases, Redis keys, object buckets, cookies, or application secrets would allow staging activity to affect production.

## Decision

Run separate Docker Compose projects for staging and production on both application servers. Give each environment distinct host ports, PostgreSQL credentials/database, Redis service and namespaces, object-storage credentials/bucket, application key, gateway token, session cookie, deployment state, and backups. Run one scheduler per environment on App Server 1.

A successful `main` CI run builds commit-addressed platform, web, and OCPP images once. GitHub Actions deploys that immutable commit to staging first. The production environment requires approval and receives the identical commit only after staging readiness succeeds.

For each environment, Nginx drains one node's HTTP and OCPP upstreams, verifies the peer, deploys and migrates App Server 1, verifies readiness, returns it to service, and then repeats without migrations on App Server 2. Server repository keys are generated locally and read-only. The Actions SSH private key is stored as one-line Base64 and validated before draining.

## Consequences

Staging and production can be operated independently with the available four-server topology, and production releases are reproducible promotions rather than rebuilds. Zero-downtime releases still require backward-compatible migrations and sufficient capacity for one node to carry an environment while its peer is drained.

The environments share physical CPU, memory, disk, and network on the two app nodes, so staging load can still affect production. Resource limits and monitoring must be established before high-volume staging tests. Moving staging or production to dedicated nodes later does not change image or environment contracts.

## Alternatives considered

- Dedicated staging app servers provide stronger failure isolation but require additional hosts.
- Deploying production directly from CI removes the validation gate and was rejected.
- Building separate production images risks artifact drift and was rejected.
- Kubernetes would provide richer scheduling but adds unjustified operational complexity for the current topology.

## Risks and controls

- Capacity contention: monitor both Compose projects and restrict staging load.
- Cross-environment data access: distinct credentials, databases, Redis services, buckets, prefixes, and cookies.
- Deployment interruption: global per-node locks and per-environment load-balancer state.
- Key corruption: generate repository keys on-server; Base64-decode and validate the Actions key before connecting.
- Migration incompatibility: additive rolling migrations only, pre-migration backups, and production approval.

## Follow-up decisions

- Production and staging DNS names and TLS certificate ownership.
- Container CPU/memory budgets and observability thresholds.
- Secret-manager product and automated credential rotation.
- Criteria for moving staging to dedicated infrastructure.
