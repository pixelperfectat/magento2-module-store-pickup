<?php declare(strict_types=1);

namespace PixelPerfect\StorePickup\Test\Unit\Service;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PixelPerfect\StorePickup\Service\Config;

class ConfigTest extends TestCase
{
    /** @var ScopeConfigInterface&MockObject */
    private $scopeConfig;

    private Config $config;

    protected function setUp(): void
    {
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->config = new Config($this->scopeConfig);
    }

    /**
     * @param array<string, mixed> $values path => value
     */
    private function stubValues(array $values, ?int $storeId): void
    {
        $this->scopeConfig->method('getValue')->willReturnCallback(
            function (string $path, string $scope, $scopeCode) use ($values, $storeId) {
                $this->assertSame(ScopeInterface::SCOPE_STORE, $scope);
                $this->assertSame($storeId, $scopeCode);
                return $values[$path] ?? null;
            }
        );
        $this->scopeConfig->method('isSetFlag')->willReturnCallback(
            function (string $path, string $scope, $scopeCode) use ($values, $storeId) {
                $this->assertSame(ScopeInterface::SCOPE_STORE, $scope);
                $this->assertSame($storeId, $scopeCode);
                return (bool) ($values[$path] ?? false);
            }
        );
    }

    public function testIsActiveReadsFlagForStore(): void
    {
        $this->stubValues(['carriers/pppickup/active' => '1'], 3);
        $this->assertTrue($this->config->isActive(3));
    }

    public function testIsActiveIsFalseWhenNotSet(): void
    {
        $this->stubValues([], null);
        $this->assertFalse($this->config->isActive());
    }

    public function testGetTitleReturnsString(): void
    {
        $this->stubValues(['carriers/pppickup/title' => 'Store Pickup'], 2);
        $this->assertSame('Store Pickup', $this->config->getTitle(2));
    }

    public function testGetTitleIsEmptyStringWhenMissing(): void
    {
        $this->stubValues([], null);
        $this->assertSame('', $this->config->getTitle());
    }

    public function testGetDefaultPriceIsFloat(): void
    {
        $this->stubValues(['carriers/pppickup/price' => '4.5'], 1);
        $this->assertSame(4.5, $this->config->getDefaultPrice(1));
    }

    public function testGetDefaultPriceIsZeroWhenMissing(): void
    {
        $this->stubValues([], null);
        $this->assertSame(0.0, $this->config->getDefaultPrice());
    }

    public function testIsReadyEmailEnabled(): void
    {
        $this->stubValues(['pp_store_pickup/ready_email/enabled' => '1'], 5);
        $this->assertTrue($this->config->isReadyEmailEnabled(5));
    }

    public function testGetReadyEmailIdentity(): void
    {
        $this->stubValues(['pp_store_pickup/ready_email/identity' => 'sales'], 5);
        $this->assertSame('sales', $this->config->getReadyEmailIdentity(5));
    }

    public function testGetReadyEmailTemplateForCustomer(): void
    {
        $this->stubValues([
            'pp_store_pickup/ready_email/template' => 'customer_tpl',
            'pp_store_pickup/ready_email/guest_template' => 'guest_tpl',
        ], 4);
        $this->assertSame('customer_tpl', $this->config->getReadyEmailTemplate(false, 4));
    }

    public function testGetReadyEmailTemplateForGuestReadsGuestTemplate(): void
    {
        $this->stubValues([
            'pp_store_pickup/ready_email/template' => 'customer_tpl',
            'pp_store_pickup/ready_email/guest_template' => 'guest_tpl',
        ], 4);
        $this->assertSame('guest_tpl', $this->config->getReadyEmailTemplate(true, 4));
    }

    public function testGetReadyEmailCopyToTrimsAndDropsEmpties(): void
    {
        $this->stubValues(
            ['pp_store_pickup/ready_email/copy_to' => ' a@example.com, ,b@example.com '],
            null
        );
        $this->assertSame(['a@example.com', 'b@example.com'], $this->config->getReadyEmailCopyTo());
    }

    public function testGetReadyEmailCopyToIsEmptyWhenUnset(): void
    {
        $this->stubValues([], null);
        $this->assertSame([], $this->config->getReadyEmailCopyTo());
    }

    public function testGetReadyEmailCopyMethod(): void
    {
        $this->stubValues(['pp_store_pickup/ready_email/copy_method' => 'bcc'], 1);
        $this->assertSame('bcc', $this->config->getReadyEmailCopyMethod(1));
    }

    public function testPickupOnlyPaymentMethodsFromMultiselect(): void
    {
        $this->stubValues(['pp_store_pickup/payment/pickup_only_methods' => 'cashondelivery,checkmo'], 1);
        $this->assertSame(['cashondelivery', 'checkmo'], $this->config->getPickupOnlyPaymentMethods(1));
    }

    public function testPickupOnlyPaymentMethodsEmptyMultiselect(): void
    {
        $this->stubValues(['pp_store_pickup/payment/pickup_only_methods' => ''], null);
        $this->assertSame([], $this->config->getPickupOnlyPaymentMethods());
    }

    public function testHiddenForPickupPaymentMethodsFromMultiselect(): void
    {
        $this->stubValues(['pp_store_pickup/payment/hidden_for_pickup' => 'banktransfer, purchaseorder'], 1);
        $this->assertSame(['banktransfer', 'purchaseorder'], $this->config->getHiddenForPickupPaymentMethods(1));
    }

    public function testHiddenForPickupPaymentMethodsEmptyWhenUnset(): void
    {
        $this->stubValues([], null);
        $this->assertSame([], $this->config->getHiddenForPickupPaymentMethods());
    }

    public function testScalarNonStringValueIsCastToString(): void
    {
        $this->stubValues(['pp_store_pickup/ready_email/identity' => 7], null);
        $this->assertSame('7', $this->config->getReadyEmailIdentity());
    }

    public function testNonScalarValueBecomesEmptyString(): void
    {
        $this->stubValues(['carriers/pppickup/title' => ['unexpected']], null);
        $this->assertSame('', $this->config->getTitle());
    }
}
