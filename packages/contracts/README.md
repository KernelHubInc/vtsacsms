# VTSA Contracts

Versioned OpenAPI and integration-event schemas shared across VTSA containers. Source schemas are authoritative; `generated/` artifacts are committed so consumers do not need the generator toolchain. `openapi/ocpp-gateway.internal.v1.yaml` defines the service command/health interface, while `events/gateway-ocpp-normalized.v1.schema.json` defines phase-six protocol evidence. Canonical `charging.*` facts remain owned by the Laravel Charging context.

```powershell
npm.cmd install
npm.cmd run lint
npm.cmd run generate
```

CI regenerates the artifacts and fails if the committed output changes.
