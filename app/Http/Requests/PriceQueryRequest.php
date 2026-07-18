<?php

declare(strict_types=1);

namespace App\Http\Requests;

use DateTimeImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Valide la query string de GET /api/prices.
 */
final class PriceQueryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'zone' => ['required', 'string', 'max:32'],
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after:from'],
            'provider' => ['nullable', 'string', Rule::in(['mock', 'entsoe'])],
        ];
    }

    public function zone(): string
    {
        return (string) $this->validated('zone');
    }

    public function fromDate(): DateTimeImmutable
    {
        return new DateTimeImmutable((string) $this->validated('from'));
    }

    public function toDate(): DateTimeImmutable
    {
        return new DateTimeImmutable((string) $this->validated('to'));
    }

    public function provider(): ?string
    {
        return $this->validated('provider');
    }
}
