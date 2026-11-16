<?php

declare(strict_types=1);

namespace Kawaa\Models;

/**
 * Data enrichment the API attaches to a verification when the request set
 * `enrich` and the plan includes it. Each field is null when the API has no
 * hint for it.
 */
class Enrichment
{
    public function __construct(
        public readonly ?string $firstName = null,
        public readonly ?string $lastName = null,
        public readonly ?string $fullName = null,
        public readonly ?string $gender = null,
        public readonly ?string $country = null,
        public readonly ?string $countryCode = null,
    ) {}

    /**
     * Create from the API's `enrichment` object. A field that is not a string
     * reads as null rather than failing the result.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $text = static fn (string $name): ?string => is_string($data[$name] ?? null) ? $data[$name] : null;

        return new self(
            firstName: $text('first_name'),
            lastName: $text('last_name'),
            fullName: $text('full_name'),
            gender: $text('gender'),
            country: $text('country'),
            countryCode: $text('country_code'),
        );
    }

    /**
     * Convert to array.
     *
     * @return array<string, string|null>
     */
    public function toArray(): array
    {
        return [
            'first_name' => $this->firstName,
            'last_name' => $this->lastName,
            'full_name' => $this->fullName,
            'gender' => $this->gender,
            'country' => $this->country,
            'country_code' => $this->countryCode,
        ];
    }
}
