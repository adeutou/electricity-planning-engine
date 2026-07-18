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
        Schema::create('plan_hours', function (Blueprint $table) {
            // Ligne de détail purement dérivée du calcul du moteur d'arbitrage :
            // un id auto-incrémenté classique suffit, contrairement à
            // simulation_plans qui est l'agrégat exposé publiquement.
            $table->id();

            $table->foreignUlid('simulation_plan_id')
                ->constrained('simulation_plans')
                ->cascadeOnDelete();

            // Position 0-based de l'heure dans l'horizon simulé.
            $table->unsignedSmallInteger('hour_index');
            $table->timestampTz('starts_at');

            $table->decimal('price_eur_per_mwh', 10, 3);

            // Toutes les grandeurs énergétiques en kWh, decimal(10,4) pour
            // rester précis sur de petites fractions (ex. 0.0125 kWh) sans
            // dérive d'arrondi flottant au fil de l'horizon.
            $table->decimal('consumption_kwh', 10, 4);
            $table->decimal('pv_production_kwh', 10, 4);
            $table->decimal('consumption_from_grid_kwh', 10, 4);
            $table->decimal('consumption_from_pv_kwh', 10, 4);
            $table->decimal('consumption_from_battery_kwh', 10, 4);
            $table->decimal('battery_charge_kwh', 10, 4);
            $table->decimal('battery_discharge_kwh', 10, 4);
            $table->decimal('export_to_grid_kwh', 10, 4);
            $table->decimal('soc_end_of_hour_kwh', 10, 4);

            // Coût de l'heure en euros ; peut être négatif (revenu net) en
            // cas d'export pendant une heure à prix spot négatif.
            $table->decimal('cost_eur', 12, 6);

            // Pas de timestamps() : ligne immuable, créée une seule fois avec
            // le plan parent (qui porte déjà created_at/updated_at).

            $table->unique(['simulation_plan_id', 'hour_index']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('plan_hours');
    }
};
