<?php declare(strict_types=1);

namespace PixelPerfect\StorePickup\Test\Unit\Observer;

use Magento\Framework\DataObject;
use Magento\Framework\Event\Observer;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PixelPerfect\StorePickup\Api\Service\ConfigInterface;
use PixelPerfect\StorePickup\Api\Service\PickupMethodResolverInterface;
use PixelPerfect\StorePickup\Observer\RestrictPaymentForPickup;
use Psr\Log\LoggerInterface;

class RestrictPaymentForPickupTest extends TestCase
{
    /** @var ConfigInterface&MockObject */
    private $config;

    /** @var PickupMethodResolverInterface&MockObject */
    private $resolver;

    /** @var LoggerInterface&MockObject */
    private $logger;

    private RestrictPaymentForPickup $observer;

    protected function setUp(): void
    {
        $this->config = $this->createMock(ConfigInterface::class);
        $this->resolver = $this->createMock(PickupMethodResolverInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->observer = new RestrictPaymentForPickup($this->config, $this->resolver, $this->logger);
    }

    /**
     * @param list<string> $pickupOnly
     * @param list<string> $hidden
     */
    private function dispatch(string $method, ?Quote $quote, array $pickupOnly, array $hidden, bool $isPickup): DataObject
    {
        $this->config->method('getPickupOnlyPaymentMethods')->with(5)->willReturn($pickupOnly);
        $this->config->method('getHiddenForPickupPaymentMethods')->with(5)->willReturn($hidden);
        $this->resolver->method('isPickupMethod')->with('pppickup_main')->willReturn($isPickup);

        $result = new DataObject(['is_available' => true]);
        $this->observer->execute(new Observer(['event' => new DataObject([
            'result' => $result,
            'quote' => $quote,
            'method_instance' => $this->methodInstance($method),
        ])]));

        return $result;
    }

    private function methodInstance(string $code): object
    {
        return new class($code) {
            public function __construct(private readonly string $code)
            {
            }

            public function getCode(): string
            {
                return $this->code;
            }
        };
    }

    private function quote(): Quote
    {
        $address = $this->createMock(Address::class);
        $address->method('getShippingMethod')->willReturn('pppickup_main');
        $quote = $this->createMock(Quote::class);
        $quote->method('getShippingAddress')->willReturn($address);
        $quote->method('getStoreId')->willReturn(5);

        return $quote;
    }

    public function testNullQuoteChangesNothing(): void
    {
        $this->config->expects($this->never())->method('getPickupOnlyPaymentMethods');
        $result = new DataObject(['is_available' => true]);
        $this->observer->execute(new Observer(['event' => new DataObject([
            'result' => $result,
            'quote' => null,
            'method_instance' => $this->methodInstance('checkmo'),
        ])]));

        $this->assertTrue($result->getData('is_available'));
    }

    public function testPickupOnlyMethodIsHiddenForNonPickupQuote(): void
    {
        $result = $this->dispatch('cashondelivery', $this->quote(), ['cashondelivery'], [], false);

        $this->assertFalse($result->getData('is_available'));
    }

    public function testPickupOnlyMethodStaysForPickupQuote(): void
    {
        $result = $this->dispatch('cashondelivery', $this->quote(), ['cashondelivery'], [], true);

        $this->assertTrue($result->getData('is_available'));
    }

    public function testHiddenMethodIsHiddenForPickupQuote(): void
    {
        $result = $this->dispatch('checkmo', $this->quote(), [], ['checkmo'], true);

        $this->assertFalse($result->getData('is_available'));
    }

    public function testHiddenMethodStaysForNonPickupQuote(): void
    {
        $result = $this->dispatch('checkmo', $this->quote(), [], ['checkmo'], false);

        $this->assertTrue($result->getData('is_available'));
    }

    public function testUnlistedMethodChangesNothing(): void
    {
        $result = $this->dispatch('banktransfer', $this->quote(), ['cashondelivery'], ['checkmo'], true);

        $this->assertTrue($result->getData('is_available'));
    }

    public function testEmptyListsChangeNothing(): void
    {
        $result = $this->dispatch('checkmo', $this->quote(), [], [], true);

        $this->assertTrue($result->getData('is_available'));
    }

    public function testNeverMakesAnUnavailableMethodAvailable(): void
    {
        $this->config->method('getPickupOnlyPaymentMethods')->willReturn(['checkmo']);
        $this->config->method('getHiddenForPickupPaymentMethods')->willReturn([]);
        $this->resolver->method('isPickupMethod')->willReturn(true);
        $result = new DataObject(['is_available' => false]);
        $this->observer->execute(new Observer(['event' => new DataObject([
            'result' => $result,
            'quote' => $this->quote(),
            'method_instance' => $this->methodInstance('checkmo'),
        ])]));

        $this->assertFalse($result->getData('is_available'));
    }

    public function testConfigErrorIsLoggedAndChangesNothing(): void
    {
        $this->config->method('getPickupOnlyPaymentMethods')->willThrowException(new \RuntimeException('boom'));
        $this->logger->expects($this->once())->method('error')->with($this->stringContains('boom'));
        $result = new DataObject(['is_available' => true]);
        $this->observer->execute(new Observer(['event' => new DataObject([
            'result' => $result,
            'quote' => $this->quote(),
            'method_instance' => $this->methodInstance('checkmo'),
        ])]));

        $this->assertTrue($result->getData('is_available'));
    }
}
