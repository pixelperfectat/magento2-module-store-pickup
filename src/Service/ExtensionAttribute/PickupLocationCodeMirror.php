<?php declare(strict_types=1);

namespace PixelPerfect\StorePickup\Service\ExtensionAttribute;

use Magento\Framework\Api\ExtensibleDataInterface;
use Magento\Framework\Api\ExtensionAttributesFactory;
use Magento\Framework\DataObject;

/**
 * Mirrors the pp_pickup_location_code column to the extension attribute on load and back on save.
 *
 * The value mirrored at load time is remembered on the entity in a data key that has no column, so a newer
 * column value set by code is not overwritten by a stale extension attribute on save.
 *
 * @internal
 */
class PickupLocationCodeMirror
{
    public const string KEY = 'pp_pickup_location_code';
    public const string MIRRORED_KEY = '_pp_pickup_location_code_mirrored';

    /**
     * Constructor.
     *
     * @param ExtensionAttributesFactory $extensionAttributesFactory
     */
    public function __construct(
        private readonly ExtensionAttributesFactory $extensionAttributesFactory
    ) {
    }

    /**
     * Copy the column value onto the extension attributes and remember it.
     *
     * @param ExtensibleDataInterface $entity
     * @param string $extensibleClass interface the extension attributes belong to
     * @return void
     */
    public function mirror(ExtensibleDataInterface $entity, string $extensibleClass): void
    {
        if (!$entity instanceof DataObject) {
            return;
        }
        // @phpstan-ignore-next-line the entity has getExtensionAttributes
        $extension = $entity->getExtensionAttributes()
            ?? $this->extensionAttributesFactory->create($extensibleClass);
        $code = $entity->getData(self::KEY);
        // @phpstan-ignore-next-line the extension class is generated
        $extension->setPpPickupLocationCode($code);
        // @phpstan-ignore-next-line the entity has setExtensionAttributes
        $entity->setExtensionAttributes($extension);
        $entity->setData(self::MIRRORED_KEY, $code);
    }

    /**
     * Write the extension attribute back to the column data when it was changed since it was mirrored.
     *
     * @param ExtensibleDataInterface $entity
     * @return void
     */
    public function writeBack(ExtensibleDataInterface $entity): void
    {
        if (!$entity instanceof DataObject) {
            return;
        }
        $extension = $entity->getExtensionAttributes();
        if ($extension === null) {
            return;
        }
        // @phpstan-ignore-next-line the extension class is generated
        $code = $extension->getPpPickupLocationCode();
        if ($entity->hasData(self::MIRRORED_KEY)) {
            if ($code === $entity->getData(self::MIRRORED_KEY)) {
                return;
            }
        } elseif ($code === null) {
            return;
        }
        $entity->setData(self::KEY, $code);
        $entity->setData(self::MIRRORED_KEY, $code);
    }
}
