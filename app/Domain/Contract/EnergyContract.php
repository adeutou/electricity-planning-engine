<?php

declare(strict_types=1);

namespace App\Domain\Contract;

use App\Domain\Contract\Enum\ContractType;
use App\Domain\Contract\PricingStrategy\PricingStrategyInterface;
use App\Domain\Pricing\PriceSeries;
use App\Domain\Shared\ValueObject\Money;
use DateTimeImmutable;

/**
 * Contrat énergétique d'un foyer/PME. L'entité ne connaît pas le détail des
 * règles tarifaires : elle délègue entièrement à sa PricingStrategyInterface
 * (Strategy pattern), ce qui lui permet de représenter indifféremment un
 * contrat français HP/HC, un contrat Tempo, ou un tarif spot néerlandais
 * sans aucune branche conditionnelle ici.
 */
final class EnergyContract
{
    public function __construct(
        private readonly string $name,
        private readonly string $countryCode,
        private readonly string $zone,
        private readonly ContractType $type,
        private readonly PricingStrategyInterface $pricingStrategy,
        private readonly string $currency = 'EUR',
        private readonly ?float $subscribedPowerKva = null,
        private readonly string $timezone = 'Europe/Paris',
    ) {}

    public function priceForHour(DateTimeImmutable $hour, ?PriceSeries $marketPrices = null): Money
    {
        return $this->pricingStrategy->priceForHour($hour, $marketPrices);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function countryCode(): string
    {
        return $this->countryCode;
    }

    public function zone(): string
    {
        return $this->zone;
    }

    public function type(): ContractType
    {
        return $this->type;
    }

    public function currency(): string
    {
        return $this->currency;
    }

    public function subscribedPowerKva(): ?float
    {
        return $this->subscribedPowerKva;
    }

    public function timezone(): string
    {
        return $this->timezone;
    }

    /**
     * Le contrat a-t-il besoin de prix de marché pour être tarifé ? Permet
     * à l'appelant (use case) de savoir s'il doit récupérer une PriceSeries
     * avant d'appeler priceForHour(), plutôt que de le faire systématiquement.
     */
    public function requiresMarketPrices(): bool
    {
        return $this->type === ContractType::DynamicSpot;
    }
}
