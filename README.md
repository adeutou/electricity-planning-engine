# Electricity Planning Engine

A small, well-architected **electricity arbitrage engine**: given an energy contract, hourly market prices, a home battery, PV production and a consumption profile, it decides — hour by hour, over a 24–72h horizon — whether to consume from the grid, charge/discharge the battery, or export surplus PV to the grid, in order to minimize cost.

## Why this project exists

This is a technical demonstration built for a **Backend Engineer – Electricity Planning** application (Selectra). The role is about building the API that decides, hour by hour, whether a household/SME should consume, store, or re-inject electricity, based on energy prices, contracts, and PV production — across several countries. This repository is a scoped-down but architecturally honest version of that problem: a real domain model (contracts, tariffs, batteries), a clean Laravel API, two arbitrage engines (a simple greedy V1 and a more anticipatory V2), formal tests, and documentation written the way I'd want a teammate to hand it to me.

It is not a production energy-management system. It *is* a demonstration of how I structure a non-trivial domain in Laravel: where business rules live, how they stay independent of Eloquent and HTTP, and how a genuinely tricky bug (see below) gets found and fixed with a regression test, not just patched.

## Architecture

Clean-ish layering: business rules have zero knowledge of Laravel; Laravel knows about business rules only through interfaces.

```
app/
├── Domain/                 Pure PHP — no Laravel, no Eloquent, no framework imports.
│   ├── Shared/              Energy, Power, Money, Percentage, TimeHorizon value objects.
│   ├── Contract/            EnergyContract entity + PricingStrategy implementations.
│   ├── Asset/               Battery, PhotovoltaicSystem, ConsumptionProfile.
│   ├── Pricing/              PricePoint, PriceSeries, PriceProviderInterface (port).
│   └── Arbitrage/            ArbitrageEngineInterface, ArbitrageContext, ArbitragePlan,
│                             HourlyDecision — shared vocabulary of V1 and V2.
│
├── Application/            Orchestration — depends on Domain and its own Ports only.
│   ├── Arbitrage/            SimulateArbitrageUseCase, the V1/V2 engines.
│   ├── Pricing/              FetchPriceSeriesUseCase, PriceProviderResolver.
│   └── Ports/                 Repository interfaces, implemented by Infrastructure.
│
├── Infrastructure/         Laravel / Eloquent / HTTP-client specific code.
│   ├── Persistence/Eloquent/  Models, Mappers (Eloquent <-> Domain), Repositories.
│   └── PriceProvider/        Mock / ENTSO-E providers, caching decorator, factory.
│
├── Http/                   Controllers, Form Requests, API Resources.
│
└── Providers/
    └── DomainServiceProvider.php   The one place Ports are bound to Infrastructure.
```

Every arrow above points inward: `Http` depends on `Application`, `Application` depends on `Domain` and its own `Ports`, `Infrastructure` implements those `Ports` and is wired in by `DomainServiceProvider` — `Domain` depends on nothing. Mappers in `Infrastructure/Persistence/Eloquent/Mappers` are deliberately the *only* place that imports both an Eloquent model and a Domain type.

**Why split it this way?** The arbitrage logic (V1/V2) is the part worth showing off in an interview — it should be readable and testable without booting Laravel, hitting a database, or mocking Eloquent. `tests/Unit/Domain` and `tests/Unit/Application` run against plain `PHPUnit\Framework\TestCase`, no framework bootstrap, no database: they run in milliseconds and read like a specification of the business rules.

## Domain model

- **`EnergyContract`** doesn't know how its price is computed — it delegates to a `PricingStrategyInterface` (Strategy pattern), so adding a new contract type or country never touches the entity itself:
  - `FixedPricingStrategy` — flat €/kWh.
  - `PeakOffPeakPricingStrategy` — HP/HC-style, with seasonal rates (`SeasonalTariff`) and recurring daily time slots (`TimeSlot`, handles midnight wraparound).
  - `TempoPricingStrategy` — EDF Tempo-style: each calendar day is Blue/White/Red (`SpecialDayColor`), each color has its own peak/off-peak rate. This also stands in for the "effacement day" concept mentioned in the job spec.
  - `DynamicSpotPricingStrategy` — wholesale spot price × supplier margin + fixed fee. The only strategy that needs a `PriceSeries`.
  - Raw JSON (as stored in `energy_contracts.pricing_config`) is hydrated into one of these via `PricingStrategyFactory::fromConfig()`.
- **`Battery`** is immutable: `charge()`/`discharge()` return a new instance. Round-trip efficiency is split evenly between charge and discharge (`sqrt(efficiency)` each side) — a deliberate simplification, documented in the code, in the absence of separate manufacturer figures. SOC min/max and power limits are enforced by throwing a `DomainException`, not by silently clamping.
- **`PhotovoltaicSystem` / `ConsumptionProfile`** accept either an explicit hourly profile or fall back to a synthetic curve (a cosine bell for PV, a two-peak morning/evening curve for consumption) — good enough for a demo, explicitly *not* a real irradiance or load-forecasting model.
- **`PriceSeries`** indexes points by **Unix timestamp**, not by ISO-8601 string. That's not incidental — see [the bug story](#a-real-bug-found-along-the-way) below.

## Arbitrage logic

Both engines implement `ArbitrageEngineInterface::plan(ArbitrageContext): ArbitragePlan` and compare against the contract's *effective* price (`EnergyContract::priceForHour()`), never the raw market price directly — this is what lets the exact same engine code handle a flat contract, HP/HC, Tempo, or a spot contract identically.

### V1 — `SimpleArbitrageEngine` (greedy, hour by hour)

1. PV covers consumption first (self-consumption).
2. **Surplus** → charge the battery if the current price is lower than the average of the next *N* hours (default 6h lookahead), otherwise export (capped at the contract's max export power). Curtailed if it exceeds that cap and the battery is full.
3. **Deficit** → discharge the battery if the current price is higher than the average of the past *N* hours (lookbehind), otherwise import from the grid (which always covers the remainder — no load-shedding in V1).

This is intentionally a *local* heuristic — understandable, cheap, and correct, but short-sighted: a battery can get spent on a "locally expensive" hour and be empty for the real daily peak. That limitation is exactly what V2 addresses (and what `AdvancedArbitrageEngineTest` demonstrates).

### V2 — `AdvancedArbitrageEngine` (two-phase, horizon-wide)

1. **Planning phase** (stateless): apply an optional forecast safety margin to PV/consumption (modeling forecast uncertainty as a simple derating, not a real probability distribution), then rank *every* hour of the horizon by price — cheapest surplus hours first, priciest deficit hours first — and accumulate until the battery's available headroom would be filled. This produces two global price thresholds.
2. **Dispatch phase** (stateful): replay the horizon hour by hour as in V1, but gate charge/discharge decisions on those global thresholds instead of a local rolling average, and dispatch using the *actual* PV/consumption values (the safety margin only shapes which hours get selected, not the physical quantities moved).

This is a "merit order" heuristic, not a real optimizer — documented limitations and what a production version would need instead (rolling MPC re-planning, or an actual MILP solve for the SOC-coupling case) are spelled out in the class docblock.

**Proven difference, not just asserted**: `AdvancedArbitrageEngineTest` builds a price series with a moderate bump early (which triggers V1's local rule) and the real daily peak much later. V1 discharges early and has nothing left for the real peak; V2 recognizes the peak is the better opportunity globally and reserves capacity for it — **12.5% lower total cost** on that scenario.

### A real bug found along the way

While building the round-trip persistence tests, `PriceSeries::priceAt()` started throwing "price not found" for a price that was clearly in the series. Root cause: it indexed points by `$hour->format(DATE_ATOM)` — an ISO-8601 string *with UTC offset*. Two `DateTimeImmutable` instances representing the **exact same instant** but constructed in different timezones (e.g. a contract built in `Europe/Paris`, reloaded from the database in `UTC`) produce different `DATE_ATOM` strings and therefore silently fail to match. Fixed by indexing on `getTimestamp()` (timezone-agnostic Unix time) instead, and normalizing every timestamp to UTC at the Eloquent persistence boundary (see the mapper docblocks). `PriceSeriesTest::test_price_at_matches_the_same_instant_regardless_of_the_timezone_object_used` is a permanent regression test for exactly this.

## API

| Method | Endpoint | Purpose |
|---|---|---|
| `POST` | `/api/simulate` | Run a simulation (V1 or V2) and persist the resulting plan. |
| `GET` | `/api/plans/{id}` | Retrieve a previously computed plan. |
| `GET` | `/api/plans/{id}/chart-data` | Same plan, reshaped into parallel per-hour series (price, PV, consumption, battery, cumulative cost) — ready to feed a charting library. |
| `GET` | `/api/prices` | Inspect raw market prices for a zone/window, without running a simulation. |

### `POST /api/simulate`

Only `contract`, `horizon` and `mode` are required — `battery`, `pv`, `consumption`, `price_provider` and `max_export_power_kw` fall back to `config/energy.php` defaults (themselves driven by `.env`), and each of their sub-fields can be overridden individually.

```jsonc
POST /api/simulate
{
  "contract": {
    "name": "Demo HP/HC",
    "country_code": "FR",
    "zone": "FR",
    "contract_type": "peak_off_peak",
    "pricing_config": {
      "off_peak_slots": [{ "start": "22:00", "end": "06:00" }],
      "seasons": [{
        "label": "year_round",
        "months": [1,2,3,4,5,6,7,8,9,10,11,12],
        "rates": [
          { "slot": "peak", "price_per_kwh": 0.27 },
          { "slot": "off_peak", "price_per_kwh": 0.20 }
        ]
      }]
    }
  },
  "horizon": {
    "start": "2026-07-20T00:00:00+02:00",
    "end": "2026-07-21T00:00:00+02:00",
    "timezone": "Europe/Paris"
  },
  "mode": "simple",
  "pv": { "peak_power_kwc": 6 },
  "battery": { "capacity_kwh": 10, "initial_soc_percent": 50 }
}
```

```jsonc
// 201 Created
{
  "data": {
    "id": "01kxtcd43zm8mbc2bxqj6xqxtv",
    "zone": "FR",
    "mode": "simple",
    "totals": {
      "cost_eur": -7.4768,
      "consumption_kwh": 15,
      "pv_production_kwh": 53.2515,
      "export_kwh": 30.6702
    },
    "hours": [
      {
        "hour_index": 0,
        "starts_at": "2026-07-19T22:00:00+00:00",
        "price_eur_per_kwh": 0.2,
        "consumption_kwh": 0.3141,
        "pv_production_kwh": 0,
        "consumption_from_grid_kwh": 0.3141,
        "consumption_from_pv_kwh": 0,
        "consumption_from_battery_kwh": 0,
        "battery_charge_kwh": 0,
        "battery_discharge_kwh": 0,
        "export_to_grid_kwh": 0,
        "soc_end_of_hour_kwh": 5,
        "cost_eur": 0.062827
      }
      // ... 23 more hours
    ]
  }
}
```

Contract-type-specific `pricing_config` shapes (`fixed`, `peak_off_peak`, `tempo`, `dynamic_spot`) are documented in `App\Domain\Contract\PricingStrategy\PricingStrategyFactory`. Invalid combinations are rejected with a `422` and a field-level message — both at the HTTP validation layer (`SimulateRequest`) and, as a safety net, via a global mapping of `DomainException` → `422` (see `bootstrap/app.php`) for business invariants that can't be expressed as simple Laravel validation rules (e.g. `soc_min_percent < soc_max_percent`).

## Running locally

### Docker Compose (recommended)

```bash
git clone <repo> electricity-planning-engine
cd electricity-planning-engine
docker compose up -d --build
```

This builds the app image, starts PostgreSQL + Redis, waits for Postgres to be healthy, runs migrations, and serves the API on `http://localhost:8000`. Try it:

```bash
curl -X POST http://localhost:8000/api/simulate \
  -H "Content-Type: application/json" \
  -d '{
    "contract": {"country_code":"FR","zone":"FR","contract_type":"fixed","pricing_config":{"price_per_kwh":0.20}},
    "horizon": {"start":"2026-07-20T00:00:00+02:00","end":"2026-07-21T00:00:00+02:00"},
    "mode": "simple"
  }'
```

> **Note on `php artisan serve` in Docker:** the compose file deliberately runs PHP's built-in server directly (`php -S`) rather than `php artisan serve`. Laravel's `ServeCommand` reconstructs its subprocess's environment by re-reading `.env` from disk rather than inheriting the parent process's environment, which silently ignores the `DB_HOST=pgsql` override set in `docker-compose.yml` and reconnects to the `.env` file's own default (`127.0.0.1`, meant for native/host execution) — a real issue hit and fixed while building this project, not a theoretical one.

### Native (PHP 8.2+ / Composer)

```bash
composer install
cp .env.example .env
php artisan key:generate
# Point DB_* at a running PostgreSQL instance (or DB_CONNECTION=sqlite for a quick spin)
php artisan migrate
php artisan serve
```

### Tests

```bash
php artisan test
# or, for coverage-style output:
vendor/bin/phpunit
```

42 tests / 580 assertions: pure-PHP unit tests for the domain and both arbitrage engines (no framework bootstrap, no database), plus Feature tests hitting the real HTTP layer against an in-memory SQLite database (`RefreshDatabase`, configured in `phpunit.xml`).

## Configuration

Everything project-specific lives in `config/energy.php`, driven by `.env` (see `.env.example` for the full list): active price provider (`mock` / `entsoe`), price cache TTL, default battery/PV/consumption/export assumptions, and V1/V2 tuning knobs (lookahead/lookbehind hours, forecast safety margins).

`ENERGY_PRICE_PROVIDER=mock` (the default) needs no external account and produces a realistic duck-curve price shape — including negative prices around midday, by design, to exercise that code path without any setup. Switching to `ENERGY_PRICE_PROVIDER=entsoe` talks to the real [ENTSO-E Transparency Platform](https://transparency.entsoe.eu/) day-ahead price API (document type A44) once `ENTSOE_API_TOKEN` is set; without a token it falls back to a synthetic document in the *same real XML schema*, so the parsing code path is exercised either way.

## Improvement ideas

Documented here rather than glossed over, because knowing what's *not* done — and why — is part of the deliverable:

- **Real ENTSO-E integration**: the request/response plumbing and XML parsing (A44 documents) are real; a production version would add retry/backoff, handle multi-resolution (15-min) series end to end, and cover more bidding zones than the handful of EIC codes currently hard-coded.
- **A real optimizer for V2**: the current "merit order" heuristic is exact for an unconstrained price-arbitrage problem but only a heuristic once power limits and SOC time-coupling bind hard. A proper next step is a rolling-horizon MPC (re-plan every hour with the real battery state) or a MILP formulation (charge/discharge per hour as decision variables, SOC dynamics and power/SOC bounds as constraints) solved via an external solver (e.g. HiGHS, GLPK) or a PHP MILP binding.
- **Home Assistant export**: exposing a computed plan as MQTT topics or via a webhook so a real home-automation setup could act on it (`input_select`/`schedule` style) is a natural bonus not yet built.
- **Scalability**: the use case is fully synchronous today. Under real load, `POST /api/simulate` becomes a queue job (Redis + Horizon, already configured as the default queue/cache driver) so the HTTP request returns immediately with a plan id and the client polls or gets notified; price series are already cached (`CachingPriceProvider` + `price_points` table) to avoid re-hitting ENTSO-E per request; Octane would remove per-request framework bootstrap cost for the hot path.
- **Multi-tenant contracts**: `EnergyContract` is created fresh on every `/api/simulate` call by design (see `EnergyContractRepositoryInterface` docblock) — deliberately simple for a demo. A real product would let users manage saved contracts and reference them by id.
