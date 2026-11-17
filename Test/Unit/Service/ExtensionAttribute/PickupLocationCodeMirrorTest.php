<?php declare(strict_types=1);

namespace PixelPerfect\StorePickup\Test\Unit\Service\ExtensionAttribute;

use Magento\Framework\Api\ExtensionAttributesFactory;
use Magento\Quote\Api\Data\CartInterface;
use PHPUnit\Framework\TestCase;
use PixelPerfect\StorePickup\Service\ExtensionAttribute\PickupLocationCodeMirror;

class PickupLocationCodeMirrorTest extends TestCase
{
    public function testEntityThatIsNoDataObjectIsLeftAlone(): void
    {
        $factory = $this->createMock(ExtensionAttributesFactory::class);
        $factory->expects($this->never())->method('create');
        $entity = $this->createMock(CartInterface::class);
        $entity->expects($this->never())->method('getExtensionAttributes');
        $entity->expects($this->never())->method('setExtensionAttributes');

        $mirror = new PickupLocationCodeMirror($factory);
        $mirror->mirror($entity, CartInterface::class);
        $mirror->writeBack($entity);
    }
}
