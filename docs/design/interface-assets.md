# Power Solutions interface assets

The [Figma asset kit](https://www.figma.com/design/5JbQJvI65H27InJqks0J00/Untitled) supplies the exact station pins, status badges, and connector and amenity symbols used in the driver app, public website, and admin/operator map. Source SVG exports live in `apps/platform/public/branding/interface/`; the Flutter app includes three-times-resolution PNG renders of those same exports in `apps/mobile/assets/interface/`. The existing Power Solutions logo remains the primary brand mark.

The map adapter selects a pin from live station status. A selected available Power Solutions station uses the selected pin; selecting any other state keeps its status symbol visible. Only stations whose published operator name is Power Solutions or EVCS Power Solutions use the branded pin. All others use the neutral operator pin. The admin/operator map reads the site operator name from its authorized reporting query. The mobile app uses the same name from the station API.

Status badges never change charging state. They label the state already returned by the backend. Stale means the last report is too old to confirm availability. Connector and amenity icons accompany names, so the symbols are not the sole source of information. Map pins have at least a 56 × 64 pixel hit target and remain keyboard-selectable on the web maps.

The Figma cluster examples contain a fixed illustrative count. They are not used for dynamic clusters, whose count must come from current map data. Unrecognized connector standards and amenities keep their text labels without a misleading icon. An unavailable status keeps the existing text chip because the kit provides no unavailable badge.
