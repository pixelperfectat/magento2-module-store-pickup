<?php declare(strict_types=1);

namespace PixelPerfect\StorePickup\Test\Unit\Plugin\Quote\Model\QuoteManagement;

use Magento\Quote\Model\Quote;
use Magento\Quote\Model\QuoteManagement;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PixelPerfect\StorePickup\Api\Service\PickupAddressApplierInterface;
use PixelPerfect\StorePickup\Plugin\Quote\Model\QuoteManagement\ApplyPickupAddressBeforeSubmit;
use Psr\Log\LoggerInterface;

class ApplyPickupAddressBeforeSubmitTest extends TestCase
{
    /** @var PickupAddressApplierInterface&MockObject */
    private $applier;

    /** @var LoggerInterface&MockObject */
    private $logger;

    private ApplyPickupAddressBeforeSubmit $plugin;

    /** @var QuoteManagement&MockObject */
    private $subject;

    protected function setUp(): void
    {
        $this->applier = $this->createMock(PickupAddressApplierInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->plugin = new ApplyPickupAddressBeforeSubmit($this->applier, $this->logger);
        $this->subject = $this->createMock(QuoteManagement::class);
    }

    /**
     * @return Quote&MockObject
     */
    private function quote(): Quote
    {
        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();
        $quote->setData('pp_pickup_address_snapshot', '{"street":"5 Home Street"}');

        return $quote;
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
