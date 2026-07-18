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
        Schema::create('price_points', function (Blueprint $table) {
            $table->id();

            // Zone de marché (bidding zone) à laquelle ce prix s'applique.
            $table->string('zone');
            // Provider ayant produit ce point : "mock", "entsoe", "epex"...
            // Permet de faire cohabiter plusieurs sources pour une même zone.
            $table->string('source');

            // Début de l'heure (ou du pas de temps) concernée, en UTC.
            $table->timestampTz('timestamp');
            // Résolution du pas de temps en minutes (60 par défaut ; certaines
            // zones ENTSO-E publient du 15 min).
            $table->unsignedSmallInteger('resolution_minutes')->default(60);

            // Prix spot, en EUR/MWh. decimal(10,3) autorise des prix fortement
            // négatifs (surproduction EnR) sans perte de précision.
            $table->decimal('price_eur_per_mwh', 10, 3);
            $table->char('currency', 3)->default('EUR');

            // Horodatage de récupération/ingestion, utile pour le cache et
            // pour distinguer un prix day-ahead définitif d'un prix révisé.
            $table->timestampTz('retrieved_at')->nullable();

            $table->timestamps();

            // Un seul prix par zone/source/instant : évite les doublons lors
            // de ré-ingestions répétées du même provider.
            $table->unique(['zone', 'source', 'timestamp']);
            // Index principal pour les requêtes "prix d'une zone sur un horizon".
            $table->index(['zone', 'timestamp']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('price_points');
    }
};
