# Unlock connector and restart charger

Open Charging network → Charging Stations → Setup in the admin or operator
portal. Both actions are in the station page header.

Give an authorized role Charging equipment → View and the relevant Charging
operations permission, Connectors → Unlock or Stations → Restart. Limit the
role assignment to the intended organization or site. Existing remote start/stop
permissions do not automatically grant recovery actions.

For a stuck cable, finish the session and verify locally that the vehicle has
stopped receiving power. Choose Unlock connector, select its EVSE/connector,
record the reason, confirm idle status and submit. Read the result under Recovery
commands. Follow the manufacturer's normal unplug procedure.

For an unresponsive online charger, confirm all connectors are idle and sessions
finalized. Choose Restart charger, record the reason and confirm local idle
status. After acceptance, wait for a new heartbeat and fresh connector reports.
Use the device's normal maintenance procedure if it cannot receive commands.

Offline or stale status, ongoing transactions and pending cancellation or
settlement block these actions. Remote stop is the recovery step for active
charging. An uncertain response requires inspection before another attempt.
Command diagnostics and Activity log retain the command and outcome evidence.

Deployment requires the station command migration, permission catalog sync and
updated charging workers before creating commands. Include Reset and
UnlockConnector in the gateway allowlist while preserving its existing commands.
No mobile application rebuild is required. Test first in staging with a separate
test identity or an idle supervised charger. Record physical unlock and restart
results separately from simulated protocol checks.
