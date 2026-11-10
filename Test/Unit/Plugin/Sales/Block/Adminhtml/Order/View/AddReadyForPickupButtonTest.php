<?php declare(strict_types=1);

namespace PixelPerfect\StorePickup\Test\Unit\Plugin\Sales\Block\Adminhtml\Order\View;

use Magento\Framework\AuthorizationInterface;
use Magento\Framework\View\LayoutInterface;
use Magento\Sales\Block\Adminhtml\Order\View;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PixelPerfect\StorePickup\Api\Service\ReadyForPickupServiceInterface;
use PixelPerfect\StorePickup\Plugin\Sales\Block\Adminhtml\Order\View\AddReadyForPickupButton;

class AddReadyForPickupButtonTest extends TestCase
{
    /** @var ReadyForPickupServiceInterface&MockObject */
    private $service;

    /** @var AuthorizationInterface&MockObject */
    private $authorization;

    /** @var View&MockObject */
    private $block;

    /** @var LayoutInterface&MockObject */
    private $layout;

    private AddReadyForPickupButton $plugin;

    protected function setUp(): void
    {
        $this->service = $this->createMock(ReadyForPickupServiceInterface::class);
        $this->authorization = $this->createMock(AuthorizationInterface::class);
        $this->block = $this->getMockBuilder(View::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getOrder', 'addButton', 'getUrl', 'escapeJs'])
            ->getMock();
        $this->block->method('escapeJs')->willReturnCallback(static fn ($value) => 'js(' . $value . ')');
        $this->layout = $this->createMock(LayoutInterface::class);
        $this->plugin = new AddReadyForPickupButton($this->service, $this->authorization);
    }

    /**
     * @return Order&MockObject
     */
    private function order()
    {
        $order = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getEntityId'])
            ->getMock();
        $order->method('getEntityId')->willReturn('42');
        $this->block->method('getOrder')->willReturn($order);

        return $order;
    }

    public function testAddsTheButtonForAnEligibleOrderWhenAllowed(): void
    {
        $order = $this->order();
        $this->authorization->method('isAllowed')
            ->with('PixelPerfect_StorePickup::ready_for_pickup')
            ->willReturn(true);
        $this->service->method('isEligible')->with($order)->willReturn(true);
        $this->block->expects($this->once())->method('getUrl')
            ->with('pp_store_pickup/order/readyForPickup', $this->identicalTo(['order_id' => 42]))
            ->willReturn('https://admin.example.com/pp_store_pickup/order/readyForPickup/order_id/42/key/abc/');
        $this->block->expects($this->once())->method('addButton')->with(
            'ready_for_pickup',
            [
                'label' => __('Ready for Pickup'),
                'class' => 'ready-for-pickup',
                'onclick' => "confirmSetLocation('js(Create the shipment if needed, set the status and email the"
                    . " customer that the order is ready for pickup?)', 'js(https://admin.example.com/"
                    . "pp_store_pickup/order/readyForPickup/order_id/42/key/abc/)')",
            ]
        );

        $this->assertNull($this->plugin->beforeSetLayout($this->block, $this->layout));
    }

    public function testAddsNoButtonForAnIneligibleOrder(): void
    {
        $this->order();
        $this->authorization->method('isAllowed')->willReturn(true);
        $this->service->method('isEligible')->willReturn(false);
        $this->block->expects($this->never())->method('addButton');

        $this->assertNull($this->plugin->beforeSetLayout($this->block, $this->layout));
    }

    public function testAddsNoButtonWhenNotAllowed(): void
    {
        $this->order();
        $this->authorization->method('isAllowed')->willReturn(false);
        $this->service->method('isEligible')->willReturn(true);
        $this->block->expects($this->never())->method('addButton');

        $this->assertNull($this->plugin->beforeSetLayout($this->block, $this->layout));
    }

    public function testAddsNoButtonWithoutAnOrder(): void
    {
        $this->block->method('getOrder')->willReturn(null);
        $this->authorization->method('isAllowed')->willReturn(true);
        $this->service->expects($this->never())->method('isEligible');
        $this->block->expects($this->never())->method('addButton');

        $this->assertNull($this->plugin->beforeSetLayout($this->block, $this->layout));
    }

    public function testUsesTheConfiguredAclResource(): void
    {
        $this->order();
        $plugin = new AddReadyForPickupButton($this->service, $this->authorization, 'Vendor_Module::custom');
        $this->authorization->expects($this->atLeastOnce())->method('isAllowed')
            ->with('Vendor_Module::custom')
            ->willReturn(true);
        $this->service->method('isEligible')->willReturn(true);
        $this->block->expects($this->once())->method('addButton');

        $this->assertNull($plugin->beforeSetLayout($this->block, $this->layout));
    }
}
