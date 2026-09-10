# Supported OCPP Surface

This is the phase-six interoperability baseline, not a certification claim. Every inbound CALL is validated by the maintained `ocpp` package's versioned JSON schema. Unsupported or malformed actions receive protocol errors; vendor extensions do not silently mutate business state.

## Charger-to-gateway messages

| Normalized fact | OCPP 1.6J | OCPP 2.0.1 | Policy |
| --- | --- | --- | --- |
| Boot | `BootNotification` | `BootNotification` | Accepted after connection identity succeeds |
| Heartbeat | `Heartbeat` | `Heartbeat` | Gateway returns UTC and configured interval |
| Connector status | `StatusNotification` | `StatusNotification` | Preserves protocol timestamp and clock-skew evidence |
| Token authorization | `Authorize` | `Authorize` | Delegated to core; fails closed |
| Start/update/stop | `StartTransaction`, `StopTransaction` | `TransactionEvent` | Protocol evidence only; Charging owns canonical sessions |
| Meter samples | `MeterValues` | `MeterValues` | kWh/kW convert to Wh/W at the boundary |
| Vendor exchange | `DataTransfer` | `DataTransfer` | Vendor allowlist; empty by default |
| Diagnostics/log status | `DiagnosticsStatusNotification` | `LogStatusNotification` | Status only; no URL fetching |
| Firmware status | `FirmwareStatusNotification` | `FirmwareStatusNotification` | Status only; no firmware trust decision |
| Security event | `SecurityEventNotification` | `SecurityEventNotification` | Technical detail presence is recorded; detail is not emitted downstream |

OCPP 1.6 `transactionId` values are gateway wire-correlation integers. They are never Laravel session identifiers. OCPP 2.0.1 transaction IDs remain protocol identifiers. Both are passed as evidence to Charging, which resolves canonical sessions and applies state guards.

## Gateway-to-charger commands

Commands require internal service authentication and an `OCPP_ALLOWED_COMMANDS` entry. Firmware, diagnostics/log, reset, remote start/stop, availability, and unlock remain disabled unless explicitly enabled by a reviewed deployment policy.

| OCPP 1.6J | OCPP 2.0.1 |
| --- | --- |
| `TriggerMessage` | `TriggerMessage` |
| `Reset` | `Reset` |
| `RemoteStartTransaction` | `RequestStartTransaction` |
| `RemoteStopTransaction` | `RequestStopTransaction` |
| `UnlockConnector` | `UnlockConnector` |
| `ChangeAvailability` | `ChangeAvailability` |
| `GetDiagnostics` | `GetLog` |
| `UpdateFirmware` | `UpdateFirmware` |

Payloads are validated by the OCPP library when constructing and serializing each version-specific request. Command ULIDs are used as OCPP unique IDs, allowing the HTTP request, wire response, metrics, and normalized command-response event to correlate.

## Unsupported optional profiles

The following are intentionally unsupported in this phase:

- OCPP 1.6 SOAP, OCPP 1.5, and all protocols other than 1.6J/2.0.1;
- smart charging/profile management and composite schedules;
- reservation and local authorization-list management;
- certificate install/delete/signing messages and ISO 15118 certificate flows;
- display/customer-information, cost, tariff, and pricing profiles;
- monitoring, variables, reports, and remote configuration;
- signed meter values and signed firmware download/update profiles;
- diagnostics or firmware file hosting, retrieval, signature validation, or SSRF-prone URL proxying;
- vendor-specific DataTransfer adapters beyond an explicit vendor-ID gate;
- OCPP certification assertions or undocumented charger-specific workarounds.

Adding an optional profile requires a threat model, contract/version update, conformance fixtures, payload retention decision, command authorization policy, and interoperability evidence for the targeted charger models.
