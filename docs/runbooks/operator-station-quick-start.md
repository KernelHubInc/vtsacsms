# Set up a charging location: operator quick start

Use **Charging network** in the [admin portal](https://staging.evcspowersolutions.com/admin) or [operator portal](https://staging.evcspowersolutions.com/operator). Open **Charging Stations → Setup guide** for instructions inside the platform. On an existing station, choose **Setup → Operator instructions** and follow its checklist.

## Understand the records

| Record | Meaning | Example |
| --- | --- | --- |
| Site | Physical place on the map | LUMINA SUBIC |
| Charging station | Device connecting to OCPP | Identity `202607010021` |
| EVSE | Independently controlled charging outlet | EVSE 1 |
| Connector | Physical plug | Connector 1, Type 2 AC, 7000 W |

Reuse the site for additional chargers at the same place. Each charger needs its own unique identity. Do not duplicate an existing station or connect a simulator with a physical charger's identity at the same time.

## Before starting

Collect the operator organization, actual address/coordinates, hours, device serial number, OCPP version, exact identity, outlet numbering, plug standard and power rating. Obtain the approved WebSocket URL/security settings and tariff from the administrator. If buttons are missing, request the relevant site/equipment/pricing permissions for your site.

## Setup checklist

1. **Sites → Create.** Select the operator; enter the real address and coordinates. Use `Asia/Manila` for Philippine sites. For mobile discovery, set **Active** and **Public**, save, then choose **Publish location** on the edit page when ready.
2. **Charging Stations → Create.** Select the site. Enter a readable name, exact **Charge point identity**, actual **Serial number**, unique **QR identifier** and supported **OCPP version**. Set **Active** and **Public** when ready. Saving opens **Station setup**. The identity is only the device ID, not a URL, display name or plug-and-charge idTag. Match spelling and case exactly.
3. **Station setup → EVSEs → Add EVSE.** Select the station and actual EVSE number. Set **Active** when ready. The two verified single-outlet examples below use `1`.
4. **Station setup → Connectors → Add connector.** Select the EVSE, actual connector number, plug standard, AC/DC type, power in **watts** and **Active** lifecycle. `7 kW = 7000 W`; kWh measures consumed energy. Save its generated QR identifier. In OCPP 1.6, connector numbers must be unique across the station. Connector `0` is station-level status, not a physical plug. Confirm multi-outlet mapping with the manufacturer.
5. **Station setup → Operating hours.** Add all seven days in local site time. Use **Open 24 hours** or mark a day closed as appropriate. Edit existing days rather than adding duplicates. Missing days mean unknown; do not assume all-day operation.
6. **Station setup → Pricing → Tariffs.** Create a PHP tariff or open the intended tariff, then choose **Set pricing**. Select the **specific connector** for a device-only test. Choose the mode/price below, approved tax settings and `Asia/Manila`, then publish. Leaving the connector blank can apply a price to a whole site or tenant. Verify the effective tariff under **Physical connectors** in Station setup.
7. **Configure the device.** Use the approved WebSocket host, port and security settings. If firmware appends a separate identifier, use a base URL ending in `/ocpp` and enter the identity separately. Otherwise use the full endpoint ending in `/ocpp/{identity}`. Include the identity once. Preserve confirmed working firmware/security settings; do not infer them from another model. This staging gateway resolves active saved stations dynamically; operators do not edit Nginx or restart the gateway per station.
8. **Save and reconnect while idle.** Configure the EVSE and connector first. Return to Station setup and confirm **Online**, a recent message and a fresh status for each physical connector. If you added the connector after the device connected, reconnect it again to obtain fresh status. A heartbeat alone cannot mark a plug Available.
9. **Check the mobile app.** Confirm the map position/address, plug/rating, hours and price. Scan a QR containing the connector QR identifier shown in Station setup, or use the existing manual station-ID flow for a station with exactly one connector. Verify the resolved station and plug. The driver's QR is not the OCPP WebSocket URL.
10. **Test with an EV under supervision.** Confirm credited wallet funds, plug in, select the connector, review the price and reserved budget, and request start once. Wait for actual **Charging** and increasing energy. Request stop, wait for completion, then verify final energy, price, wallet debit/release and receipt. Pending or an accepted command alone is not a completed charge. With no EV, leave the physical acceptance test outstanding.

| Intended price | Charge by | Price field | Time unit |
| --- | --- | --- | --- |
| ₱1 per kWh test | Energy (per kWh) | `1.00` | Ignored |
| ₱1 once per session | Session (once) | `1.00` | Ignored |
| ₱1 per minute while energy flows | Energy-flow time | `1.00` | Per minute |

New prices apply to new sessions; existing sessions retain their tariff snapshot. Time pricing counts metered energy-flow periods, not all connected/waiting time. Wallet top-ups are separate from charging fees. A prepaid budget may stop the session before a full kWh is delivered.

## Existing examples — checked October 17, 2026

Inspect these existing records; do not recreate them. Live status can change.

| Field | LUMINA SUBIC | VTSA-CP01 |
| --- | --- | --- |
| Site | LUMINA SUBIC | VTSA - DEMO |
| Charge point identity | `202607010021` | `VTSA-CP01` |
| OCPP | 1.6J | 1.6J |
| EVSE / connector | 1 / 1 | 1 / 1 |
| Plug / current / power | Type 2 / AC / 7000 W | Type 2 / AC / 7000 W |
| Timezone | Asia/Manila | Asia/Manila |
| Site / station | Published site; Active/Public station | Published site; Active/Public station |
| Operating days recorded | None — enter the real hours | Seven — confirm actual hours |

Lumina's address is **Block 10 Lot 47, Lumina Homes, Pamatawan, Subic, Zambales**, latitude `14.926222`, longitude `120.223833`. VTSA's current site is a demo location: verify its real address/map position before offering it as a public location. Confirm both recorded equipment profiles against the physical labels.

Lumina's previously saved base URL is `wss://staging.evcspowersolutions.com:8080/ocpp`, with separate device identifier `202607010021`. Its final path is `/ocpp/202607010021`. Keep the confirmed working URL on each device; ports are deployment-specific. The active VTSA identity is **VTSA-CP01**, not the retired record's **VSTA-CP01**.

VTSA-CP01 sent its earlier Available reports before its current connector existed; they were quarantined. Its current connector was created October 17 at 10:01 AM GMT+8. At the check, a fresh physical connector report was still needed. Do not manually force Available or reuse an old report to conceal this condition.

## Troubleshooting

| Display | Meaning / next step |
| --- | --- |
| Online + Unknown | Connection is alive; no usable current plug status. Check Active EVSE/connector and matching numbers; reconnect while idle for fresh StatusNotification. Escalate if it persists. |
| Not configured | Add the EVSE and connector, then reconnect. |
| Not seen / Unknown connection | No connection evidence. Check exact identity, saved URL, internet and power. |
| Offline | Connection/recent heartbeat evidence is missing. Check the device/network; do not manually mark it Available. |
| Available | Device reports the plug ready; verify pricing and vehicle compatibility too. |
| Preparing / Charging / Paused / Finishing | Follow the actual session state. Paused is not completed. Wait for confirmed stop and settlement. |
| Faulted / Unavailable | Review Status and faults and the device display; involve maintenance. Active lifecycle does not clear a device fault. |
| Missing from mobile | Check site Active/Public, coordinates/publication and station Active/Public. Check equipment/pricing for start readiness. |
| Start stays pending | Check Activity and the charger; use Cancel pending start if appropriate. Cancellation may wait for device evidence before releasing funds. Do not delete history or start multiple attempts. |

Use **Session diagnostics** and **Command diagnostics** for start/stop requests, **Status and faults** for equipment problems, and **Session review queue** for unresolved transactions. Send support the exact identity, EVSE/connector number, timestamp/timezone, session/command reference and displayed error. Do not send passwords or signing keys.

Before handover, record: correct map location, active equipment, actual hours, effective tariff, fresh connection/plug status, successful supervised start/energy/stop, wallet settlement and receipt. Preserve history; use the guarded retire/delete workflow instead of deleting records to clear a connection issue.
