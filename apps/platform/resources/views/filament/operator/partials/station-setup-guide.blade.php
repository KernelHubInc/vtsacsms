<div class="space-y-6 text-sm text-gray-700 dark:text-gray-200">
    <p>Follow this order: <strong>Site → Station → EVSE → Connector → Hours → Pricing → Device check.</strong> Use an existing record when it already represents the same location or device.</p>

    <ol class="space-y-4">
        <li>
            <h3 class="font-semibold">1. Create the location in Sites</h3>
            <p class="mt-1">Select the correct operator. Enter the address, map coordinates and timezone (Asia/Manila for Philippine sites). Set Active and Public, then use Publish location on the edit page when the location is ready to appear in the app.</p>
        </li>
        <li>
            <h3 class="font-semibold">2. Register the device in Charging Stations</h3>
            <p class="mt-1">Select its site. Enter a readable name, the exact charge point identity saved on the device, its serial number, a unique station QR identifier and the supported OCPP version. Set Active and Public when ready. Saving opens Station setup; use Setup on an existing station.</p>
            <p class="mt-1">The charge point identity is not a URL, display name or driver idTag. Match its spelling and case exactly. Never connect a simulator and a physical charger with the same identity at the same time.</p>
        </li>
        <li>
            <h3 class="font-semibold">3. Add the EVSE, then its connector</h3>
            <p class="mt-1">An EVSE is an independently controlled charging outlet; a connector is its physical plug. In EVSEs, select the station and actual EVSE number. In Connectors, select that EVSE, the actual connector number, plug standard, AC/DC type and maximum power in watts. A 7 kW charger is 7000 W.</p>
            <p class="mt-1">Set both records to Active when ready. For a confirmed single-outlet device, EVSE 1 / Connector 1 is typical; check the manufacturer's mapping. Connector 0 is not a physical plug. For OCPP 1.6, physical connector numbers must be unique across the station. The connector QR identifier is generated when saved.</p>
        </li>
        <li>
            <h3 class="font-semibold">4. Enter all seven operating days</h3>
            <p class="mt-1">In Operating hours, select the site and enter each day's opening and closing times. Use Open 24 hours for an all-day service or mark a day closed. Missing hours mean unknown, not closed.</p>
        </li>
        <li>
            <h3 class="font-semibold">5. Publish pricing for the intended connector</h3>
            <p class="mt-1">In Tariffs, create or open a tariff and choose Set pricing. Select the specific connector to avoid applying a test price to other stations. Choose Energy (per kWh), Session (once), or Energy-flow time, then enter the price and approved tax settings. For a PHP test tariff, Energy with 1.00 means ₱1 per kWh. Time pricing counts only metered energy-flow periods.</p>
            <p class="mt-1">Publish the price, then confirm its name under Physical connectors in Station setup. New prices apply to new sessions; existing sessions retain their original pricing.</p>
        </li>
        <li>
            <h3 class="font-semibold">6. Connect the charger after configuring its equipment</h3>
            <p class="mt-1">Use the WebSocket host, port and security settings approved for your deployment. If the device appends its separate identifier, enter a base URL ending in /ocpp and enter the charge point identity separately. Otherwise use the full URL ending in /ocpp/{charge-point-identity}. Include the identity only once.</p>
            <p class="mt-1">Save and reconnect while idle. In dynamic enrollment mode, saving an active station registers it for gateway lookup; operators do not need to edit or restart the gateway. Device authentication settings depend on the deployment and firmware.</p>
        </li>
        <li>
            <h3 class="font-semibold">7. Confirm readiness, then test with an EV</h3>
            <p class="mt-1">Return to Station setup. Check a recent device message and Online connection, then a fresh status for each physical connector. Check the station, plug, hours and price in the mobile app. Scan the connector QR, review the price and wallet budget, connect an EV and start a supervised session. Confirm Charging, increasing energy, stop confirmation, the final wallet charge and receipt.</p>
            <p class="mt-1">Pending or a successful start command is not proof that energy is flowing. With no EV available, record setup as configured but leave the physical charging test outstanding.</p>
        </li>
    </ol>

    <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-700">
        <h3 class="font-semibold">If a status does not look right</h3>
        <dl class="mt-3 space-y-3">
            <div><dt class="font-medium">Online + Unknown</dt><dd>The connection is alive, but there is no usable status for the configured plug on this connection. Confirm the EVSE and connector numbers and Active lifecycle, then reconnect the idle charger for a fresh StatusNotification. A heartbeat alone cannot mark a plug Available.</dd></div>
            <div><dt class="font-medium">Not configured</dt><dd>No physical connectors are configured for the station. Add the EVSE and connector before reconnecting.</dd></div>
            <div><dt class="font-medium">Offline or Not seen</dt><dd>Check power, LAN/4G internet, the exact identity and the approved WebSocket URL. Ask support to check connection logs if it remains offline.</dd></div>
            <div><dt class="font-medium">Faulted or Unavailable</dt><dd>Review Status and faults and the charger's display. Resolve the cause with maintenance; setting the asset to Active does not clear a device fault.</dd></div>
            <div><dt class="font-medium">Missing from the app</dt><dd>Check the site's Active/Public settings, coordinates and publication, then the station's Active/Public settings. Confirm active equipment and effective published pricing before attempting a start.</dd></div>
        </dl>
    </div>
    <p>If an action is missing, ask your administrator for the relevant site, equipment or pricing permissions. For unresolved problems, give support the station identity, connector number, exact time with timezone and the displayed error. Keep records with charging history; do not delete and recreate a station to clear a status.</p>
</div>
