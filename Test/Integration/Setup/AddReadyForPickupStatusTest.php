<?php declare(strict_types=1);

namespace PixelPerfect\StorePickup\Test\Integration\Setup;

use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Config as OrderConfig;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;
use PixelPerfect\StorePickup\Setup\Patch\Data\AddReadyForPickupStatus;

/**
 * The status patch adds "Ready for Pickup" to state processing and can run again.
 *
 * A reused sandbox install does not run new data patches, so the test applies the patch itself.
 */
#[DbIsolation(false)]
class AddReadyForPickupStatusTest extends TestCase
{
    public function testAddsTheStatusToStateProcessingAndIsRepeatable(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $objectManager->create(AddReadyForPickupStatus::class)->apply();
        $objectManager->create(AddReadyForPickupStatus::class)->apply();

        $config = $objectManager->create(OrderConfig::class);
        self::assertSame(
            'Ready for Pickup',
            (string) ($config->getStateStatuses(Order::STATE_PROCESSING)['ready_for_pickup'] ?? '')
        );
        self::assertContains('ready_for_pickup', $config->getVisibleOnFrontStatuses());
        self::assertSame(Order::STATE_PROCESSING, $config->getStateDefaultStatus(Order::STATE_PROCESSING));
    }
}
