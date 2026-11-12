# Tariff Engine and Immutable Snapshot

**Status:** Phase 7 implemented deterministic baseline  
**Owner:** Tariffs for definitions/rating semantics; Charging stores the selected immutable snapshot

## Selection

Only published versions effective at the requested UTC instant are selectable. Applicability precedence is connector, site, operator, then tenant-wide. A requested version must still be published, effective, in the tenant, and applicable to the connector projection. Published effective intervals for the same tariff and scope cannot overlap.

Every session stores canonical tariff JSON and its SHA-256 hash. Publishing makes the version, components, and discounts immutable; a price change requires a new effective-dated version. Reconstructed sessions without a deterministically selectable tariff store an explicit `pricing_status: missing` snapshot and enter anomaly handling rather than inventing a price.

## Units and rating

- Energy components rate integer `energy_wh` against an integer `unit_quantity` (normally 1,000 Wh).
- Time, parking, and idle components rate integer seconds (normally 60-second units).
- Session components are charged once.
- All prices, discounts, tax, minimums, maximums, subtotals, and totals are integer minor units. Half units round away from zero; floating-point money is prohibited.
- Day masks and local time windows are evaluated in the tariff version's IANA timezone. The baseline selects the applicable band at the session start instant; splitting a session across changing bands remains an open decision.
- Higher-priority applicable components override lower-priority components for the same dimension.
- Automatic discounts and a matching supplied promotion code apply deterministically in stored order. Percentage discounts use basis points and cannot exceed 10,000; fixed discounts cannot reduce the total below zero.
- Tax-exclusive pricing adds the configured basis-point tax. Tax-inclusive pricing derives the tax portion from the total. A tax rate may be omitted; no registration detail or rate is inferred.
- Minimum and maximum fees apply after discounts and tax, and the rating result records adjustments and a complete breakdown.

## Supported dimensions and scopes

The baseline supports per-kWh-equivalent energy, per-minute-equivalent time, session, parking, idle, minimum/maximum fee, day-of-week, time-of-day, operator/site/connector scopes, inclusive/exclusive tax treatment, automatic promotions, supplied promotion codes, and effective dates.

Admin **Tariffs → Set pricing** can publish an optional post-charge idle price with 30 free minutes as the default. The price is prorated by the second after the free window and uses the tariff’s tax settings. Normal tariff selection lets a connector version override a site version. The session snapshots the price and free time at authorization. Existing sessions keep their quoted price.

The idle price applies to compatible mobile prepaid sessions with a disclosed quote. Charging preserves the wallet reservation while the confirmed stop awaits connector release. An uninterrupted charger connection, a post-charge occupied report, and a subsequent Available report on that connection establish the interval. A lost connection, a conflicting status or an unconfirmed release two hours after the free window finalizes charging with zero idle fee. The prepaid cap can reduce the idle charge. Platform fee waiver thresholds use charging usage before idle pricing.

## Assumptions and open decisions

- Currency is chosen by the operator when creating a tariff; seed data creates no production currency or tax rate by default.
- Parking bay occupancy requires independent evidence and remains an open decision.
- Cross-midnight windows, band splitting, discount stacking policy, tax rounding jurisdiction, tiered/block pricing, reservation fees, roaming tariffs, demand charges, and retroactive corrections need explicit product/legal decisions.

