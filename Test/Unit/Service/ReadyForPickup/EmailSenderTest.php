<?php declare(strict_types=1);

namespace PixelPerfect\StorePickup\Test\Unit\Service\ReadyForPickup;

use Magento\Framework\App\Area;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\DataObject;
use Magento\Framework\DataObjectFactory;
use Magento\Framework\Locale\ListsInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Container\IdentityInterface;
use Magento\Sales\Model\Order\Email\Container\Template;
use Magento\Sales\Model\Order\Email\SenderBuilder;
use Magento\Sales\Model\Order\Email\SenderBuilderFactory;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\Store;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PixelPerfect\StorePickup\Model\Data\PickupLocation;
use PixelPerfect\StorePickup\Service\ReadyForPickup\EmailSender;
use Psr\Log\LoggerInterface;

require_once __DIR__ . '/../../../Stub/ReadyForPickupFactories.php';

class EmailSenderTest extends TestCase
{
    private Template $template;

    /** @var IdentityInterface&MockObject */
    private $identity;

    /** @var SenderBuilderFactory&MockObject */
    private $senderBuilderFactory;

    /** @var SenderBuilder&MockObject */
    private $senderBuilder;

    /** @var LoggerInterface&MockObject */
    private $logger;

    /** @var Store&MockObject */
    private $store;

    private PickupLocation $location;

    private EmailSender $sender;

    protected function setUp(): void
    {
        $this->template = new Template();
        $this->identity = $this->createMock(IdentityInterface::class);
        $this->senderBuilder = $this->createMock(SenderBuilder::class);
        $this->senderBuilderFactory = $this->createMock(SenderBuilderFactory::class);
        $this->senderBuilderFactory->method('create')
            ->with(['templateContainer' => $this->template, 'identityContainer' => $this->identity])
            ->willReturn($this->senderBuilder);
        $this->logger = $this->createMock(LoggerInterface::class);
        $translatedLists = $this->createMock(ListsInterface::class);
        $translatedLists->method('getCountryTranslation')->willReturnMap([
            ['AT', 'de_DE', 'Österreich'],
            ['XX', 'de_DE', null],
        ]);
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->with('general/locale/code', ScopeInterface::SCOPE_STORE, 3)
            ->willReturn('de_DE');
        $this->store = $this->createMock(Store::class);
        $this->store->method('getId')->willReturn('3');
        $this->identity->method('getStore')->willReturn($this->store);
        $this->identity->method('getTemplateId')->willReturn('customer_template');
        $this->identity->method('getGuestTemplateId')->willReturn('guest_template');
        $this->location = new PickupLocation(
            code: 'main',
            name: 'Main Store',
            street: ['1 Example Street', 'Door 2'],
            postcode: '1010',
            city: 'Vienna',
            countryId: 'AT',
            telephone: '+43 1 0000000',
            openingHours: 'Mon-Fri 9-18',
            note: 'Ring the bell.'
        );
        $this->sender = new EmailSender(
            $this->template,
            $this->identity,
            $this->senderBuilderFactory,
            new DataObjectFactory(),
            $translatedLists,
            $scopeConfig,
            $this->logger
        );
    }

    /**
     * @return Order&MockObject
     */
    private function order(bool $guest = false): Order
    {
        $order = $this->createMock(Order::class);
        $order->method('getStore')->willReturn($this->store);
        $order->method('getId')->willReturn('42');
        $order->method('getIncrementId')->willReturn('000000042');
        $order->method('getCustomerIsGuest')->willReturn($guest ? 1 : 0);
        $order->method('getCustomerName')->willReturn('Jane Doe');
        $order->method('getCustomerEmail')->willReturn('jane.doe@example.com');
        $order->method('getFrontendStatusLabel')->willReturn('Ready for Pickup');
        $order->method('getIsNotVirtual')->willReturn(true);
        $order->method('getEmailCustomerNote')->willReturn('Please wrap it.');

        return $order;
    }

    public function testSendsTheCustomerTemplateWithOrderAndLocationVariables(): void
    {
        $order = $this->order();
        $this->identity->expects($this->once())->method('setStore')->with($this->store);
        $this->identity->expects($this->once())->method('setCustomerName')->with('Jane Doe');
        $this->identity->expects($this->once())->method('setCustomerEmail')->with('jane.doe@example.com');
        $this->identity->method('getCopyMethod')->willReturn('bcc');
        $this->senderBuilder->expects($this->once())->method('send');
        $this->senderBuilder->expects($this->never())->method('sendCopyTo');

        self::assertTrue($this->sender->send($order, $this->location));

        self::assertSame('customer_template', $this->template->getTemplateId());
        self::assertSame(['area' => Area::AREA_FRONTEND, 'store' => 3], $this->template->getTemplateOptions());
        $vars = $this->template->getTemplateVars();
        self::assertSame($order, $vars['order']);
        self::assertSame(42, $vars['order_id']);
        self::assertSame($this->store, $vars['store']);
        self::assertInstanceOf(DataObject::class, $vars['location']);
        self::assertSame(
            [
                'name' => 'Main Store',
                'street' => '1 Example Street, Door 2',
                'postcode' => '1010',
                'city' => 'Vienna',
                'country' => 'Österreich',
                'telephone' => '+43 1 0000000',
                'opening_hours' => 'Mon-Fri 9-18',
                'note' => 'Ring the bell.',
            ],
            $vars['location']->getData()
        );
        self::assertSame(
            [
                'customer_name' => 'Jane Doe',
                'frontend_status_label' => 'Ready for Pickup',
                'is_not_virtual' => true,
                'email_customer_note' => 'Please wrap it.',
            ],
            $vars['order_data']
        );
    }

    public function testGuestOrderUsesTheGuestTemplate(): void
    {
        $this->identity->method('getCopyMethod')->willReturn('bcc');

        self::assertTrue($this->sender->send($this->order(true), $this->location));

        self::assertSame('guest_template', $this->template->getTemplateId());
    }

    public function testUnknownCountryFallsBackToItsCode(): void
    {
        $location = new PickupLocation('main', 'Main Store', ['1 Example Street'], '1010', 'Vienna', 'XX');

        self::assertTrue($this->sender->send($this->order(), $location));

        $vars = $this->template->getTemplateVars();
        self::assertSame('XX', $vars['location']->getData('country'));
        self::assertNull($vars['location']->getData('telephone'));
    }

    public function testCopyMethodCopySendsSeparateCopies(): void
    {
        $this->identity->method('getCopyMethod')->willReturn('copy');
        $this->senderBuilder->expects($this->once())->method('send');
        $this->senderBuilder->expects($this->once())->method('sendCopyTo');

        self::assertTrue($this->sender->send($this->order(), $this->location));
    }

    public function testFailedCopyIsLoggedAndStillCountsAsSent(): void
    {
        $this->identity->method('getCopyMethod')->willReturn('copy');
        $this->senderBuilder->method('sendCopyTo')->willThrowException(new \RuntimeException('copy failed'));
        $this->logger->expects($this->once())->method('error')
            ->with('Ready-for-pickup email copy for order 000000042 failed: copy failed', $this->arrayHasKey('exception'));

        self::assertTrue($this->sender->send($this->order(), $this->location));
    }

    public function testFailedSendIsLoggedAndReturnsFalse(): void
    {
        $this->identity->method('getCopyMethod')->willReturn('copy');
        $this->senderBuilder->method('send')->willThrowException(new \RuntimeException('mail down'));
        $this->senderBuilder->expects($this->never())->method('sendCopyTo');
        $this->logger->expects($this->once())->method('error')
            ->with('Ready-for-pickup email for order 000000042 failed: mail down', $this->arrayHasKey('exception'));

        self::assertFalse($this->sender->send($this->order(), $this->location));
    }

    public function testErrorWhileSendingIsLoggedAndReturnsFalse(): void
    {
        $this->identity->method('getCopyMethod')->willReturn('copy');
        $this->senderBuilder->method('send')->willThrowException(new \Error('template broken'));
        $this->senderBuilder->expects($this->never())->method('sendCopyTo');
        $this->logger->expects($this->once())->method('error')
            ->with('Ready-for-pickup email for order 000000042 failed: template broken', $this->arrayHasKey('exception'));

        self::assertFalse($this->sender->send($this->order(), $this->location));
    }

    public function testErrorWhileSendingTheCopyIsLoggedAndStillCountsAsSent(): void
    {
        $this->identity->method('getCopyMethod')->willReturn('copy');
        $this->senderBuilder->method('sendCopyTo')->willThrowException(new \TypeError('copy broken'));
        $this->logger->expects($this->once())->method('error')
            ->with('Ready-for-pickup email copy for order 000000042 failed: copy broken', $this->arrayHasKey('exception'));

        self::assertTrue($this->sender->send($this->order(), $this->location));
    }

    public function testErrorWhilePreparingTheEmailIsLoggedAndReturnsFalse(): void
    {
        $order = $this->createMock(Order::class);
        $order->method('getStore')->willReturn($this->store);
        $order->method('getIncrementId')->willReturn('000000042');
        $order->method('getFrontendStatusLabel')->willThrowException(new \RuntimeException('no status label'));
        $this->senderBuilder->expects($this->never())->method('send');
        $this->logger->expects($this->once())->method('error')
            ->with('Ready-for-pickup email for order 000000042 failed: no status label', $this->arrayHasKey('exception'));

        self::assertFalse($this->sender->send($order, $this->location));
    }

    public function testErrorWhileCreatingTheSenderIsLoggedAndReturnsFalse(): void
    {
        $factory = $this->createMock(SenderBuilderFactory::class);
        $factory->method('create')->willThrowException(new \TypeError('builder broken'));
        $sender = new EmailSender(
            $this->template,
            $this->identity,
            $factory,
            new DataObjectFactory(),
            $this->createMock(ListsInterface::class),
            $this->createMock(ScopeConfigInterface::class),
            $this->logger
        );
        $this->logger->expects($this->once())->method('error')
            ->with('Ready-for-pickup email for order 000000042 failed: builder broken', $this->arrayHasKey('exception'));

        self::assertFalse($sender->send($this->order(), $this->location));
    }

    public function testErrorWhileReadingTheCopyMethodIsLoggedAndStillCountsAsSent(): void
    {
        $this->identity->method('getCopyMethod')->willThrowException(new \RuntimeException('no config'));
        $this->senderBuilder->expects($this->once())->method('send');
        $this->logger->expects($this->once())->method('error')
            ->with('Ready-for-pickup email copy for order 000000042 failed: no config', $this->arrayHasKey('exception'));

        self::assertTrue($this->sender->send($this->order(), $this->location));
    }

    public function testOrderThatIsNoOrderModelIsNotSent(): void
    {
        $order = $this->createMock(OrderInterface::class);
        $this->senderBuilderFactory->expects($this->never())->method('create');
        $this->logger->expects($this->once())->method('error')
            ->with('Ready-for-pickup email not sent: ' . $order::class . ' is no order model.');

        self::assertFalse($this->sender->send($order, $this->location));
    }
}
