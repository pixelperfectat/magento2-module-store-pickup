<?php declare(strict_types=1);

namespace PixelPerfect\StorePickup\Setup\Patch\Data;

use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\StatusFactory;
use Magento\Sales\Model\ResourceModel\Order\Status as StatusResource;
use PixelPerfect\StorePickup\Api\Service\ReadyForPickupServiceInterface;

/**
 * Adds the order status "Ready for Pickup", assigned to state processing (not default, visible on the storefront).
 */
class AddReadyForPickupStatus implements DataPatchInterface
{
    /**
     * Constructor.
     *
     * @param StatusFactory $statusFactory
     * @param StatusResource $statusResource
     */
    public function __construct(
        private readonly StatusFactory $statusFactory,
        private readonly StatusResource $statusResource
    ) {
    }

    /**
     * @inheritDoc
     */
    public function apply(): self
    {
        $status = $this->statusFactory->create();
        $this->statusResource->load($status, ReadyForPickupServiceInterface::STATUS);
        if (!$status->getStatus()) {
            $status->setData([
                'status' => ReadyForPickupServiceInterface::STATUS,
                'label' => 'Ready for Pickup',
            ]);
            $this->statusResource->save($status);
        }
        $this->statusResource->assignState(
            ReadyForPickupServiceInterface::STATUS,
            Order::STATE_PROCESSING,
            false,
            true
        );

        return $this;
    }

    /**
     * @inheritDoc
     */
    public static function getDependencies(): array
    {
        return [];
    }

    /**
     * @inheritDoc
     */
    public function getAliases(): array
    {
        return [];
    }
}
