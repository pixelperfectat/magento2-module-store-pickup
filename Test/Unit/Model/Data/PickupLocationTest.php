<?php declare(strict_types=1);

namespace PixelPerfect\StorePickup\Test\Unit\Model\Data;

use PHPUnit\Framework\TestCase;
use PixelPerfect\StorePickup\Model\Data\PickupLocation;

class PickupLocationTest extends TestCase
{
    public function testExposesAllValues(): void
    {
        $location = new PickupLocation(
            'main',
            'Main Store',
            ['1 Example Street', 'Floor 2'],
            '12345',
            'Sampletown',
            'DE',
            7,
            'Sample Region',
            '+49 000 0000',
            'Mon-Fri 9-17',
            'Ring the bell',
            2.5,
            10,
            false,
            'source_a'
        );

        $this->assertSame('main', $location->getCode());
        $this->assertSame('Main Store', $location->getName());
        $this->assertSame(['1 Example Street', 'Floor 2'], $location->getStreet());
        $this->assertSame('12345', $location->getPostcode());
        $this->assertSame('Sampletown', $location->getCity());
        $this->assertSame('DE', $location->getCountryId());
        $this->assertSame(7, $location->getRegionId());
        $this->assertSame('Sample Region', $location->getRegion());
        $this->assertSame('+49 000 0000', $location->getTelephone());
        $this->assertSame('Mon-Fri 9-17', $location->getOpeningHours());
        $this->assertSame('Ring the bell', $location->getNote());
        $this->assertSame(2.5, $location->getPrice());
        $this->assertSame(10, $location->getSortOrder());
        $this->assertFalse($location->isActive());
        $this->assertSame('source_a', $location->getStockBinding());
    }

    public function testOptionalValuesDefault(): void
    {
        $location = new PickupLocation('main', 'Main Store', ['1 Example Street'], '12345', 'Sampletown', 'DE');

        $this->assertNull($location->getRegionId());
        $this->assertNull($location->getRegion());
        $this->assertNull($location->getTelephone());
        $this->assertNull($location->getOpeningHours());
        $this->assertNull($location->getNote());
        $this->assertNull($location->getPrice());
        $this->assertSame(0, $location->getSortOrder());
        $this->assertTrue($location->isActive());
        $this->assertNull($location->getStockBinding());
    }
}
