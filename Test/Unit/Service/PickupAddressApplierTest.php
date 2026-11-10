<?php declare(strict_types=1);

namespace PixelPerfect\StorePickup\Test\Unit\Service;

use Magento\Framework\Serialize\Serializer\Json;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PixelPerfect\StorePickup\Api\Data\PickupLocationInterface;
use PixelPerfect\StorePickup\Api\Service\LocationProviderInterface;
use PixelPerfect\StorePickup\Model\Data\PickupLocation;
use PixelPerfect\StorePickup\Service\PickupAddressApplier;
use PixelPerfect\StorePickup\Service\PickupMethodResolver;
use Psr\Log\LoggerInterface;

class PickupAddressApplierTest extends TestCase
{
    private const string HOME_STREET = '5 Home Street';

    /** @var LocationProviderInterface&MockObject */
    private $provider;

    /** @var CartRepositoryInterface&MockObject */
    private $cartRepository;

    /** @var LoggerInterface&MockObject */
    private $logger;

    private PickupAddressApplier $applier;

    /** @var array<string, PickupLocationInterface> */
    private array $locations;

    protected function setUp(): void
    {
        $this->locations = [
            'main' => $this->location('main', 'Main Store', '1 Example Street', '1010', 'Vienna'),
            'second' => $this->location('second', 'Second Store', '2 Example Street', '8010', 'Graz'),
            'closed' => $this->location('closed', 'Closed Store', '3 Example Street', '4020', 'Linz', false),
        ];
        $this->provider = $this->createMock(LocationProviderInterface::class);
        $this->provider->method('getLocation')->willReturnCallback(
            fn (string $code, int $storeId): ?PickupLocationInterface => $storeId === 1
                ? ($this->locations[$code] ?? null)
                : null
        );
        $this->cartRepository = $this->createMock(CartRepositoryInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->applier = new PickupAddressApplier(
            $this->provider,
            new PickupMethodResolver(),
            new Json(),
            $this->cartRepository,
            $this->logger
        );
    }

    private function location(
        string $code,
        string $name,
        string $street,
        string $postcode,
        string $city,
        bool $active = true
    ): PickupLocationInterface {
        return new PickupLocation(
            code: $code,
            name: $name,
            street: [$street],
            postcode: $postcode,
            city: $city,
            countryId: 'AT',
            regionId: 95,
            region: 'Wien',
            active: $active
        );
    }

    /**
     * @param array<string, mixed> $data
     * @return Address&MockObject
     */
    private function address(array $data): Address
    {
        $address = $this->getMockBuilder(Address::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getRegion'])
            ->getMock();
        $address->method('getRegion')->willReturnCallback(static fn () => $address->getData('region'));
        $address->setData($data);

        return $address;
    }

    /**
     * @return array<string, mixed>
     */
    private function homeAddress(): array
    {
        return [
            'customer_address_id' => 7,
            'firstname' => 'John',
            'lastname' => 'Smith',
            'company' => 'Example Ltd',
            'street' => self::HOME_STREET,
            'postcode' => '6020',
            'city' => 'Innsbruck',
            'region' => 'Tirol',
            'region_id' => 102,
            'country_id' => 'AT',
            'telephone' => '+43 512 000000',
            'save_in_address_book' => 1,
            'same_as_billing' => 1,
        ];
    }

    /**
     * @param string|null $method
     * @param array<string, mixed> $shipping
     * @param array<string, mixed> $quoteData
     * @param bool $virtual
     * @return Quote&MockObject
     */
    private function quote(
        ?string $method,
        array $shipping = [],
        array $quoteData = [],
        bool $virtual = false
    ): Quote {
        $shippingAddress = $this->address(($shipping ?: $this->homeAddress()) + ['shipping_method' => $method]);
        $billingAddress = $this->address([
            'firstname' => 'Jane',
            'lastname' => 'Doe',
            'telephone' => '+43 1 2345678',
            'street' => '8 Billing Road',
        ]);
        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getShippingAddress', 'getBillingAddress', 'isVirtual'])
            ->getMock();
        $quote->method('getShippingAddress')->willReturn($shippingAddress);
        $quote->method('getBillingAddress')->willReturn($billingAddress);
        $quote->method('isVirtual')->willReturn($virtual);
        $quote->setIdFieldName('entity_id');
        $quote->setData($quoteData + ['store_id' => 1]);

        return $quote;
    }

    /**
     * @param Quote $quote
     * @return array<string, mixed>
     */
    private function snapshot(Quote $quote): array
    {
        $snapshot = (new Json())->unserialize((string) $quote->getData('pp_pickup_address_snapshot'));
        self::assertIsArray($snapshot);

        return $snapshot;
    }

    public function testVirtualQuoteIsLeftUntouched(): void
    {
        $quote = $this->quote('pppickup_main', [], [], true);
        $before = $quote->getShippingAddress()->getData();

        $this->assertFalse($this->applier->apply($quote));

        $this->assertSame($before, $quote->getShippingAddress()->getData());
        $this->assertNull($quote->getData('pp_pickup_location_code'));
        $this->assertNull($quote->getData('pp_pickup_address_snapshot'));
    }

    public function testNonPickupMethodIsLeftUntouched(): void
    {
        $quote = $this->quote('flatrate_flatrate');
        $before = $quote->getShippingAddress()->getData();

        $this->assertFalse($this->applier->apply($quote));

        $this->assertSame($before, $quote->getShippingAddress()->getData());
        $this->assertNull($quote->getData('pp_pickup_location_code'));
    }

    public function testUnknownLocationIsLeftUntouched(): void
    {
        $quote = $this->quote('pppickup_gone');
        $before = $quote->getShippingAddress()->getData();

        $this->assertFalse($this->applier->apply($quote));

        $this->assertSame($before, $quote->getShippingAddress()->getData());
        $this->assertNull($quote->getData('pp_pickup_location_code'));
    }

    public function testInactiveLocationIsLeftUntouched(): void
    {
        $quote = $this->quote('pppickup_closed');
        $before = $quote->getShippingAddress()->getData();

        $this->assertFalse($this->applier->apply($quote));

        $this->assertSame($before, $quote->getShippingAddress()->getData());
        $this->assertNull($quote->getData('pp_pickup_location_code'));
    }

    public function testLocationIsLookedUpInTheQuoteStore(): void
    {
        $quote = $this->quote('pppickup_main', [], ['store_id' => 2]);

        $this->assertFalse($this->applier->apply($quote));
    }

    public function testNonQuoteCartIsIgnored(): void
    {
        $cart = $this->createMock(CartInterface::class);

        $this->assertFalse($this->applier->apply($cart));
        $this->assertFalse($this->applier->revert($cart));
    }

    public function testFirstApplyStoresSnapshotAndSetsCode(): void
    {
        $quote = $this->quote('pppickup_main');

        $this->assertTrue($this->applier->apply($quote));

        $this->assertSame('main', $quote->getData('pp_pickup_location_code'));
        $this->assertSame(
            [
                'customer_address_id' => 7,
                'firstname' => 'John',
                'lastname' => 'Smith',
                'company' => 'Example Ltd',
                'street' => self::HOME_STREET,
                'postcode' => '6020',
                'city' => 'Innsbruck',
                'region' => 'Tirol',
                'region_id' => 102,
                'country_id' => 'AT',
                'telephone' => '+43 512 000000',
                'save_in_address_book' => 1,
                'same_as_billing' => 1,
            ],
            $this->snapshot($quote)
        );
        $address = $quote->getShippingAddress();
        $this->assertSame(['1 Example Street'], $address->getStreet());
        $this->assertSame('1010', $address->getPostcode());
        $this->assertSame('Vienna', $address->getCity());
        $this->assertSame('AT', $address->getCountryId());
        $this->assertSame(95, $address->getData('region_id'));
        $this->assertSame('Wien', $address->getData('region'));
        $this->assertSame('Main Store', $address->getCompany());
    }

    public function testSwitchingLocationKeepsTheFirstSnapshot(): void
    {
        $quote = $this->quote('pppickup_main');
        $this->applier->apply($quote);
        $firstSnapshot = $quote->getData('pp_pickup_address_snapshot');

        $quote->getShippingAddress()->setShippingMethod('pppickup_second');
        $this->assertTrue($this->applier->apply($quote));

        $this->assertSame($firstSnapshot, $quote->getData('pp_pickup_address_snapshot'));
        $this->assertSame('second', $quote->getData('pp_pickup_location_code'));
        $this->assertSame(['2 Example Street'], $quote->getShippingAddress()->getStreet());
        $this->assertSame('Second Store', $quote->getShippingAddress()->getCompany());
    }

    public function testEmptyShippingNamesAndTelephoneAreTakenFromBilling(): void
    {
        $quote = $this->quote('pppickup_main', ['firstname' => '', 'lastname' => null, 'street' => 'x']);

        $this->applier->apply($quote);

        $address = $quote->getShippingAddress();
        $this->assertSame('Jane', $address->getFirstname());
        $this->assertSame('Doe', $address->getLastname());
        $this->assertSame('+43 1 2345678', $address->getTelephone());
    }

    public function testEmptyNamesFallBackToTheCustomerWhenBillingHasNone(): void
    {
        $quote = $this->quote(
            'pppickup_main',
            ['street' => 'x'],
            ['customer_firstname' => 'Jane', 'customer_lastname' => 'Doe']
        );
        $quote->getBillingAddress()->setData([]);

        $this->applier->apply($quote);

        $address = $quote->getShippingAddress();
        $this->assertSame('Jane', $address->getFirstname());
        $this->assertSame('Doe', $address->getLastname());
        $this->assertNull($address->getTelephone());
    }

    public function testEachEmptyContactFieldIsFilledOnItsOwn(): void
    {
        $quote = $this->quote('pppickup_main', ['firstname' => 'John', 'lastname' => '', 'telephone' => '']);
        $quote->getBillingAddress()->setData(['lastname' => 'Doe']);

        $this->applier->apply($quote);

        $address = $quote->getShippingAddress();
        $this->assertSame('John', $address->getFirstname());
        $this->assertSame('Doe', $address->getLastname());
        $this->assertSame('', $address->getTelephone());
    }

    public function testShippingNamesAlreadySetAreKept(): void
    {
        $quote = $this->quote('pppickup_main');

        $this->applier->apply($quote);

        $address = $quote->getShippingAddress();
        $this->assertSame('John', $address->getFirstname());
        $this->assertSame('Smith', $address->getLastname());
        $this->assertSame('+43 512 000000', $address->getTelephone());
    }

    public function testAddressIsDetachedFromTheAddressBookOnACustomerQuote(): void
    {
        $quote = $this->quote('pppickup_main', [], ['customer_id' => '42']);

        $this->applier->apply($quote);

        $address = $quote->getShippingAddress();
        $this->assertNull($address->getCustomerAddressId());
        $this->assertSame(0, $address->getSaveInAddressBook());
        $this->assertSame(0, $address->getSameAsBilling());
        $this->assertSame(42, $address->getCustomerId());
    }

    public function testGuestQuoteGetsNoCustomerId(): void
    {
        $quote = $this->quote('pppickup_main', $this->homeAddress() + ['customer_id' => 9]);

        $this->applier->apply($quote);

        $this->assertNull($quote->getShippingAddress()->getCustomerId());
    }

    public function testApplyToAddressNeverTouchesNamesTelephoneOrEmail(): void
    {
        $address = $this->address($this->homeAddress() + ['email' => 'jane.doe@example.com']);

        $this->applier->applyToAddress($address, $this->locations['main'], null);

        $this->assertSame('John', $address->getFirstname());
        $this->assertSame('Smith', $address->getLastname());
        $this->assertSame('+43 512 000000', $address->getTelephone());
        $this->assertSame('jane.doe@example.com', $address->getEmail());
        $this->assertSame('Main Store', $address->getCompany());
    }

    public function testApplyToAddressClearsTheRegionOfALocationWithout(): void
    {
        $address = $this->address($this->homeAddress());
        $location = new PickupLocation(
            code: 'plain',
            name: 'Plain Store',
            street: ['4 Example Street', 'Floor 2'],
            postcode: '5020',
            city: 'Salzburg',
            countryId: 'DE'
        );

        $this->applier->applyToAddress($address, $location, null);

        $this->assertSame(['4 Example Street', 'Floor 2'], $address->getStreet());
        $this->assertSame('DE', $address->getCountryId());
        $this->assertNull($address->getData('region_id'));
        $this->assertNull($address->getData('region'));
    }

    public function testRevertRestoresTheSnapshot(): void
    {
        $quote = $this->quote('pppickup_main');
        $this->applier->apply($quote);

        $this->assertTrue($this->applier->revert($quote));

        $address = $quote->getShippingAddress();
        $this->assertSame(7, $address->getCustomerAddressId());
        $this->assertSame([self::HOME_STREET], $address->getStreet());
        $this->assertSame('Example Ltd', $address->getCompany());
        $this->assertSame('Innsbruck', $address->getCity());
        $this->assertSame(102, $address->getData('region_id'));
        $this->assertSame(1, $address->getSaveInAddressBook());
        $this->assertSame(1, $address->getSameAsBilling());
        $this->assertNull($quote->getData('pp_pickup_location_code'));
        $this->assertNull($quote->getData('pp_pickup_address_snapshot'));
    }

    public function testRevertWithoutSnapshotClearsOnlyTheLocationParts(): void
    {
        $quote = $this->quote('flatrate_flatrate', [], ['pp_pickup_location_code' => 'main']);
        $this->applier->applyToAddress($quote->getShippingAddress(), $this->locations['main'], null);
        $this->logger->expects($this->never())->method('warning');

        $this->assertTrue($this->applier->revert($quote));

        $address = $quote->getShippingAddress();
        $this->assertNull($address->getData('street'));
        $this->assertNull($address->getPostcode());
        $this->assertNull($address->getCity());
        $this->assertNull($address->getData('region'));
        $this->assertNull($address->getData('region_id'));
        $this->assertNull($address->getCompany());
        $this->assertSame('John', $address->getFirstname());
        $this->assertSame('Smith', $address->getLastname());
        $this->assertSame('AT', $address->getCountryId());
        $this->assertNull($quote->getData('pp_pickup_location_code'));
    }

    public function testRevertWithUnreadableSnapshotLogsAndClearsTheLocationParts(): void
    {
        $quote = $this->quote(
            'flatrate_flatrate',
            [],
            ['entity_id' => 33, 'pp_pickup_location_code' => 'main', 'pp_pickup_address_snapshot' => '{broken']
        );
        $this->logger->expects($this->once())->method('warning')->with(
            'Pickup address snapshot of quote 33 is unreadable: Unable to unserialize value. Error: Syntax error'
        );

        $this->assertTrue($this->applier->revert($quote));

        $this->assertNull($quote->getShippingAddress()->getData('street'));
        $this->assertNull($quote->getData('pp_pickup_address_snapshot'));
    }

    public function testRevertWithANonArraySnapshotClearsTheLocationParts(): void
    {
        $quote = $this->quote(
            'flatrate_flatrate',
            [],
            ['pp_pickup_location_code' => 'main', 'pp_pickup_address_snapshot' => '"text"']
        );

        $this->assertTrue($this->applier->revert($quote));

        $this->assertNull($quote->getShippingAddress()->getData('street'));
    }

    public function testRevertWithoutCodeDoesNothing(): void
    {
        $quote = $this->quote('flatrate_flatrate', [], ['pp_pickup_address_snapshot' => '{"street":"x"}']);

        $this->assertFalse($this->applier->revert($quote));

        $this->assertSame([self::HOME_STREET], $quote->getShippingAddress()->getStreet());
    }

    public function testSyncAppliesAndSavesForANewPickupSelection(): void
    {
        $quote = $this->quote('pppickup_main');
        $this->cartRepository->expects($this->once())->method('save')->with($quote);

        $this->applier->sync($quote);

        $this->assertSame('main', $quote->getData('pp_pickup_location_code'));
    }

    public function testSyncAppliesWhenTheStoredCodeDiffers(): void
    {
        $quote = $this->quote('pppickup_second', [], ['pp_pickup_location_code' => 'main']);
        $this->cartRepository->expects($this->once())->method('save');

        $this->applier->sync($quote);

        $this->assertSame('second', $quote->getData('pp_pickup_location_code'));
    }

    public function testSyncRevertsOnANonPickupMethod(): void
    {
        $quote = $this->quote('pppickup_main');
        $this->applier->apply($quote);
        $quote->getShippingAddress()->setShippingMethod('flatrate_flatrate');
        $this->cartRepository->expects($this->once())->method('save')->with($quote);

        $this->applier->sync($quote);

        $this->assertSame([self::HOME_STREET], $quote->getShippingAddress()->getStreet());
        $this->assertNull($quote->getData('pp_pickup_location_code'));
    }

    public function testSyncRevertsOnAnInactiveLocation(): void
    {
        $quote = $this->quote('pppickup_main');
        $this->applier->apply($quote);
        $this->locations['main'] = $this->location('main', 'Main Store', '1 Example Street', '1010', 'Vienna', false);
        $this->cartRepository->expects($this->once())->method('save');

        $this->applier->sync($quote);

        $this->assertSame([self::HOME_STREET], $quote->getShippingAddress()->getStreet());
        $this->assertNull($quote->getData('pp_pickup_location_code'));
    }

    public function testSyncRevertsOnAMissingLocation(): void
    {
        $quote = $this->quote('pppickup_main');
        $this->applier->apply($quote);
        unset($this->locations['main']);
        $this->cartRepository->expects($this->once())->method('save');

        $this->applier->sync($quote);

        $this->assertNull($quote->getData('pp_pickup_location_code'));
    }

    public function testSyncDoesNotSaveWhenNothingChanged(): void
    {
        $pickup = $this->quote('pppickup_main', [], ['pp_pickup_location_code' => 'main']);
        $delivery = $this->quote('flatrate_flatrate');
        $gone = $this->quote('pppickup_closed');
        $this->cartRepository->expects($this->never())->method('save');
        $this->logger->expects($this->never())->method('error');

        $this->applier->sync($pickup);
        $this->applier->sync($delivery);
        $this->applier->sync($gone);
        $this->applier->sync($this->createMock(CartInterface::class));

        $this->assertSame([self::HOME_STREET], $pickup->getShippingAddress()->getStreet());
    }

    public function testSyncOnAVirtualQuoteRevertsAStoredCode(): void
    {
        $quote = $this->quote('pppickup_main', [], ['pp_pickup_location_code' => 'main'], true);
        $this->cartRepository->expects($this->once())->method('save');

        $this->applier->sync($quote);

        $this->assertNull($quote->getData('pp_pickup_location_code'));
    }

    public function testSyncLogsAndSwallowsErrors(): void
    {
        $quote = $this->quote('pppickup_main');
        $this->cartRepository->method('save')->willThrowException(new \RuntimeException('db down'));
        $this->logger->expects($this->once())->method('error')
            ->with('Pickup address could not be synchronised with the shipping method: db down');

        $this->applier->sync($quote);
    }
}
