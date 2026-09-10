# OCPP TLS and Client Certificate Rotation

## Scope

This runbook defines the interface and safe sequence for gateway server and enrolled charger client certificates. It does not choose a production CA, secret manager, domain, certificate contents, validity period, or charger-vendor enrollment mechanism.

## Preconditions

- An approved CA and service owner have issued the replacement certificate.
- Private keys are injected through the selected secret mechanism and never stored in Git, container layers, logs, screenshots, or Redis.
- Certificate/key matching, subject alternative names, chain, validity, key usage, and revocation status have been verified out of band.
- Rotation has an observation window and rollback owner.
- For charger mTLS, Assets has the correct charger ULID/tenant binding and the charger supports the selected security profile.

## Gateway server certificate

1. Stage the replacement certificate chain and private key at new immutable paths or secret versions.
2. Point `OCPP_TLS_CERTIFICATE_FILE` and `OCPP_TLS_PRIVATE_KEY_FILE` at the staged pair. If the gateway terminates behind an approved edge, rotate there under its equivalent procedure instead.
3. Start a canary gateway node and verify `/health/ready`, TLS chain/SNI, both OCPP subprotocol handshakes, and a synthetic simulator boot/heartbeat.
4. Roll nodes gradually. Existing sockets may remain on the prior certificate until drained; new connections must receive the replacement.
5. Observe handshake failure, connection churn, reconnect latency, boot rejections, and node ownership metrics through at least the agreed overlap window.
6. Remove the old certificate/key reference only after all nodes and trusted edges use the replacement and rollback is no longer required.
7. Revoke the old certificate when policy requires and record the rotation evidence/reference in the approved audit system.

The current process loads certificates at startup; it does not hot-reload them. Rolling restart/drain timing remains a deployment decision. Never replace a key file non-atomically under a running process.

## Client CA bundle

1. Add the new issuing CA to a combined old+new trust bundle.
2. Set `OCPP_TLS_CLIENT_CA_FILE` to the combined bundle and canary/restart gateway nodes.
3. Enroll/rotate charger certificates while both issuers are trusted.
4. Confirm new fingerprints and successful charger reconnects. The injected registry projection stores only the lowercase SHA-256 leaf-certificate fingerprint, not the certificate/private key.
5. After the fleet migration and exception review, remove the old CA from the trust bundle and roll nodes again.
6. Revoke/expire the old issuing path according to CA policy and preserve audit evidence.

Set `OCPP_TLS_REQUIRE_CLIENT_CERT=true` only when the CA bundle is present and the targeted charger fleet has completed enrollment. This switch rejects every charger without a trusted client certificate at the TLS handshake.

If TLS terminates at an approved edge and the ASGI scope cannot expose the peer certificate, the edge may supply a SHA-256 fingerprint header selected by `OCPP_TRUSTED_CLIENT_CERTIFICATE_FINGERPRINT_HEADER`. The edge must authenticate to the gateway, strip every client-supplied copy, and keep the gateway listener unreachable except from that edge. Directly trusting an internet-supplied fingerprint header is prohibited.

## Individual charger certificate

1. Issue/install the new certificate through an approved charger capability workflow; do not invent a vendor procedure.
2. During the overlap window, update the Assets safe registry projection to accept the intended new fingerprint. The current phase-six static entry supports one fingerprint, so coordinated cutover or a future multi-fingerprint projection is required.
3. Restart/reload the gateway configuration through the deployment system, reconnect the charger, and verify it binds to the expected tenant/charger ULIDs.
4. Disable/revoke the old certificate and remove its fingerprint after verification.
5. If compromise is suspected, disable the charger enrollment immediately, preserve evidence, revoke the certificate, and do not use an overlap window.

## Verification

```powershell
Invoke-RestMethod https://gateway.example.invalid/health/ready
```

Use the simulator with environment-injected credentials and certificate paths to test boot and heartbeat. Do not copy production charger credentials into local environments.

Success requires: no unexpected TLS downgrade; correct subprotocol; correct tenant/charger binding; stable lease ownership; normalized connected/boot/heartbeat events; and no credentials or certificate bodies in logs.

## Rollback

Restore the prior secret/config version and roll gateway nodes back only while its certificate remains valid and unrevoked. If the old key or certificate is compromised, rollback to it is prohibited; issue a new replacement and contain affected identities instead.
