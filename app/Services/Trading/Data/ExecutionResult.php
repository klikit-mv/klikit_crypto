<?php

namespace App\Services\Trading\Data;

/**
 * What a venue reported back for an order: its status and any fills since submission.
 */
final readonly class ExecutionResult
{
    /**
     * @param  list<Fill>  $fills
     */
    public function __construct(
        public string $status, // open, partially_filled, filled, cancelled, rejected
        public array $fills = [],
        public ?string $externalOrderId = null,
        public ?string $message = null,
    ) {}

    public static function open(?string $externalOrderId = null): self
    {
        return new self('open', [], $externalOrderId);
    }

    public static function rejected(string $message): self
    {
        return new self('rejected', [], null, $message);
    }
}
