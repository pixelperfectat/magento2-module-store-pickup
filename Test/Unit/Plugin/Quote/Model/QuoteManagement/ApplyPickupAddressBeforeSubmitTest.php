<?php declare(strict_types=1);

namespace PixelPerfect\StorePickup\Test\Unit\Plugin\Quote\Model\QuoteManagement;

use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use Magento\Quote\Model\QuoteManagement;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PixelPerfect\StorePickup\Api\Service\PickupAddressApplierInterface;
use PixelPerfect\StorePickup\Plugin\Quote\Model\QuoteManagement\ApplyPickupAddressBeforeSubmit;
use PixelPerfect\StorePickup\Service\ExtensionAttribute\PickupLocationCodeMirror;
use PixelPerfect\StorePickup\Service\PickupMethodResolver;
use Psr\Log\LoggerInterface;

class ApplyPickupAddressBeforeSubmitTest extends TestCase
{
    /** @var PickupAddressApplierInterface&MockObject */
    private $applier;

    /** @var LoggerInterface&MockObject */
    private $logger;

    /** @var PickupLocationCodeMirror&MockObject */
    private $mirror;

    private ApplyPickupAddressBeforeSubmit $plugin;

    /** @var QuoteManagement&MockObject */
    private $subject;

    protected function setUp(): void
    {
        $this->applier = $this->createMock(PickupAddressApplierInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->mirror = $this->createMock(PickupLocationCodeMirror::class);
        $this->plugin = new ApplyPickupAddressBeforeSubmit(
            $this->applier,
            new PickupMethodResolver(),
            $this->mirror,
            $this->logger
        );
        $this->subject = $this->createMock(QuoteManagement::class);
    }

    /**
     * @param string|null $method
     * @param bool $virtual
     * @return Quote&MockObject
     */
    private function quote(?string $method = 'pppickup_main', bool $virtual = false): Quote
    {
        $address = $this->getMockBuilder(Address::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();
        $address->setData(['street' => '1 Example Street', 'shipping_method' => $method]);
        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getShippingAddress', 'isVirtual'])
            ->getMock();
        $quote->method('getShippingAddress')->willReturn($address);
        $quote->method('isVirtual')->willReturn($virtual);
        $quote->setData('pp_pickup_location_code', 'main');
        $quote->setData('pp_pickup_address_snapshot', '{"street":"5 Home Street"}');

        return $quote;
    }

    public function testNonPickupMethodClearsAStaleCodeAndLeavesTheAddress(): void
    {
        $quote = $this->quote('flatrate_flatrate');
        $this->applier->method('apply')->willReturn(false);

        $this->assertNull($this->plugin->beforeSubmit($this->subject, $quote));

        $this->assertNull($quote->getData('pp_pickup_location_code'));
        $this->assertSame('1 Example Street', $quote->getShippingAddress()->getData('street'));
        $this->assertSame('{"street":"5 Home Street"}', $quote->getData('pp_pickup_address_snapshot'));
    }

    public function testVirtualQuoteClearsAStaleCode(): void
    {
        $quote = $this->quote('pppickup_main', true);
        $this->applier->method('apply')->willReturn(false);

        $this->plugin->beforeSubmit($this->subject, $quote);

        $this->assertNull($quote->getData('pp_pickup_location_code'));
    }

    public function testEmptyMethodKeepsTheCode(): void
    {
        $this->applier->method('apply')->willReturn(false);
        foreach (['', null] as $method) {
            $quote = $this->quote($method);

            $this->plugin->beforeSubmit($this->subject, $quote);

            $this->assertSame('main', $quote->getData('pp_pickup_location_code'));
        }
    }

    public function testExtensionAttributeIsRefreshedFromTheColumn(): void
    {
        $quote = $this->quote('flatrate_flatrate');
        $this->applier->method('apply')->willReturn(false);
        $this->mirror->expects($this->once())->method('mirror')
            ->with($quote, \Magento\Quote\Api\Data\CartInterface::class)
            ->willReturnCallback(fn () => $this->assertNull($quote->getData('pp_pickup_location_code')));

        $this->plugin->beforeSubmit($this->subject, $quote);
    }

    public function testDisabledLocationKeepsTheCode(): void
    {
        $quote = $this->quote('pppickup_main');
        $this->applier->method('apply')->willReturn(false);

        $this->plugin->beforeSubmit($this->subject, $quote);

        $this->assertSame('main', $quote->getData('pp_pickup_location_code'));
    }

    public function testAppliedPickupClearsTheSnapshot(): void
    {
        $quote = $this->quote();
        $this->applier->expects($this->once())->method('apply')->with($quote)->willReturn(true);

        $this->assertNull($this->plugin->beforeSubmit($this->subject, $quote, ['x' => 1]));

        $this->assertNull($quote->getData('pp_pickup_address_snapshot'));
    }

    public function testNothingAppliedKeepsTheSnapshot(): void
    {
        $quote = $this->quote();
        $this->applier->method('apply')->willReturn(false);

        $this->assertNull($this->plugin->beforeSubmit($this->subject, $quote));

        $this->assertSame('{"street":"5 Home Street"}', $quote->getData('pp_pickup_address_snapshot'));
    }

    public function testErrorsAreLoggedAndSubmitGoesAhead(): void
    {
        $quote = $this->quote();
        $this->applier->method('apply')->willThrowException(new \RuntimeException('boom'));
        $this->logger->expects($this->once())->method('error')
            ->with('Pickup address could not be applied before the order was submitted: boom');

        $this->assertNull($this->plugin->beforeSubmit($this->subject, $quote));
    }
}
