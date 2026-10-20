# Mobile flow audit — 13 October 2026

## Scope

Reviewed the existing Flutter mobile flow and Laravel contracts from registration and email verification through QR Ph wallet funding, station/connector selection, quote review, remote Start, live metering, remote Stop, final settlement and Activity. This release changes mobile behavior only; no schema, tariff, gateway, payment-provider or VPS runtime configuration changes are required.

## Findings fixed

- A lost Start response could show failure even when the server had accepted the request. The app now queries the authoritative active session after an ambiguous response or an existing-session rejection and opens the recovered session. It does not issue another Start during recovery.
- An obsolete locally saved session could prevent recovery of the current server session until another refresh. A missing saved reference now falls through immediately to the active-session query.
- Stop idempotency keys persisted across different sessions. Retries for the same session retain their key; a different session gets a new key.
- Responses arriving after sign-out/account changes could repopulate the previous account's session/history. Requests now retain the account and controller generation, stale responses are discarded, and session expiry clears cached charging state. A local quick-unlock lock still preserves recovery references.
- Activity could display an empty history when the history request had failed. It now shows the error and clears it after successful recovery.
- Support/refund forms did not display failed submissions or update their busy state. Errors are now visible and duplicate taps are disabled. Support offers a copyable session reference and explicitly explains an unavailable submission endpoint; it never claims that a rejected request was received.
- An offline sign-out cleared credentials but threw an uncaught error. It now completes local sign-out and explains that server revocation could not be confirmed.

## Verified flow and boundaries

| Stage | Evidence / behavior |
| --- | --- |
| Registration | Existing registration-v2 form/API tests cover account creation and email-delivery recovery. No real customer was registered during this audit. |
| Authentication | Verification route guards, token expiry, quick unlock, tenant/device binding and sign-out are covered by mobile tests; selected backend auth/registration tests pass. |
| Wallet | Amounts remain integer centavos. QR creation does not credit money. Backend tests cover signed callback validation, duplicate callbacks, wrong amounts/currency/merchant, expiry and QR recovery. No live top-up or refund was created. |
| Discovery / prepare | Real station APIs and connector readiness are preserved; preparation retrieves the published tariff and wallet reservation budget. No price is calculated or trusted from the client. |
| Start / active | Server acknowledgement remains pending until charger evidence. Controller tests cover duplicate taps, timeout recovery, start deadline, stale updates, disconnects and app resumption. |
| Stop / settlement | Stop acknowledgement is not completion. Backend tests cover budget-triggered stopping, retaining holds for ambiguous starts, cancellation before dispatch, final energy rating and exactly-once wallet settlement. |
| Activity | Final amount/history are server projections. Receipt, invoice and refund actions remain conditional on server data. |

## Validation

- Five charging/account regression cases were observed failing before the fixes, then passing. A separate offline sign-out regression also failed before its fix.
- Full mobile suite: 174 tests passed. The final targeted run after the sign-out change passed seven tests, including the additional sign-out case.
- Selected backend suite: 47 tests, 284 assertions passed using PHPUnit's in-memory SQLite configuration. This is not a PostgreSQL deployment/migration test.
- Formatter checked 124 Dart files without changes.
- Flutter analyzer: no issues. Release APK build succeeded; the existing `mobile_scanner` future Kotlin-plugin compatibility warning remains.
- No dependency or lockfile changes were introduced by this audit.
- No Android phone/emulator was attached during the audit. Widget/controller tests do not establish physical EV or live AUB acceptance.

## Remaining pilot limits and supervised acceptance

The backend does not yet implement in-app support submission, refund execution or legal invoice generation. The app now reports submission failures and retains the reference for operator assistance; this release does not invent these contracts.

After installing the APK, perform one supervised physical EV session: sign in, confirm a funded wallet, choose the online connector, review the current tariff, request Start once, verify physical charging and increasing Wh, request Stop once, then wait for final cost and released unused wallet funds. Record the session reference if it remains pending or needs review. A working simulator or an HTTP acknowledgement alone does not prove the physical flow.

## APK

- Version `1.0.15`, Android build `16`, package `com.vtsa.vtsa_mobile`.
- Size: 79,480,418 bytes.
- SHA-256: `e40790bc9aa87a124fe4c54939f090d03755f2c346733d20c4081a03fb41b9b2`.
- Signature verified and unchanged from the preceding staging APK. This uses the existing staging Android debug certificate, not a Play Store production signing configuration.
- Download: `https://staging.evcspowersolutions.com/demo/Power-Solutions-Staging-Lumina-1.0.15.apk`.
- Uploaded file and published VPS file both match the local SHA-256. Previous APK downloads remain intact.
- Public HTTPS download verified: HTTP 200, 79,480,418 bytes, `application/vnd.android.package-archive`.
