<?php declare(strict_types=1);

namespace PixelPerfect\StorePickup\Model\Data;

use PixelPerfect\StorePickup\Api\Data\PickupLocationInterface;

class PickupLocation implements PickupLocationInterface
{
    /**
     * Constructor.
     *
     * @param string $code
     * @param string $name
     * @param list<string> $street
     * @param string $postcode
     * @param string $city
     * @param string $countryId
     * @param ?int $regionId
     * @param ?string $region
     * @param ?string $telephone
     * @param ?string $openingHours
     * @param ?string $note
     * @param ?float $price
     * @param int $sortOrder
     * @param bool $active
     * @param ?string $stockBinding
     */
    public function __construct(
        private readonly string $code,
        private readonly string $name,
        private readonly array $street,
        private readonly string $postcode,
        private readonly string $city,
        private readonly string $countryId,
        private readonly ?int $regionId = null,
        private readonly ?string $region = null,
        private readonly ?string $telephone = null,
        private readonly ?string $openingHours = null,
        private readonly ?string $note = null,
        private readonly ?float $price = null,
        private readonly int $sortOrder = 0,
        private readonly bool $active = true,
        private readonly ?string $stockBinding = null
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getCode(): string
    {
        return $this->code;
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @inheritDoc
     */
    public function getStreet(): array
    {
        return $this->street;
    }

    /**
     * @inheritDoc
     */
    public function getPostcode(): string
    {
        return $this->postcode;
    }

    /**
     * @inheritDoc
     */
    public function getCity(): string
    {
        return $this->city;
    }

    /**
     * @inheritDoc
     */
    public function getCountryId(): string
    {
        return $this->countryId;
    }

    /**
     * @inheritDoc
     */
    public function getRegionId(): ?int
    {
        return $this->regionId;
    }

    /**
     * @inheritDoc
     */
    public function getRegion(): ?string
    {
        return $this->region;
    }

    /**
     * @inheritDoc
     */
    public function getTelephone(): ?string
    {
        return $this->telephone;
    }

    /**
     * @inheritDoc
     */
    public function getOpeningHours(): ?string
    {
        return $this->openingHours;
    }

    /**
     * @inheritDoc
     */
    public function getNote(): ?string
    {
        return $this->note;
    }

    /**
     * @inheritDoc
     */
    public function getPrice(): ?float
    {
        return $this->price;
    }

    /**
     * @inheritDoc
     */
    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    /**
     * @inheritDoc
     */
    public function isActive(): bool
    {
        return $this->active;
    }

    /**
     * @inheritDoc
     */
    public function getStockBinding(): ?string
    {
        return $this->stockBinding;
    }
}
