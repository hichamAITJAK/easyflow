<?php

namespace App\DTOs\Coliix;

/**
 * Represents the result of a Coliix "add" call:
 *
 * {"status": 200, "msg": "ajouté avec succès", "tracking": "code denvoi"}
 *
 * Coliix's add-parcel response carries no cost/receiver/address fields back
 * (unlike Sendit/OzonExpress) — only the tracking code and a status message
 * — so deliveryCost/returnedCost/refusedCost are always null here.
 *
 * trackingNumber/deliveryCost/returnedCost/refusedCost follow the same
 * naming as every other courier's parcel-result DTO (see SenditParcelDTO /
 * OzonExpressParcelDTO), so callers can read those uniformly without caring
 * which concrete courier answered.
 */
readonly class ColiixParcelDTO
{
    public function __construct(
        public string $trackingNumber,
        public ?string $message,
        public ?float $deliveryCost = null,
        public ?float $returnedCost = null,
        public ?float $refusedCost = null,
    ) {}

    /**
     * @param  array{tracking?: string|null, msg?: string|null}  $response
     */
    public static function fromArray(array $response): self
    {
        return new self(
            trackingNumber: (string) ($response['tracking'] ?? ''),
            message: $response['msg'] ?? null,
        );
    }

    /**
     * @return array{tracking: string, msg: string|null}
     */
    public function toArray(): array
    {
        return [
            'tracking' => $this->trackingNumber,
            'msg' => $this->message,
        ];
    }
}
