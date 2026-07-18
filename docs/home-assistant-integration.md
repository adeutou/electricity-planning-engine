# Home Assistant integration (bonus)

Push a computed arbitrage plan to a [Home Assistant](https://www.home-assistant.io/) instance so a real automation can act on it: turn a smart plug on during a cheap/negative-price hour, switch a battery inverter's mode, etc.

This is intentionally a thin slice, not a full HACS integration: one export mechanism (webhook) is implemented end to end; two others (MQTT, REST API with a long-lived token) are documented below as the natural next steps, since the job is really "get *a* real payload into HA reliably," not "support every possible transport."

## How it works

`POST /api/plans/{id}/export/home-assistant` loads a previously computed plan (`SimulationPlanRecord`) and pushes it to the URL configured in `HOME_ASSISTANT_WEBHOOK_URL` via `App\Infrastructure\HomeAssistant\HomeAssistantWebhookExporter`. The exporter builds a JSON payload with:

- `totals`: the same aggregates as `POST /api/simulate`'s response.
- `current_hour`: the plan's hour matching "now" (`null` if the plan's horizon doesn't cover the current instant), with a derived `recommended_action` (`charging` / `discharging` / `exporting` / `idle`). This is the field a real-time automation would react to directly, without having to reparse the full `hours` array.
- `hours`: the full per-hour schedule, for automations that want to look further ahead (e.g. pre-heat a water tank before a known price spike).

Any failure (missing configuration, network error, non-2xx response from HA) is wrapped into a single `HomeAssistantExportException`, mapped to an HTTP `503`: the request itself was valid, the external service wasn't reachable/configured.

## Setup

1. **Create a webhook trigger in Home Assistant**: Settings → Automations & Scenes → Automations → *Create Automation* → *When* → *Webhook*. Home Assistant generates a webhook ID; the resulting URL looks like `http://<ha-host>:8123/api/webhook/<webhook-id>`.
2. **Configure this project**: set `HOME_ASSISTANT_WEBHOOK_URL` in `.env` to that URL.
3. **Push a plan**: after `POST /api/simulate` returns a plan `id`, call:

   ```bash
   curl -X POST http://localhost:8000/api/plans/<id>/export/home-assistant
   ```

4. **React to it in Home Assistant**: the webhook trigger exposes the payload as `trigger.json`. Example automation that turns on a switch while the recommended action is "charging":

   ```yaml
   alias: Electricity plan, drive the battery charger
   trigger:
     - platform: webhook
       webhook_id: <webhook-id>
   condition: []
   action:
     - choose:
         - conditions:
             - condition: template
               value_template: "{{ trigger.json.current_hour.recommended_action == 'charging' }}"
           sequence:
             - service: switch.turn_on
               target:
                 entity_id: switch.battery_charger
         - conditions:
             - condition: template
               value_template: "{{ trigger.json.current_hour.recommended_action == 'discharging' }}"
           sequence:
             - service: switch.turn_on
               target:
                 entity_id: switch.battery_inverter_discharge_mode
       default:
         - service: switch.turn_off
           target:
             entity_id: switch.battery_charger
   mode: single
   ```

   A dashboard card can instead store the full `trigger.json.hours` array (e.g. via an `input_text`/`variable` integration or a small `pyscript`) to render the whole day's plan, not just the current hour.

## Alternatives not implemented (but designed for)

- **MQTT**: instead of (or in addition to) the webhook, publish the same payload to an MQTT broker on a topic like `homeassistant/sensor/electricity_plan/state`, paired with an [MQTT discovery](https://www.home-assistant.io/integrations/mqtt/#mqtt-discovery) config message so Home Assistant auto-creates the sensor. This is the more "native" HA pattern for a fully local setup (no inbound HTTP access to HA required) and would live as a sibling `HomeAssistantMqttExporter implements HomeAssistantExporterInterface`: the port (`Application\Ports\HomeAssistantExporterInterface`) is already transport-agnostic specifically so this doesn't require touching the use case or controller, only `DomainServiceProvider`'s binding.
- **Home Assistant REST API (long-lived token)**: `config/energy.php` already reserves a `HOME_ASSISTANT_LONG_LIVED_TOKEN` setting for this. Rather than a one-shot webhook push, the exporter would `POST` to HA's `/api/states/sensor.electricity_plan_next_action` with a `Bearer` token to directly set sensor state/attributes, closer to how a polling integration would behave, and avoids relying on HA being able to receive the request (webhook direction) versus this app pushing to HA's own API (same direction as any other HA REST client).
