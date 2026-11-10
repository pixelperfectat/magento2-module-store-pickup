<?php declare(strict_types=1);

namespace PixelPerfect\StorePickup\Test\Integration\ReadyForPickup;

use Magento\Catalog\Test\Fixture\Product as ProductFixture;
use Magento\Checkout\Test\Fixture\SetBillingAddress;
use Magento\Checkout\Test\Fixture\SetDeliveryMethod;
use Magento\Checkout\Test\Fixture\SetGuestEmail;
use Magento\Checkout\Test\Fixture\SetPaymentMethod;
use Magento\Checkout\Test\Fixture\SetShippingAddress;
use Magento\Customer\Test\Fixture\Customer;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Mail\EmailMessageInterface;
use Magento\Quote\Test\Fixture\AddProductToCart;
use Magento\Quote\Test\Fixture\CustomerCart;
use Magento\Quote\Test\Fixture\GuestCart;
use Magento\Sales\Api\InvoiceOrderInterface;
use Magento\Sales\Api\ShipOrderInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Status\History;
use Magento\Sales\Model\ResourceModel\Order\Shipment\CollectionFactory as ShipmentCollectionFactory;
use Magento\TestFramework\Fixture\AppArea;
use Magento\TestFramework\Fixture\Config;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\TestFramework\Mail\Template\TransportBuilderMock;
use PHPUnit\Framework\TestCase;
use PixelPerfect\StorePickup\Api\Service\ReadyForPickupServiceInterface;
use PixelPerfect\StorePickup\Exception\AlreadyNotifiedException;
use PixelPerfect\StorePickup\Test\Integration\Fixture\PickupOrderTrait;

require_once __DIR__ . '/../Fixture/PickupOrderTrait.php';

/**
 * Marking a pickup order as ready for pickup ships it, emails the customer and sets the status.
 */
#[DbIsolation(false)]
#[AppArea('frontend')]
class ReadyForPickupServiceTest extends TestCase
{
    use PickupOrderTrait;

    /** @var list<EmailMessageInterface> */
    private array $sentMessages = [];

    protected function setUp(): void
    {
        $this->resetLocationCaches();
    }

    protected function tearDown(): void
    {
        Bootstrap::getObjectManager()->get(TransportBuilderMock::class)->clean();
        $this->sentMessages = [];
        $this->cleanUpOrders();
        $this->resetLocationCaches();
    }

    /**
     * Collects the messages sent from now on (the order confirmation of the placement is not among them).
     */
    private function recordMessages(): void
    {
        $transport = Bootstrap::getObjectManager()->get(TransportBuilderMock::class);
        $transport->clean();
        $transport->setOnMessageSentCallback(function (EmailMessageInterface $message): void {
            $this->sentMessages[] = $message;
        });
    }

    private function service(): ReadyForPickupServiceInterface
    {
        return Bootstrap::getObjectManager()->get(ReadyForPickupServiceInterface::class);
    }

    private function shipmentCount(int $orderId): int
    {
        return (int) Bootstrap::getObjectManager()->get(ShipmentCollectionFactory::class)->create()
            ->setOrderFilter($orderId)
            ->getSize();
    }

    private function lastComment(Order $order): History
    {
        $comment = $order->getStatusHistoryCollection()->getFirstItem();
        self::assertInstanceOf(History::class, $comment);

        return $comment;
    }

    private function body(EmailMessageInterface $message): string
    {
        return quoted_printable_decode($message->getBodyText());
    }

    /**
     * @return list<string>
     */
    private function recipients(EmailMessageInterface $message): array
    {
        $emails = [];
        foreach ($message->getTo() as $address) {
            $emails[] = (string) $address->getEmail();
        }

        return $emails;
    }

    #[Config('carriers/pppickup/active', '1', 'store', 'default')]
    #[Config('carriers/pppickup/locations', self::LOCATIONS, 'store', 'default')]
    #[Config('carriers/pppickup/locations', self::LOCATIONS)]
    #[DataFixture(ProductFixture::class, as: 'product')]
    #[DataFixture(
        Customer::class,
        ['email' => 'jane.doe@example.com', 'firstname' => 'Jane', 'lastname' => 'Doe'],
        as: 'customer'
    )]
    #[DataFixture(CustomerCart::class, ['customer_id' => '$customer.id$'], as: 'cart')]
    #[DataFixture(AddProductToCart::class, ['cart_id' => '$cart.id$', 'product_id' => '$product.id$', 'qty' => 2])]
    #[DataFixture(SetBillingAddress::class, ['cart_id' => '$cart.id$', 'address' => self::ADDRESS])]
    #[DataFixture(SetShippingAddress::class, ['cart_id' => '$cart.id$', 'address' => self::ADDRESS])]
    #[DataFixture(
        SetDeliveryMethod::class,
        ['cart_id' => '$cart.id$', 'carrier_code' => 'pppickup', 'method_code' => 'main']
    )]
    #[DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart.id$', 'method' => 'checkmo'])]
    public function testCreatesShipmentSendsEmailAndSetsStatus(): void
    {
        $orderId = $this->placeOrder();
        self::assertTrue($this->service()->isEligible($this->freshOrder($orderId)));
        $this->recordMessages();

        $this->service()->execute($orderId);

        self::assertSame(1, $this->shipmentCount($orderId));
        $order = $this->freshOrder($orderId);
        foreach ($order->getAllItems() as $item) {
            self::assertEquals($item->getQtyOrdered(), $item->getQtyShipped(), 'every item is shipped');
        }
        self::assertSame(Order::STATE_PROCESSING, $order->getState());
        self::assertSame('ready_for_pickup', $order->getStatus());
        self::assertNotEmpty($order->getData('pp_pickup_notified_at'));
        self::assertFalse($this->service()->isEligible($order));

        $comment = $this->lastComment($order);
        self::assertSame('Your order is ready for pickup at Main Store.', $comment->getComment());
        self::assertSame(1, (int) $comment->getIsVisibleOnFront());
        self::assertSame(1, (int) $comment->getIsCustomerNotified());

        self::assertCount(1, $this->sentMessages);
        $message = $this->sentMessages[0];
        self::assertSame(['jane.doe@example.com'], $this->recipients($message));
        self::assertStringContainsString('is ready for pickup', (string) $message->getSubject());
        self::assertStringContainsString('#' . $order->getIncrementId(), (string) $message->getSubject());
        $body = $this->body($message);
        self::assertStringContainsString('Main Store', $body);
        self::assertStringContainsString('1 Example Street', $body);
        self::assertStringContainsString('Mon-Fri 9:00-18:00', $body);
        self::assertStringContainsString('Please use the side entrance.', $body);
        self::assertStringContainsString('logging into your account', $body);
    }

    #[Config('carriers/pppickup/active', '1', 'store', 'default')]
    #[Config('carriers/pppickup/locations', self::LOCATIONS, 'store', 'default')]
    #[Config('carriers/pppickup/locations', self::LOCATIONS)]
    #[DataFixture(ProductFixture::class, as: 'product')]
    #[DataFixture(GuestCart::class, as: 'cart')]
    #[DataFixture(AddProductToCart::class, ['cart_id' => '$cart.id$', 'product_id' => '$product.id$'])]
    #[DataFixture(SetBillingAddress::class, ['cart_id' => '$cart.id$', 'address' => self::ADDRESS])]
    #[DataFixture(SetShippingAddress::class, ['cart_id' => '$cart.id$', 'address' => self::ADDRESS])]
    #[DataFixture(
        SetDeliveryMethod::class,
        ['cart_id' => '$cart.id$', 'carrier_code' => 'pppickup', 'method_code' => 'main']
    )]
    #[DataFixture(SetGuestEmail::class, ['cart_id' => '$cart.id$', 'email' => 'jane.doe@example.com'])]
    #[DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart.id$', 'method' => 'checkmo'])]
    public function testKeepsAnExistingShipment(): void
    {
        $orderId = $this->placeOrder();
        Bootstrap::getObjectManager()->get(ShipOrderInterface::class)->execute($orderId);
        self::assertSame(1, $this->shipmentCount($orderId));

        $this->service()->execute($orderId);

        self::assertSame(1, $this->shipmentCount($orderId));
        $order = $this->freshOrder($orderId);
        self::assertSame('ready_for_pickup', $order->getStatus());
        self::assertNotEmpty($order->getData('pp_pickup_notified_at'));
    }

    #[Config('carriers/pppickup/active', '1', 'store', 'default')]
    #[Config('carriers/pppickup/locations', self::LOCATIONS, 'store', 'default')]
    #[Config('carriers/pppickup/locations', self::LOCATIONS)]
    #[DataFixture(ProductFixture::class, as: 'product')]
    #[DataFixture(GuestCart::class, as: 'cart')]
    #[DataFixture(AddProductToCart::class, ['cart_id' => '$cart.id$', 'product_id' => '$product.id$'])]
    #[DataFixture(SetBillingAddress::class, ['cart_id' => '$cart.id$', 'address' => self::ADDRESS])]
    #[DataFixture(SetShippingAddress::class, ['cart_id' => '$cart.id$', 'address' => self::ADDRESS])]
    #[DataFixture(
        SetDeliveryMethod::class,
        ['cart_id' => '$cart.id$', 'carrier_code' => 'pppickup', 'method_code' => 'main']
    )]
    #[DataFixture(SetGuestEmail::class, ['cart_id' => '$cart.id$', 'email' => 'jane.doe@example.com'])]
    #[DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart.id$', 'method' => 'checkmo'])]
    public function testSecondCallThrowsAlreadyNotified(): void
    {
        $orderId = $this->placeOrder();
        $this->service()->execute($orderId);
        $this->recordMessages();

        try {
            $this->service()->execute($orderId);
            self::fail('A second call must be rejected.');
        } catch (AlreadyNotifiedException $exception) {
            self::assertStringContainsString(
                'has already been marked as ready for pickup.',
                $exception->getMessage()
            );
        }
        self::assertSame([], $this->sentMessages);
        self::assertSame(1, $this->shipmentCount($orderId));
    }

    #[Config('carriers/pppickup/active', '1', 'store', 'default')]
    #[Config('carriers/pppickup/locations', self::LOCATIONS, 'store', 'default')]
    #[Config('carriers/pppickup/locations', self::LOCATIONS)]
    #[DataFixture(ProductFixture::class, as: 'product')]
    #[DataFixture(GuestCart::class, as: 'cart')]
    #[DataFixture(AddProductToCart::class, ['cart_id' => '$cart.id$', 'product_id' => '$product.id$'])]
    #[DataFixture(SetBillingAddress::class, ['cart_id' => '$cart.id$', 'address' => self::ADDRESS])]
    #[DataFixture(SetShippingAddress::class, ['cart_id' => '$cart.id$', 'address' => self::ADDRESS])]
    #[DataFixture(
        SetDeliveryMethod::class,
        ['cart_id' => '$cart.id$', 'carrier_code' => 'flatrate', 'method_code' => 'flatrate']
    )]
    #[DataFixture(SetGuestEmail::class, ['cart_id' => '$cart.id$', 'email' => 'jane.doe@example.com'])]
    #[DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart.id$', 'method' => 'checkmo'])]
    public function testNonPickupOrderIsRejected(): void
    {
        $orderId = $this->placeOrder();
        self::assertFalse($this->service()->isEligible($this->freshOrder($orderId)));

        try {
            $this->service()->execute($orderId);
            self::fail('A flat rate order must be rejected.');
        } catch (LocalizedException $exception) {
            self::assertStringContainsString('is not a store pickup order.', $exception->getMessage());
        }
        self::assertSame(0, $this->shipmentCount($orderId));
    }

    #[Config('carriers/pppickup/active', '1', 'store', 'default')]
    #[Config('carriers/pppickup/locations', self::LOCATIONS, 'store', 'default')]
    #[Config('carriers/pppickup/locations', self::LOCATIONS)]
    #[Config('pp_store_pickup/ready_email/enabled', '0', 'store', 'default')]
    #[DataFixture(ProductFixture::class, as: 'product')]
    #[DataFixture(GuestCart::class, as: 'cart')]
    #[DataFixture(AddProductToCart::class, ['cart_id' => '$cart.id$', 'product_id' => '$product.id$'])]
    #[DataFixture(SetBillingAddress::class, ['cart_id' => '$cart.id$', 'address' => self::ADDRESS])]
    #[DataFixture(SetShippingAddress::class, ['cart_id' => '$cart.id$', 'address' => self::ADDRESS])]
    #[DataFixture(
        SetDeliveryMethod::class,
        ['cart_id' => '$cart.id$', 'carrier_code' => 'pppickup', 'method_code' => 'main']
    )]
    #[DataFixture(SetGuestEmail::class, ['cart_id' => '$cart.id$', 'email' => 'jane.doe@example.com'])]
    #[DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart.id$', 'method' => 'checkmo'])]
    public function testEmailDisabledStillShipsAndSetsStatus(): void
    {
        $orderId = $this->placeOrder();
        $this->recordMessages();

        $this->service()->execute($orderId);

        self::assertSame([], $this->sentMessages);
        self::assertSame(1, $this->shipmentCount($orderId));
        $order = $this->freshOrder($orderId);
        self::assertSame(Order::STATE_PROCESSING, $order->getState());
        self::assertSame('ready_for_pickup', $order->getStatus());
        self::assertNotEmpty($order->getData('pp_pickup_notified_at'));
        self::assertSame(0, (int) $this->lastComment($order)->getIsCustomerNotified());
    }

    #[Config('carriers/pppickup/active', '1', 'store', 'default')]
    #[Config('carriers/pppickup/locations', self::LOCATIONS, 'store', 'default')]
    #[Config('carriers/pppickup/locations', self::LOCATIONS)]
    #[DataFixture(ProductFixture::class, as: 'product')]
    #[DataFixture(GuestCart::class, as: 'cart')]
    #[DataFixture(AddProductToCart::class, ['cart_id' => '$cart.id$', 'product_id' => '$product.id$'])]
    #[DataFixture(SetBillingAddress::class, ['cart_id' => '$cart.id$', 'address' => self::ADDRESS])]
    #[DataFixture(SetShippingAddress::class, ['cart_id' => '$cart.id$', 'address' => self::ADDRESS])]
    #[DataFixture(
        SetDeliveryMethod::class,
        ['cart_id' => '$cart.id$', 'carrier_code' => 'pppickup', 'method_code' => 'main']
    )]
    #[DataFixture(SetGuestEmail::class, ['cart_id' => '$cart.id$', 'email' => 'jane.doe@example.com'])]
    #[DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart.id$', 'method' => 'checkmo'])]
    public function testInvoicedOrderEndsComplete(): void
    {
        $orderId = $this->placeOrder();
        Bootstrap::getObjectManager()->get(InvoiceOrderInterface::class)->execute($orderId);
        self::assertSame(Order::STATE_PROCESSING, $this->freshOrder($orderId)->getState());

        $this->service()->execute($orderId);

        $order = $this->freshOrder($orderId);
        self::assertSame(Order::STATE_COMPLETE, $order->getState());
        self::assertSame(Order::STATE_COMPLETE, $order->getStatus());
        self::assertNotEmpty($order->getData('pp_pickup_notified_at'));
    }

    #[Config('carriers/pppickup/active', '1', 'store', 'default')]
    #[Config('carriers/pppickup/locations', self::LOCATIONS, 'store', 'default')]
    #[Config('carriers/pppickup/locations', self::LOCATIONS)]
    #[DataFixture(ProductFixture::class, as: 'product')]
    #[DataFixture(GuestCart::class, as: 'cart')]
    #[DataFixture(AddProductToCart::class, ['cart_id' => '$cart.id$', 'product_id' => '$product.id$'])]
    #[DataFixture(SetBillingAddress::class, ['cart_id' => '$cart.id$', 'address' => self::ADDRESS])]
    #[DataFixture(SetShippingAddress::class, ['cart_id' => '$cart.id$', 'address' => self::ADDRESS])]
    #[DataFixture(
        SetDeliveryMethod::class,
        ['cart_id' => '$cart.id$', 'carrier_code' => 'pppickup', 'method_code' => 'main']
    )]
    #[DataFixture(SetGuestEmail::class, ['cart_id' => '$cart.id$', 'email' => 'jane.doe@example.com'])]
    #[DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart.id$', 'method' => 'checkmo'])]
    public function testGuestOrderUsesGuestTemplate(): void
    {
        $orderId = $this->placeOrder();
        $this->recordMessages();

        $this->service()->execute($orderId);

        self::assertCount(1, $this->sentMessages);
        $message = $this->sentMessages[0];
        self::assertSame(['jane.doe@example.com'], $this->recipients($message));
        self::assertStringContainsString('is ready for pickup', (string) $message->getSubject());
        $body = $this->body($message);
        self::assertStringContainsString('Main Store', $body);
        self::assertStringContainsString('Jane Doe', $body);
        // only the customer template links to the customer account
        self::assertStringNotContainsString('logging into your account', $body);
    }
}
