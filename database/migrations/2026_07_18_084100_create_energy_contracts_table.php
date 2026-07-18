<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('energy_contracts', function (Blueprint $table) {
            $table->id();

            $table->string('name');
            // ISO 3166-1 alpha-2 (FR, BE, DE, ES...) : permet de brancher des
            // règles tarifaires et des price providers différents par pays.
            $table->string('country_code', 2);
            // Zone de marché (bidding zone), ex. "FR", "DE_LU", "BE" — utilisée
            // pour interroger les price providers (PriceProviderInterface).
            $table->string('zone');
            // fixed | peak_off_peak | tempo | dynamic_spot (cf. Domain\Contract\Enum\ContractType).
            $table->string('contract_type');
            $table->char('currency', 3)->default('EUR');

            // Configuration spécifique à la stratégie tarifaire (plages horaires
            // HP/HC, tarifs saisonniers, règles jours Tempo, etc.). Ce contenu est
            // hétérogène par nature selon le contract_type et le pays : plutôt que
            // de multiplier les tables de jointure (time_slots, seasonal_rates,
            // special_days...) pour un moteur de règles qui reste simple, on le
            // modélise en JSON structuré et on délègue sa validation/hydratation
            // à App\Domain\Contract\PricingStrategy\*::fromConfig(). Compromis
            // assumé : moins "relationnel", mais un schéma qui n'a pas besoin de
            // migration à chaque nouveau type de contrat/pays.
            $table->jsonb('pricing_config');

            // Puissance souscrite (kVA), pertinente pour les contrats français
            // (impacte les pénalités de dépassement, non modélisées en V1).
            $table->decimal('subscribed_power_kva', 6, 2)->nullable();

            $table->string('timezone')->default('Europe/Paris');
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index('zone');
            $table->index('contract_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('energy_contracts');
    }
};
