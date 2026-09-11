# ADR 0016: Use WireGuard for application-to-data transport

- Status: Accepted
- Date: 2026-09-11
- Owners: Platform Engineering

## Context

The initial Hostinger deployment places PostgreSQL/PostGIS, Redis, and MinIO on a dedicated data server while application workloads run on two separate VPS nodes. Those services must not be publicly reachable, and firewall source restrictions alone do not encrypt traffic. The provider-private-network encryption properties have not been confirmed.

## Decision

Create a narrow WireGuard overlay between the data server and both application servers. The data server is the fixed endpoint on UDP port `51820`; both app servers initiate their tunnels. Only the data server's tunnel address is routed from the application peers, so default Internet traffic and load-balancer traffic remain unchanged.

Use the proposed `10.77.0.0/24` overlay only after confirming it does not conflict with an existing host, Docker, or provider route. Assign the data server `10.77.0.1`, App Server 1 `10.77.0.2`, and App Server 2 `10.77.0.3`. If the preflight detects a conflict, select another unused RFC 1918 subnet and update all peers and deployment configuration consistently.

Bind PostgreSQL, both Redis instances, and both MinIO APIs only to the data server's WireGuard address. Limit each peer's `AllowedIPs` to the other endpoint addresses required for the data path. Store each private key only on the host that generated it, with root-only permissions. Exchange only public keys. No forwarding or NAT is enabled because the data server is the traffic destination.

## Consequences

Application-to-data traffic is encrypted independently of the hosting provider's private-network guarantees. Database, cache, and storage endpoints can use their internal protocols within the tunnel, while public HTTP and OCPP routing remains unchanged.

The WireGuard interface becomes a prerequisite for starting the data stack because Docker services bind to its address. Operations must monitor handshakes, preserve UDP `51820` access from both app-server public addresses, rotate keys deliberately, and update the peer configuration when a server is replaced or its public address changes.

WireGuard secures traffic in transit but does not replace service authentication, least-privilege credentials, host firewalling, encrypted backups, or encryption at rest.

## Alternatives considered

- Rely only on public-IP firewall rules: rejected because it restricts sources but does not encrypt traffic.
- Assume the provider private network is encrypted: rejected until that property is explicitly documented and verified.
- Configure independent native TLS for PostgreSQL, Redis, and MinIO: secure but requires separate certificate lifecycle and client trust configuration for every service; it remains a viable defense-in-depth enhancement.
- Route all server traffic through WireGuard: rejected because it would unnecessarily change public application, deployment, and load-balancer routes.

## Risks and controls

- Subnet collision: run route and Docker-network checks on all peers before assigning the overlay.
- Key disclosure: generate keys on each host, never print private keys, and restrict key/config files to mode `0600`.
- Accidental public exposure: bind data services only to the WireGuard address and retain Hostinger firewall restrictions.
- Tunnel outage: require a recent handshake and data-port connectivity checks before application deployment.
- Peer replacement: remove obsolete public keys and firewall sources as part of server decommissioning.

## Operational reference

See `docs/runbooks/wireguard-data-network.md` for installation, key exchange, peer configuration, validation, and troubleshooting.
