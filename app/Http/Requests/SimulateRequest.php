<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Application\Arbitrage\DTO\SimulationRequestData;
use App\Application\Arbitrage\Engine\AdvancedArbitrageEngine;
use App\Application\Arbitrage\Engine\SimpleArbitrageEngine;
use App\Domain\Contract\Enum\ContractType;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Valide le corps de POST /api/simulate et le convertit en
 * SimulationRequestData (le DTO que consomme SimulateArbitrageUseCase).
 * Deux niveaux de validation :
 *  - les `rules()` couvrent la forme générale (types, présence, enums) ;
 *  - `withValidator()` ajoute des vérifications qui dépendent de plusieurs
 *    champs à la fois (durée de l'horizon en heures entières, clés de
 *    pricing_config attendues selon le type de contrat) — trop contextuelles
 *    pour être exprimées comme des règles Laravel simples.
 *
 * Les invariants "purement métier" (ex. socMin < socMax, énergie négative)
 * ne sont volontairement PAS revalidés ici : ils sont déjà garantis par les
 * value objects du domaine, qui lèveront une DomainException si violés —
 * mappée en 422 par le gestionnaire d'exceptions global (bootstrap/app.php).
 * Dupliquer ces règles ici serait de la redondance, pas de la robustesse.
 */
final class SimulateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $contractTypes = array_map(static fn (ContractType $type) => $type->value, ContractType::cases());

        return [
            'contract' => ['required', 'array'],
            'contract.name' => ['nullable', 'string', 'max:255'],
            'contract.country_code' => ['required', 'string', 'size:2'],
            'contract.zone' => ['required', 'string', 'max:32'],
            'contract.contract_type' => ['required', 'string', Rule::in($contractTypes)],
            'contract.pricing_config' => ['required', 'array'],
            'contract.currency' => ['nullable', 'string', 'size:3'],
            'contract.subscribed_power_kva' => ['nullable', 'numeric', 'min:0'],
            'contract.timezone' => ['nullable', 'string', 'timezone'],

            'horizon' => ['required', 'array'],
            'horizon.start' => ['required', 'date'],
            'horizon.end' => ['required', 'date', 'after:horizon.start'],
            'horizon.timezone' => ['nullable', 'string', 'timezone'],

            'mode' => ['required', 'string', Rule::in([SimpleArbitrageEngine::MODE, AdvancedArbitrageEngine::MODE])],
            'price_provider' => ['nullable', 'string', Rule::in(['mock', 'entsoe'])],

            'battery' => ['nullable', 'array'],
            'battery.capacity_kwh' => ['nullable', 'numeric', 'min:0'],
            'battery.max_charge_power_kw' => ['nullable', 'numeric', 'min:0'],
            'battery.max_discharge_power_kw' => ['nullable', 'numeric', 'min:0'],
            'battery.round_trip_efficiency' => ['nullable', 'numeric', 'min:0.01', 'max:1'],
            'battery.soc_min_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'battery.soc_max_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'battery.initial_soc_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],

            'pv' => ['nullable', 'array'],
            'pv.peak_power_kwc' => ['nullable', 'numeric', 'min:0'],
            'pv.hourly_profile_kwh' => ['nullable', 'array', 'min:1'],
            'pv.hourly_profile_kwh.*' => ['numeric', 'min:0'],

            'consumption' => ['nullable', 'array'],
            'consumption.daily_baseline_kwh' => ['nullable', 'numeric', 'min:0'],
            'consumption.hourly_profile_kwh' => ['nullable', 'array', 'min:1'],
            'consumption.hourly_profile_kwh.*' => ['numeric', 'min:0'],

            'max_export_power_kw' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    public function withValidator(ValidatorContract $validator): void
    {
        $validator->after(function (ValidatorContract $validator) {
            $this->validateHorizonIsWholeHours($validator);
            $this->validatePricingConfigShape($validator);
        });
    }

    private function validateHorizonIsWholeHours(ValidatorContract $validator): void
    {
        $start = $this->input('horizon.start');
        $end = $this->input('horizon.end');

        if (! is_string($start) || ! is_string($end)) {
            return; // déjà signalé par les règles "required"/"date"
        }

        try {
            $startsAt = new DateTimeImmutable($start);
            $endsAt = new DateTimeImmutable($end);
        } catch (\Exception) {
            return; // déjà signalé par la règle "date"
        }

        $diffSeconds = $endsAt->getTimestamp() - $startsAt->getTimestamp();

        if ($diffSeconds % 3600 !== 0) {
            $validator->errors()->add('horizon.end', 'The horizon duration must be a whole number of hours.');
        }

        if ($diffSeconds > 168 * 3600) {
            $validator->errors()->add('horizon.end', 'The horizon cannot exceed 168 hours (7 days).');
        }
    }

    private function validatePricingConfigShape(ValidatorContract $validator): void
    {
        $type = $this->input('contract.contract_type');
        $config = $this->input('contract.pricing_config');

        if (! is_array($config)) {
            return; // déjà signalé par la règle "array"
        }

        $requiredKeys = match ($type) {
            ContractType::Fixed->value => ['price_per_kwh'],
            ContractType::PeakOffPeak->value => ['off_peak_slots', 'seasons'],
            ContractType::Tempo->value => ['off_peak_slots', 'rates'],
            ContractType::DynamicSpot->value => ['supplier_fee_per_kwh'],
            default => [],
        };

        foreach ($requiredKeys as $key) {
            if (! array_key_exists($key, $config)) {
                $validator->errors()->add(
                    "contract.pricing_config.{$key}",
                    "The pricing_config.{$key} field is required for a '{$type}' contract."
                );
            }
        }
    }

    public function toDto(): SimulationRequestData
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();

        $timezoneName = $validated['horizon']['timezone'] ?? ($validated['contract']['timezone'] ?? 'Europe/Paris');
        $timezone = new DateTimeZone($timezoneName);

        return new SimulationRequestData(
            contract: $validated['contract'],
            // Si la chaîne ISO contient déjà un offset explicite, PHP le
            // respecte et ignore ce second paramètre ; il ne sert que de
            // fuseau par défaut pour une chaîne naïve (ex. "2026-07-20 00:00:00").
            horizonStart: new DateTimeImmutable($validated['horizon']['start'], $timezone),
            horizonEnd: new DateTimeImmutable($validated['horizon']['end'], $timezone),
            timezone: $timezoneName,
            mode: $validated['mode'],
            priceProvider: $validated['price_provider'] ?? null,
            battery: $validated['battery'] ?? null,
            pv: $validated['pv'] ?? null,
            consumption: $validated['consumption'] ?? null,
            maxExportPowerKw: isset($validated['max_export_power_kw']) ? (float) $validated['max_export_power_kw'] : null,
        );
    }
}
