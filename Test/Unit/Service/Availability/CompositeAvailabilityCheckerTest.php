<?php declare(strict_types=1);

namespace PixelPerfect\StorePickup\Test\Unit\Service\Availability;

use Magento\Quote\Model\Quote\Address\RateRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PixelPerfect\StorePickup\Api\Data\PickupLocationInterface;
use PixelPerfect\StorePickup\Api\Service\AvailabilityCheckerInterface;
use PixelPerfect\StorePickup\Model\Data\PickupLocation;
use PixelPerfect\StorePickup\Service\Availability\AlwaysAvailable;
use PixelPerfect\StorePickup\Service\Availability\CompositeAvailabilityChecker;
use Psr\Log\LoggerInterface;

class CompositeAvailabilityCheckerTest extends TestCase
{
    /** @var LoggerInterface&MockObject */
    private $logger;

    private PickupLocationInterface $location;

    private RateRequest $request;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->location = new PickupLocation('main', 'Main', ['1 Example Street'], '1010', 'Vienna', 'AT');
        $this->request = new RateRequest();
    }

    private function checker(bool $answer): AvailabilityCheckerInterface
    {
        $checker = $this->createMock(AvailabilityCheckerInterface::class);
        $checker->method('isAvailable')->willReturn($answer);

        return $checker;
    }

    public function testNoCheckersMeansAvailable(): void
    {
        $composite = new CompositeAvailabilityChecker($this->logger, []);

        $this->assertTrue($composite->isAvailable($this->location, $this->request));
    }

    public function testAllYesIsAvailable(): void
    {
        $composite = new CompositeAvailabilityChecker($this->logger, [
            'a' => $this->checker(true),
            'b' => $this->checker(true),
        ]);

        $this->assertTrue($composite->isAvailable($this->location, $this->request));
    }

    public function testOneNoMakesItUnavailable(): void
    {
        $composite = new CompositeAvailabilityChecker($this->logger, [
            'a' => $this->checker(true),
            'b' => $this->checker(false),
        ]);

        $this->assertFalse($composite->isAvailable($this->location, $this->request));
    }

    public function testThrowingCheckerCountsAsNoAndIsLoggedOnce(): void
    {
        $throwing = $this->createMock(AvailabilityCheckerInterface::class);
        $throwing->method('isAvailable')->willThrowException(new \RuntimeException('boom'));
        $this->logger->expects($this->once())->method('error')->with(
            $this->stringContains('"broken"'),
            $this->arrayHasKey('exception')
        );
        $composite = new CompositeAvailabilityChecker($this->logger, ['broken' => $throwing]);

        $this->assertFalse($composite->isAvailable($this->location, $this->request));
    }

    public function testAlwaysAvailableSaysYes(): void
    {
        $this->assertTrue((new AlwaysAvailable())->isAvailable($this->location, $this->request));
    }
}
