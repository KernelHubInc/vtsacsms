# ADR 0025: Native staging OCPP runtime

- Status: Accepted by explicit operator request
- Date: 2026-10-10
- Owners: Platform Engineering, Charging

## Decision

The staging OCPP gateway may run as a native Python systemd service as an explicit
exception to the container-only deployment baseline. Docker remains a supported
rollback runtime. Both use the same gateway implementation and versioned core
contracts; neither writes platform database tables. This changes process hosting,
not OCPP semantics, charger eligibility, authentication policy, or data ownership.

Nginx terminates public WSS and forwards `/ocpp/{identity}` to one active upstream.
A separate private listener forwards core commands to that same upstream.
The platform and consumers keep their existing Redis streams and namespace.
The gateway's URL-only registration lookup retains its service bearer token and
HTTPS verification. TLS and internal API authentication are not weakened.

The native process runs as a dedicated unprivileged user in an isolated Python
3.13 virtual environment. Protected JSON settings avoid shell interpolation of
secrets. Redis remains on the current infrastructure; native deployment does not
mean removal of all Docker services.

## Switching and failure handling

The root-only switcher serializes operations with a lock, starts and checks the
candidate, validates and reloads Nginx, checks private routing and service-token
access, then stops the previous gateway. Existing sockets must reconnect. It
updates startup policies so the selected runtime starts after a reboot. Failed
cutover checks restore the previous upstream and runtime. An unavailable previous
runtime or failed Nginx rollback still requires operator intervention.

Do not distribute chargers randomly between these processes: live connections
and command dispatch are process-local. Both public and private traffic must
select the same runtime. A test instance must not use an ID connected elsewhere.

## Operational consequences

Native Python dependencies, release directories and systemd units now need explicit
updates and security scans. Keep a frozen dependency inventory per release and
retain the previous native release and Docker image. The private Docker bridge
address is deployment-specific and must be rechecked if the Compose network is
recreated. Nginx must start after Docker creates that interface.

See [native staging operations](../../runbooks/ocpp-native-staging.md).
