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
        Schema::create('simulation_plans', function (Blueprint $table) {
            // ULID plutôt qu'un id auto-incrémenté : identifiant public,
            // triable chronologiquement, sans exposer le volume de plans
            // générés via l'API (GET /api/plans/{id}).
            $table->ulid('id')->primary();

            // Nullable + ON DELETE SET NULL : un plan reste consultable et
            // reproductible même si le contrat source est supprimé, grâce au
            // snapshot stocké dans contract_snapshot ci-dessous.
            $table->foreignId('energy_contract_id')
                ->nullable()
                ->constrained('energy_contracts')
                ->nullOnDelete();

            // simple (V1, greedy) | advanced (V2, lookahead).
            $table->string('mode');
            $table->string('zone');
            $table->string('price_provider');

            $table->timestampTz('horizon_start');
            $table->timestampTz('horizon_end');
            $table->string('timezone')->default('Europe/Paris');

            // Snapshots des entrées effectivement utilisées par le moteur au
            // moment du calcul (contrat, batterie, PV, consommation). Un plan
            // stocké doit rester interprétable même si la config par défaut
            // (config/energy.php) ou le contrat changent ensuite : c'est un
            // enregistrement historique, pas une vue calculée à la volée.
            $table->jsonb('contract_snapshot')->nullable();
            $table->jsonb('battery_config')->nullable();
            $table->jsonb('pv_config')->nullable();
            $table->jsonb('consumption_config')->nullable();

            // Agrégats dénormalisés pour lister/trier les plans sans avoir à
            // recalculer une somme sur plan_hours à chaque requête.
            $table->decimal('total_cost_eur', 12, 4)->default(0);
            $table->decimal('total_consumption_kwh', 12, 4)->default(0);
            $table->decimal('total_pv_production_kwh', 12, 4)->default(0);
            $table->decimal('total_export_kwh', 12, 4)->default(0);

            // Informations libres propres au moteur (ex. scénarios explorés
            // par AdvancedArbitrageEngine, hypothèses d'incertitude retenues).
            $table->jsonb('metadata')->nullable();

            $table->timestamps();

            $table->index('zone');
            $table->index('mode');
            $table->index('horizon_start');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('simulation_plans');
    }
};
