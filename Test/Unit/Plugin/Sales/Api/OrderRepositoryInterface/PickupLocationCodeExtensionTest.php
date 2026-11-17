<?php declare(strict_types=1);

namespace PixelPerfect\StorePickup\Test\Unit\Plugin\Sales\Api\OrderRepositoryInterface;

use Magento\Framework\Api\ExtensionAttributesFactory;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderSearchResultInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PixelPerfect\StorePickup\Plugin\Sales\Api\OrderRepositoryInterface\PickupLocationCodeExtension;
use PixelPerfect\StorePickup\Service\ExtensionAttribute\PickupLocationCodeMirror;
use PixelPerfect\StorePickup\Test\Stub\ExtensionAttributes;
use Psr\Log\LoggerInterface;

class PickupLocationCodeExtensionTest extends TestCase
{
    /** @var LoggerInterface&MockObject */
    private $logger;

    private PickupLocationCodeExtension $plugin;

    /** @var OrderRepositoryInterface&MockObject */
    private $subject;

    protected function setUp(): void
    {
        $factory = $this->createMock(ExtensionAttributesFactory::class);
        $factory->method('create')->willReturnCallback(static fn () => new ExtensionAttributes());
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->subject = $this->createMock(OrderRepositoryInterface::class);
        $this->plugin = new PickupLocationCodeExtension(new PickupLocationCodeMirror($factory), $this->logger);
    }

    /**
     * @param string|null $code
     * @param ExtensionAttributes|null $ext
     * @return Order&MockObject
     */
    private function order(?string $code, ?ExtensionAttributes $ext = null)
    {
        $order = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getExtensionAttributes', 'setExtensionAttributes'])
            ->getMock();
        $order->setData('pp_pickup_location_code', $code);
        $holder = new \ArrayObject(['ext' => $ext]);
        $order->method('getExtensionAttributes')->willReturnCallback(static fn () => $holder['ext']);
        $order->method('setExtensionAttributes')->willReturnCallback(
            static function ($value) use ($holder, $order) {
                $holder['ext'] = $value;
                return $order;
            }
        );
        return $order;
    }

    /**
     * @param OrderInterface $order
     * @return ExtensionAttributes|null
     */
    private function ext(OrderInterface $order): ?ExtensionAttributes
    {
        $ext = $order->getExtensionAttributes();
        return $ext instanceof ExtensionAttributes ? $ext : null;
    }

    public function testAfterGetMirrorsDataToExtensionAttribute(): void
    {
        $order = $this->order('main');

        $this->assertSame($order, $this->plugin->afterGet($this->subject, $order));
        $this->assertSame('main', $this->ext($order)?->getPpPickupLocationCode());
    }

    public function testAfterGetKeepsExistingExtensionAttributes(): void
    {
        $existing = new ExtensionAttributes();
        $existing->other = 'keep';
        $order = $this->order('main', $existing);

        $this->plugin->afterGet($this->subject, $order);

        $this->assertSame($existing, $this->ext($order));
        $this->assertSame('keep', $existing->other);
        $this->assertSame('main', $existing->getPpPickupLocationCode());
    }

    public function testAfterGetListMirrorsEveryItem(): void
    {
        $first = $this->order('main');
        $second = $this->order(null);
        $list = $this->createMock(OrderSearchResultInterface::class);
        $list->method('getItems')->willReturn([$first, $second]);

        $this->assertSame($list, $this->plugin->afterGetList($this->subject, $list));
        $this->assertSame('main', $this->ext($first)?->getPpPickupLocationCode());
        $this->assertNull($this->ext($second)?->getPpPickupLocationCode());
    }

    public function testBeforeSaveWritesExtensionAttributeBackToData(): void
    {
        $ext = new ExtensionAttributes();
        $ext->setPpPickupLocationCode('second');
        $order = $this->order('main', $ext);

        $this->assertSame([$order], $this->plugin->beforeSave($this->subject, $order));
        $this->assertSame('second', $order->getData('pp_pickup_location_code'));
    }

    public function testNullOnBothSidesStaysNull(): void
    {
        $order = $this->order(null, new ExtensionAttributes());

        $this->plugin->beforeSave($this->subject, $order);

        $this->assertNull($order->getData('pp_pickup_location_code'));
    }

    public function testBeforeSaveKeepsDataWhenExtensionAttributeIsNull(): void
    {
        $order = $this->order('main', new ExtensionAttributes());

        $this->plugin->beforeSave($this->subject, $order);

        $this->assertSame('main', $order->getData('pp_pickup_location_code'));
    }

    public function testFailureIsLoggedAndTheOrderIsStillReturned(): void
    {
        $order = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getExtensionAttributes'])
            ->getMock();
        $order->method('getExtensionAttributes')->willThrowException(new \RuntimeException('boom'));
        $this->logger->expects($this->exactly(2))->method('error')->withConsecutive(
            ['Pickup location code could not be read onto the order: boom'],
            ['Pickup location code could not be written to the order: boom']
        );

        $this->assertSame($order, $this->plugin->afterGet($this->subject, $order));
        $this->assertSame([$order], $this->plugin->beforeSave($this->subject, $order));
    }

    public function testNewerColumnValueSurvivesAStaleMirroredAttribute(): void
    {
        $order = $this->order('main');
        $this->plugin->afterGet($this->subject, $order);
        $order->setData('pp_pickup_location_code', 'second');

        $this->plugin->beforeSave($this->subject, $order);

        $this->assertSame('second', $order->getData('pp_pickup_location_code'));
    }

    public function testAttributeChangedAfterLoadWinsOverTheColumn(): void
    {
        $order = $this->order('main');
        $this->plugin->afterGet($this->subject, $order);
        $this->ext($order)?->setPpPickupLocationCode('second');

        $this->plugin->beforeSave($this->subject, $order);

        $this->assertSame('second', $order->getData('pp_pickup_location_code'));
    }

    public function testAttributeSetToNullAfterLoadClearsTheCode(): void
    {
        $order = $this->order('main');
        $this->plugin->afterGet($this->subject, $order);
        $this->ext($order)?->setPpPickupLocationCode(null);

        $this->plugin->beforeSave($this->subject, $order);

        $this->assertNull($order->getData('pp_pickup_location_code'));
    }

    public function testNeverMirroredNonNullAttributeIsWrittenBack(): void
    {
        $ext = new ExtensionAttributes();
        $ext->setPpPickupLocationCode('x');
        $order = $this->order(null, $ext);

        $this->plugin->beforeSave($this->subject, $order);

        $this->assertSame('x', $order->getData('pp_pickup_location_code'));
    }

    public function testAfterGetListFailureIsLoggedAndTheResultReturned(): void
    {
        $list = $this->createMock(OrderSearchResultInterface::class);
        $list->method('getItems')->willThrowException(new \RuntimeException('boom'));
        $this->logger->expects($this->once())->method('error')
            ->with('Pickup location code could not be read onto the orders: boom');

        $this->assertSame($list, $this->plugin->afterGetList($this->subject, $list));
    }

    public function testSecondSaveKeepsANewerColumnValueAfterAWriteBack(): void
    {
        $order = $this->order('main');
        $this->plugin->afterGet($this->subject, $order);
        $this->ext($order)?->setPpPickupLocationCode('second');
        $this->plugin->beforeSave($this->subject, $order);
        $order->setData('pp_pickup_location_code', 'third');

        $this->plugin->beforeSave($this->subject, $order);

        $this->assertSame('third', $order->getData('pp_pickup_location_code'));
    }
}
