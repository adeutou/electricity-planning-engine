<?php

declare(strict_types=1);

namespace App\Domain\Contract\ValueObject;

use App\Domain\Contract\Enum\TimeSlotType;
use App\Domain\Shared\Exception\DomainException;
use DateTimeImmutable;

/**
 * Plage horaire quotidienne récurrente (ex. "22:00–06:00" pour les heures
 * creuses). Gère nativement le passage à minuit : start > end signifie que
 * la plage traverse minuit, comportement courant pour les heures creuses.
 */
final class TimeSlot
{
    private readonly TimeSlotType $type;

    private readonly int $startMinuteOfDay;

    private readonly int $endMinuteOfDay;

    public function __construct(TimeSlotType $type, string $startTime, string $endTime)
    {
        $this->type = $type;
        $this->startMinuteOfDay = self::toMinuteOfDay($startTime);
        $this->endMinuteOfDay = self::toMinuteOfDay($endTime);

        if ($this->startMinuteOfDay === $this->endMinuteOfDay) {
            throw DomainException::because('A time slot cannot have identical start and end times.');
        }
    }

    public function type(): TimeSlotType
    {
        return $this->type;
    }

    public function contains(DateTimeImmutable $moment): bool
    {
        $minuteOfDay = ((int) $moment->format('H')) * 60 + (int) $moment->format('i');

        if ($this->startMinuteOfDay < $this->endMinuteOfDay) {
            return $minuteOfDay >= $this->startMinuteOfDay && $minuteOfDay < $this->endMinuteOfDay;
        }

        // La plage traverse minuit (ex. 22:00 -> 06:00).
        return $minuteOfDay >= $this->startMinuteOfDay || $minuteOfDay < $this->endMinuteOfDay;
    }

    private static function toMinuteOfDay(string $time): int
    {
        if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $time, $matches) !== 1) {
            throw DomainException::because("Invalid time format '{$time}', expected HH:MM.");
        }

        return ((int) $matches[1]) * 60 + (int) $matches[2];
    }
}
