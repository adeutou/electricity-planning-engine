<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Active price provider
    |--------------------------------------------------------------------------
    |
    | Nom logique du provider utilisé par défaut lorsqu'une requête de
    | simulation ne précise pas explicitement "price_provider". La classe
    | App\Infrastructure\PriceProvider\PriceProviderFactory lit cette clé
    | pour résoudre l'implémentation concrète (mock, entsoe, ...) derrière
    | l'interface App\Domain\Pricing\PriceProviderInterface.
    |
    | Supported: "mock", "entsoe"
    |
    */

    'price_provider' => env('ENERGY_PRICE_PROVIDER', 'mock'),

    /*
    |--------------------------------------------------------------------------
    | Zone tarifaire par défaut
    |--------------------------------------------------------------------------
    |
    | Code de zone de marché (ex. "FR", "BE", "DE_LU", "ES") utilisé quand
    | le contrat ou la requête ne précise pas de zone explicite. Permet de
    | garder le moteur d'arbitrage agnostique du pays tout en offrant une
    | valeur par défaut sensée pour les démos locales.
    |
    */

    'default_zone' => env('ENERGY_DEFAULT_ZONE', 'FR'),

    /*
    |--------------------------------------------------------------------------
    | Cache des séries de prix
    |--------------------------------------------------------------------------
    |
    | Les prix day-ahead (ENTSO-E, EPEX...) sont publiés une fois par jour
    | et coûteux à récupérer (rate limiting, latence réseau). On les met en
    | cache (driver Redis en prod) par zone + horizon pour éviter de
    | re-solliciter le provider à chaque simulation.
    |
    */

    'price_cache_ttl' => (int) env('ENERGY_PRICE_CACHE_TTL', 3600),

    /*
    |--------------------------------------------------------------------------
    | Provider ENTSO-E
    |--------------------------------------------------------------------------
    |
    | Configuration du client HTTP pour brancher une vraie source de prix
    | day-ahead. Voir App\Infrastructure\PriceProvider\EntsoEPriceProvider
    | pour le détail du mapping XML (document A44) -> PriceSeries du domaine.
    |
    */

    'entsoe' => [
        'base_url' => env('ENTSOE_API_BASE_URL', 'https://web-api.tp.entsoe.eu/api'),
        'api_token' => env('ENTSOE_API_TOKEN'),
        'timeout' => (int) env('ENTSOE_API_TIMEOUT', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Valeurs par défaut des actifs pilotables
    |--------------------------------------------------------------------------
    |
    | Utilisées par SimulateArbitrageUseCase quand une requête /api/simulate
    | ne fournit pas explicitement les caractéristiques de la batterie, du
    | système PV ou de la puissance d'export max. Elles décrivent un foyer
    | résidentiel type (batterie 10 kWh, PV 6 kWc) et servent de fixtures
    | pour les démos et les tests.
    |
    */

    'battery' => [
        'capacity_kwh' => (float) env('ENERGY_BATTERY_CAPACITY_KWH', 10.0),
        'max_charge_power_kw' => (float) env('ENERGY_BATTERY_MAX_POWER_KW', 5.0),
        'max_discharge_power_kw' => (float) env('ENERGY_BATTERY_MAX_POWER_KW', 5.0),
        'round_trip_efficiency' => (float) env('ENERGY_BATTERY_ROUND_TRIP_EFFICIENCY', 0.90),
        'soc_min_percent' => (float) env('ENERGY_BATTERY_SOC_MIN_PERCENT', 10.0),
        'soc_max_percent' => (float) env('ENERGY_BATTERY_SOC_MAX_PERCENT', 100.0),
        'initial_soc_percent' => (float) env('ENERGY_BATTERY_INITIAL_SOC_PERCENT', 50.0),
    ],

    'pv' => [
        'peak_power_kwc' => (float) env('ENERGY_PV_PEAK_POWER_KWC', 6.0),
    ],

    'grid' => [
        // Puissance maximale de réinjection autorisée vers le réseau (kW).
        // Contrainte réglementaire/contractuelle typique (ex. Enedis).
        'max_export_power_kw' => (float) env('ENERGY_MAX_EXPORT_POWER_KW', 3.0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Moteur d'arbitrage
    |--------------------------------------------------------------------------
    |
    | Paramètres partagés par les moteurs V1 (simple) et V2 (advanced).
    | "lookahead_hours" borne la fenêtre glissante utilisée par le moteur
    | avancé pour anticiper les heures de prix bas/haut.
    |
    */

    'arbitrage' => [
        'default_mode' => env('ENERGY_ARBITRAGE_DEFAULT_MODE', 'simple'),
        'lookahead_hours' => (int) env('ENERGY_ARBITRAGE_LOOKAHEAD_HOURS', 6),
        'lookbehind_hours' => (int) env('ENERGY_ARBITRAGE_LOOKBEHIND_HOURS', 6),
    ],

    /*
    |--------------------------------------------------------------------------
    | Intégration Home Assistant (bonus)
    |--------------------------------------------------------------------------
    |
    | Permet d'exporter un ArbitragePlan calculé vers une instance Home
    | Assistant via un webhook entrant. Voir
    | App\Infrastructure\HomeAssistant\HomeAssistantPlanExporter.
    |
    */

    'home_assistant' => [
        'webhook_url' => env('HOME_ASSISTANT_WEBHOOK_URL'),
        'long_lived_token' => env('HOME_ASSISTANT_LONG_LIVED_TOKEN'),
    ],

];
