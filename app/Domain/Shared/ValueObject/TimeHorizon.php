<?php

declare(strict_types=1);

namespace App\Domain\Shared\ValueObject;

use App\Domain\Shared\Exception\DomainException;
use DateTimeImmutable;
use Generator;

/**
 * Fenêtre de simulation discrétisée à l'heure : [start, end), bornes
 * incluse/exclue, exprimée en nombre entier d'heures. Le moteur d'arbitrage
 * raisonne exclusivement en "heure d'indice N depuis le début de
 * l'horizon" (`hourIndex`) plutôt qu'en dates absolues, ce qui simplifie
 * les algorithmes de lookahead/lookbehind de la V2.
 */
final class TimeHorizon
{
    private readonly DateTimeImmutable $start;

    private readonly DateTimeImmutable $end;

    private readonly int $hoursCount;

    private function __construct(DateTimeImmutable $start, DateTimeImmutable $end)
    {
        if ($end <= $start) {
            throw DomainException::because('Horizon end must be strictly after horizon start.');
        }

        $diffSeconds = $end->getTimestamp() - $start->getTimestamp();

        if ($diffSeconds % 3600 !== 0) {
            throw DomainException::because(
                'Horizon duration must be a whole number of hours.'
            );
        }

        $this->start = $start;
        $this->end = $end;
        $this->hoursCount = intdiv($diffSeconds, 3600);
    }

    public static function between(DateTimeImmutable $start, DateTimeImmutable $end): self
    {
        return new self($start, $end);
    }

    public static function hoursFrom(DateTimeImmutable $start, int $hours): self
    {
        if ($hours < 1) {
            throw DomainException::because('Horizon must span at least one hour.');
        }

        return new self($start, $start->modify("+{$hours} hours"));
    }

    public function start(): DateTimeImmutable
    {
        return $this->start;
    }

    public function end(): DateTimeImmutable
    {
        return $this->end;
    }

    public function hoursCount(): int
    {
        return $this->hoursCount;
    }

    public function hourAt(int $hourIndex): DateTimeImmutable
    {
        if ($hourIndex < 0 || $hourIndex >= $this->hoursCount) {
            throw DomainException::because(
                "Hour index {$hourIndex} is out of range [0, {$this->hoursCount})."
            );
        }

        return $this->start->modify("+{$hourIndex} hours");
    }

    public function contains(DateTimeImmutable $moment): bool
    {
        return $moment >= $this->start && $moment < $this->end;
    }

    /**
     * @return Generator<int, DateTimeImmutable>
     */
    public function iterateHours(): Generator
    {
        for ($i = 0; $i < $this->hoursCount; $i++) {
            yield $i => $this->hourAt($i);
        }
    }
}
