<?php declare(strict_types=1);

namespace PixelPerfect\StorePickup\Test\Unit\Model\Order\Email\Container;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PixelPerfect\StorePickup\Api\Service\ConfigInterface;
use PixelPerfect\StorePickup\Model\Order\Email\Container\ReadyForPickupIdentity;

class ReadyForPickupIdentityTest extends TestCase
{
    /** @var ConfigInterface&MockObject */
    private $config;

    private ReadyForPickupIdentity $identity;

    protected function setUp(): void
    {
        $this->config = $this->createMock(ConfigInterface::class);
        $this->identity = new ReadyForPickupIdentity(
            $this->createMock(ScopeConfigInterface::class),
            $this->createMock(StoreManagerInterface::class),
            $this->config
        );
        $store = $this->createMock(Store::class);
        $store->method('getId')->willReturn('3');
        $this->identity->setStore($store);
    }

    public function testReadsEveryValueForTheStoreOfTheOrder(): void
    {
        $this->config->method('isReadyEmailEnabled')->with(3)->willReturn(true);
        $this->config->method('getReadyEmailIdentity')->with(3)->willReturn('sales');
        $this->config->method('getReadyEmailTemplate')->willReturnMap([
            [false, 3, 'customer_template'],
            [true, 3, 'guest_template'],
        ]);
        $this->config->method('getReadyEmailCopyTo')->with(3)
            ->willReturn(['copy.one@example.com', 'copy.two@example.com']);
        $this->config->method('getReadyEmailCopyMethod')->with(3)->willReturn('copy');

        self::assertTrue($this->identity->isEnabled());
        self::assertSame('sales', $this->identity->getEmailIdentity());
        self::assertSame('customer_template', $this->identity->getTemplateId());
        self::assertSame('guest_template', $this->identity->getGuestTemplateId());
        self::assertSame(['copy.one@example.com', 'copy.two@example.com'], $this->identity->getEmailCopyTo());
        self::assertSame('copy', $this->identity->getCopyMethod());
    }

    public function testWithoutAStoreReadsForTheCurrentStore(): void
    {
        $current = $this->createMock(Store::class);
        $current->method('getId')->willReturn('5');
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($current);
        $identity = new ReadyForPickupIdentity(
            $this->createMock(ScopeConfigInterface::class),
            $storeManager,
            $this->config
        );
        $this->config->method('getReadyEmailIdentity')->with(5)->willReturn('support');

        self::assertSame('support', $identity->getEmailIdentity());
    }

    public function testDisabledAndNoCopies(): void
    {
        $this->config->method('isReadyEmailEnabled')->with(3)->willReturn(false);
        $this->config->method('getReadyEmailCopyTo')->with(3)->willReturn([]);

        self::assertFalse($this->identity->isEnabled());
        self::assertFalse($this->identity->getEmailCopyTo());
    }
}
