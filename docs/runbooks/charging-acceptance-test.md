# CSMS, charger and EV acceptance test

Start with one operator, one driver, one EV and one physical charger. Keep simulators using that device identity disconnected. Complete each checkpoint before continuing. Record the station identity, connector, mobile session reference, OCPP transaction ID, test time/timezone and outcome.

## Preparation

- Verify the site, station, EVSE, connector, actual hours and published tariff using [Operator quick start](operator-station-quick-start.md).
- Lumina uses identity `202607010021`; VTSA uses `VTSA-CP01`. Both current records have EVSE 1 / Connector 1, Type 2 AC, 7000 W and OCPP 1.6J. Confirm labels against the actual device.
- In Station setup, confirm Online and a fresh status for Connector 1. Resolve Unknown before testing. Use the deployment's working OCPP URL and firmware settings; do not reset the charger while a session is active.
- Use an authorized driver account with registration/verification requirements completed, the correct personal/shared charging account and vehicle, and credited wallet funds. Verify the current quote. A test unit price is not the same as the wallet's reserved spending budget.
- Have the EV owner present. Confirm the EV is able to accept a charge (battery below its limit, no charging schedule blocking this test). Follow the charger and EV operating instructions. Use the normal local stop procedure if the actual vehicle continues charging while remote control is unavailable; do not pull a locked connector or treat a dashboard label as physical proof.

## Test one: mobile start and mobile stop

| Step | Operator / driver action | Required evidence |
| --- | --- | --- |
| 1. Baseline | Open Station setup, Session diagnostics and Command diagnostics. Record initial wallet available/reserved balances. | Recent heartbeat, correct connector mapping and no conflicting active request. |
| 2. Connect EV | Plug in according to the device instructions. | Device detects the EV; normally Preparing. No physical fault. |
| 3. Review mobile quote | Scan the connector QR or select the correct station/plug. Confirm vehicle/account, tariff and wallet budget. | The quote identifies the intended connector and approved price, e.g. PHP 1.00/kWh. |
| 4. Request start once | Tap Request start in the app. | A session/request reference appears and Command diagnostics records RemoteStartTransaction. |
| 5. Confirm physical start | Wait for the charger and EV to start. | StartTransaction is processed, an OCPP transaction ID is recorded, session becomes Charging, and power/energy begin updating. Command Accepted alone is insufficient. |
| 6. Check telemetry | Observe enough metering updates to compare app, CSMS and device. | Session identity and connector agree; energy increases; power is plausible for the charger/EV. A paused EV can legitimately show no energy increase. |
| 7. Request stop once | Tap Stop in the mobile active-session screen. | RemoteStopTransaction targets the same OCPP transaction ID. Acknowledged is only command acceptance; the session may show Stopping. |
| 8. Confirm completion | Wait for StopTransaction/final readings. Follow the device's unplug instructions. | EV has stopped receiving power; CSMS records stop time and final energy; app displays completion. Connector may remain Finishing until unplugged, then Available. |
| 9. Confirm settlement | Compare receipt, final energy, charge and wallet ledger. | Exactly one settlement; unused reserved funds released; no duplicate debit. If review is required, retain the reference and investigate rather than creating another request. |

For an energy-only PHP 1.00/kWh tariff with no additional charges, 100 Wh is 0.1 kWh and costs PHP 0.10 before any separately configured tax treatment/rounding. Compare against the actual published tariff and receipt. A small prepaid budget can cause an automatic stop earlier than expected.

### Where QR scanning fits in the first test

Scan after connecting the EV, before reviewing the price and requesting a start. Scanning identifies the connector; it does not start charging or make a payment.

1. In admin/operator **Charging Stations**, open Lumina's **Station setup**, then find Connector 1 and its **QR identifier**. The driver QR must encode that identifier exactly. A manufacturer's existing QR is not automatically a Power Solutions connector QR. Do not use the OCPP WebSocket URL or a wallet QR Ph payment code for this step.
2. In the mobile app, sign in, choose the intended charging account and vehicle, and confirm that the wallet has credited funds. Plug in the EV following the equipment instructions.
3. Open **Explore**, choose **LUMINA SUBIC**, expand **Station details & directions**, then tap **Scan charger to start**. Alternatively, use the QR scanner icon on Explore (labelled **Scan charger code**).
4. On **Identify the connector**, confirm the account/vehicle, tap **Continue to camera**, grant camera permission, and scan the connector QR. The app validates it and opens the review screen.
5. If scanning is unavailable, enter `202607010021` in **Charger code** and tap **Validate connector**. This station-ID shortcut applies while Lumina has exactly one configured connector; for a multi-connector station use the specific connector QR identifier.
6. Verify LUMINA SUBIC, Connector 1, Type 2 AC, the live tariff and reserved budget. For this test the intended tariff is PHP 1/kWh; do not proceed if the quote is different. Tap **Review and request start**, read the **Start charging?** confirmation, then tap **Request start** once.
7. Continue the evidence checks in test one: charger-confirmed transaction ID, physical energy flow, telemetry, stop confirmation and one wallet settlement. Pending or Waiting for charger is not proof of charging.

## Test two: operator remote stop

Run a **new** short session and complete steps 1–6. An authorized operator opens **Session diagnostics**, identifies the live session by station and transaction ID, chooses **Remote stop**, and confirms. Verify steps 8–9 and confirm the driver's app updates too. The operator must have remote-command permission for this site. A cancelled request or a request with no charger transaction ID cannot be remotely stopped as an active transaction.

## Additional cases after both normal flows pass

- **Cancel before charging:** create one controlled pending request while the device is idle, use Cancel pending start, and verify its final state and wallet hold release. A sent request may wait for fresh idle evidence; do not fabricate a status with a simulator.
- **Insufficient funds:** confirm the app cannot begin a new paid session without the required budget and does not create duplicate holds.
- **Repeat taps / refresh:** verify retries do not create duplicate sessions, commands or wallet debits. Keep the same request reference when reviewing a retry.
- **Account sharing:** use an authorized shared-account driver, verify the intended vehicle and payer wallet; verify a non-member cannot use the account.
- **Offline display:** while the charger is idle, perform a planned network disconnect/reconnect. Confirm offline detection and fresh connector status on reconnect. Active-session network-loss testing is a separate supervised case after normal stop is proven.
- **Different tariffs:** publish a connector-scoped session/time tariff for a separate test, confirm its quote, and restore the intended tariff afterward. Time pricing measures energy-flow periods. Do not change pricing midway and expect an existing session's snapshot to change.

## If start or stop fails

1. Keep the reference and inspect Command diagnostics. Requested/dispatched, acknowledged, rejected, timed out and delivery unknown are different outcomes.
2. Inspect Session diagnostics for the actual OCPP transaction ID, latest event, meter readings and state. A pending request without StartTransaction has no confirmed transaction to stop; use the pending-cancel flow.
3. Check the charger's physical screen and the EV. Online only proves communication, not current delivery or successful remote control.
4. Give support the station/connector, session and command references, exact time/timezone and visible error. Do not send passwords, authorization tags or payment keys.
5. Do not delete records, force Available, replay an old start command, or mark a wallet transaction paid/completed manually to make the test appear successful.

## October 17 diagnostic finding

Lumina's 17:21:46 GMT+8 request did not obtain a charger transaction ID. Its RemoteStartTransaction failed gateway validation because its generated authorization tag had 45 characters; the installed OCPP 1.6 schema permits at most 20. Offline validation reproduced rejection at 45 and acceptance at 20. No remote-stop command was recorded for that request; it was subsequently cancelled after idle evidence.

The backend correction issues a random 20-character alphanumeric tag, retaining hash-only authorization storage and encrypted command secret storage, expiry, session/station binding and revocation. Existing tokens are not rewritten and old commands are not replayed. Use a fresh request after deployment. Passing software checks does not prove a successful physical charge: complete the supervised tests above.

Deployed to staging on October 17 as `vtsa-prepaid-platform:20261017-ocpp-token` across the web application, event/authorization consumers and charging workers. Verification: the regression failed on the old 45-character implementation; the corrected image passed 263 tests / 2,263 assertions, Pint and PHPStan passed, and PostgreSQL token issuance/hash checks passed with the transaction rolled back. The readiness endpoint returned HTTP 200 after deployment. The native gateway process remained unchanged. No migration, mobile rebuild or physical start/stop command was needed for this deployment. Physical EV acceptance remains to be performed.
