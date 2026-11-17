<?php declare(strict_types=1);

namespace PixelPerfect\StorePickup\Test\Unit\Plugin\Quote\Api\CartRepositoryInterface;

use Magento\Framework\Api\ExtensionAttributesFactory;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Model\Quote;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PixelPerfect\StorePickup\Plugin\Quote\Api\CartRepositoryInterface\PickupLocationCodeExtension;
use PixelPerfect\StorePickup\Service\ExtensionAttribute\PickupLocationCodeMirror;
use PixelPerfect\StorePickup\Test\Stub\ExtensionAttributes;
use Psr\Log\LoggerInterface;

class PickupLocationCodeExtensionTest extends TestCase
{
    /** @var ExtensionAttributesFactory&MockObject */
    private $factory;

    /** @var LoggerInterface&MockObject */
    private $logger;

    private PickupLocationCodeExtension $plugin;

    /** @var CartRepositoryInterface&MockObject */
    private $subject;

    protected function setUp(): void
    {
        $this->factory = $this->createMock(ExtensionAttributesFactory::class);
        $this->factory->method('create')->willReturnCallback(static fn () => new ExtensionAttributes());
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->subject = $this->createMock(CartRepositoryInterface::class);
        $this->plugin = new PickupLocationCodeExtension(new PickupLocationCodeMirror($this->factory), $this->logger);
    }

    /**
     * @param string|null $code
     * @param ExtensionAttributes|null $ext
     * @return Quote&MockObject
     */
    private function quote(?string $code, ?ExtensionAttributes $ext = null)
    {
        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getExtensionAttributes', 'setExtensionAttributes'])
            ->getMock();
        $quote->setData('pp_pickup_location_code', $code);
        $holder = new \ArrayObject(['ext' => $ext]);
        $quote->method('getExtensionAttributes')->willReturnCallback(static fn () => $holder['ext']);
        $quote->method('setExtensionAttributes')->willReturnCallback(
            static function ($value) use ($holder, $quote) {
                $holder['ext'] = $value;
                return $quote;
            }
        );
        return $quote;
    }

    /**
     * @param CartInterface $quote
     * @return ExtensionAttributes|null
     */
    private function ext(CartInterface $quote): ?ExtensionAttributes
    {
        $ext = $quote->getExtensionAttributes();
        return $ext instanceof ExtensionAttributes ? $ext : null;
    }

    public function testAfterGetMirrorsDataToExtensionAttribute(): void
    {
        $quote = $this->quote('main');

        $result = $this->plugin->afterGet($this->subject, $quote);

        $this->assertSame($quote, $result);
        $this->assertSame('main', $this->ext($quote)?->getPpPickupLocationCode());
    }

    public function testAfterGetKeepsExistingExtensionAttributes(): void
    {
        $existing = new ExtensionAttributes();
        $existing->other = 'keep';
        $quote = $this->quote('main', $existing);

        $this->plugin->afterGet($this->subject, $quote);

        $this->assertSame($existing, $this->ext($quote));
        $this->assertSame('keep', $existing->other);
        $this->assertSame('main', $existing->getPpPickupLocationCode());
    }

    public function testAfterGetActiveAndCustomerVariantsMirrorToo(): void
    {
        foreach (['afterGetActive', 'afterGetForCustomer', 'afterGetActiveForCustomer'] as $method) {
            $quote = $this->quote('main');
            $this->plugin->$method($this->subject, $quote);
            $this->assertSame('main', $this->ext($quote)?->getPpPickupLocationCode(), $method);
        }
    }

    public function testNullStaysNull(): void
    {
        $quote = $this->quote(null);

        $this->plugin->afterGet($this->subject, $quote);
        $this->assertNull($this->ext($quote)?->getPpPickupLocationCode());

        $this->plugin->beforeSave($this->subject, $quote);
        $this->assertNull($quote->getData('pp_pickup_location_code'));
    }

    public function testBeforeSaveWritesExtensionAttributeBackToData(): void
    {
        $ext = new ExtensionAttributes();
        $ext->setPpPickupLocationCode('second');
        $quote = $this->quote('main', $ext);

        $result = $this->plugin->beforeSave($this->subject, $quote);

        $this->assertSame([$quote], $result);
        $this->assertSame('second', $quote->getData('pp_pickup_location_code'));
    }

    public function testBeforeSaveKeepsDataWhenExtensionAttributeIsNull(): void
    {
        $quote = $this->quote('main', new ExtensionAttributes());

        $this->plugin->beforeSave($this->subject, $quote);

        $this->assertSame('main', $quote->getData('pp_pickup_location_code'));
    }

    public function testBeforeSaveWithoutExtensionAttributesKeepsData(): void
    {
        $this->logger->expects($this->never())->method('error');
        $quote = $this->quote('main');

        $this->assertSame([$quote], $this->plugin->beforeSave($this->subject, $quote));
        $this->assertSame('main', $quote->getData('pp_pickup_location_code'));
    }

    public function testFailureIsLoggedAndTheCartIsStillReturned(): void
    {
        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getExtensionAttributes'])
            ->getMock();
        $quote->method('getExtensionAttributes')->willThrowException(new \RuntimeException('boom'));
        $this->logger->expects($this->exactly(2))->method('error')->withConsecutive(
            ['Pickup location code could not be read onto the cart: boom'],
            ['Pickup location code could not be written to the cart: boom']
        );

        $this->assertSame($quote, $this->plugin->afterGet($this->subject, $quote));
        $this->assertSame([$quote], $this->plugin->beforeSave($this->subject, $quote));
    }

    public function testNewerColumnValueSurvivesAStaleMirroredAttribute(): void
    {
        $quote = $this->quote('main');
        $this->plugin->afterGet($this->subject, $quote);
        $quote->setData('pp_pickup_location_code', 'second');

        $this->plugin->beforeSave($this->subject, $quote);

        $this->assertSame('second', $quote->getData('pp_pickup_location_code'));
    }

    public function testAttributeChangedAfterLoadWinsOverTheColumn(): void
    {
        $quote = $this->quote('main');
        $this->plugin->afterGet($this->subject, $quote);
        $this->ext($quote)?->setPpPickupLocationCode('second');

        $this->plugin->beforeSave($this->subject, $quote);

        $this->assertSame('second', $quote->getData('pp_pickup_location_code'));
    }

    public function testAttributeSetToNullAfterLoadClearsTheCode(): void
    {
        $quote = $this->quote('main');
        $this->plugin->afterGet($this->subject, $quote);
        $this->ext($quote)?->setPpPickupLocationCode(null);

        $this->plugin->beforeSave($this->subject, $quote);

        $this->assertNull($quote->getData('pp_pickup_location_code'));
    }

    public function testNeverMirroredNonNullAttributeIsWrittenBack(): void
    {
        $ext = new ExtensionAttributes();
        $ext->setPpPickupLocationCode('x');
        $quote = $this->quote(null, $ext);

        $this->plugin->beforeSave($this->subject, $quote);

        $this->assertSame('x', $quote->getData('pp_pickup_location_code'));
    }

    public function testSecondSaveKeepsANewerColumnValueAfterAWriteBack(): void
    {
        $quote = $this->quote('main');
        $this->plugin->afterGet($this->subject, $quote);
        $this->ext($quote)?->setPpPickupLocationCode('second');
        $this->plugin->beforeSave($this->subject, $quote);
        $quote->setData('pp_pickup_location_code', 'third');

        $this->plugin->beforeSave($this->subject, $quote);

        $this->assertSame('third', $quote->getData('pp_pickup_location_code'));
    }
}
