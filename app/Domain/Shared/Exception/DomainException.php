<?php

declare(strict_types=1);

namespace App\Domain\Shared\Exception;

/**
 * Exception racine pour toute violation de règle métier (invariants des
 * value objects, contraintes physiques des actifs, etc.). Volontairement
 * unique plutôt qu'une hiérarchie riche : le domaine reste petit et les
 * appelants (use cases, Form Requests) distinguent les cas via le message,
 * pas via le type d'exception.
 */
class DomainException extends \RuntimeException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
