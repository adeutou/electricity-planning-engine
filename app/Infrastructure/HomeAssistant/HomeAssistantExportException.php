<?php

declare(strict_types=1);

namespace App\Infrastructure\HomeAssistant;

use RuntimeException;

/**
 * Toute défaillance de l'export vers Home Assistant (configuration
 * manquante, HA injoignable, réponse HTTP en erreur) est enveloppée dans ce
 * type unique plutôt que de laisser fuiter les exceptions du client HTTP —
 * l'API expose une erreur stable, indépendante du mécanisme de transport
 * choisi. Mappée en 503 par le gestionnaire d'exceptions global (voir
 * bootstrap/app.php) : le problème est côté service externe, pas côté
 * requête du client.
 */
final class HomeAssistantExportException extends RuntimeException {}
