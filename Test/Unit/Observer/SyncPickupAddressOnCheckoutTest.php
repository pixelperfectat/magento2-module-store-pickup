<?php declare(strict_types=1);

namespace PixelPerfect\StorePickup\Test\Unit\Observer;

use Magento\Checkout\Model\Session;
use Magento\Framework\Event\Observer;
use Magento\Quote\Model\Quote;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PixelPerfect\StorePickup\Api\Service\PickupAddressApplierInterface;
use PixelPerfect\StorePickup\Observer\SyncPickupAddressOnCheckout;
use Psr\Log\LoggerInterface;

class SyncPickupAddressOnCheckoutTest extends TestCase
{
    /** @var Session&MockObject */
    private $session;

    /** @var PickupAddressApplierInterface&MockObject */
    private $applier;

    /** @var LoggerInterface&MockObject */
    private $logger;

    private SyncPickupAddressOnCheckout $observer;

    protected function setUp(): void
    {
        $this->session = $this->getMockBuilder(Session::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getQuoteId', 'getQuote'])
            ->getMock();
        $this->applier = $this->createMock(PickupAddressApplierInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->observer = new SyncPickupAddressOnCheckout($this->session, $this->applier, $this->logger);
    }

    public function testSessionQuoteIsSynced(): void
    {
        $quote = $this->createMock(Quote::class);
        $this->session->method('getQuoteId')->willReturn(12);
        $this->session->method('getQuote')->willReturn($quote);
        $this->applier->expects($this->once())->method('sync')->with($quote);

        $this->observer->execute(new Observer());
    }

    public function testNoQuoteNoSync(): void
    {
        $this->session->method('getQuoteId')->willReturn(null);
        $this->session->expects($this->never())->method('getQuote');
        $this->applier->expects($this->never())->method('sync');

        $this->observer->execute(new Observer());
    }

    public function testErrorsAreLogged(): void
    {
        $this->session->method('getQuoteId')->willReturn(12);
        $this->session->method('getQuote')->willThrowException(new \RuntimeException('no session'));
        $this->logger->expects($this->once())->method('error')
            ->with('Pickup address could not be synchronised on the checkout page: no session');

        $this->observer->execute(new Observer());
    }
}
