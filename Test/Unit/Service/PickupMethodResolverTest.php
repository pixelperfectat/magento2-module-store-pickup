<?php declare(strict_types=1);

namespace PixelPerfect\StorePickup\Test\Unit\Service;

use Magento\Framework\Exception\InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use PixelPerfect\StorePickup\Api\Service\PickupMethodResolverInterface;
use PixelPerfect\StorePickup\Service\PickupMethodResolver;

class PickupMethodResolverTest extends TestCase
{
    private PickupMethodResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new PickupMethodResolver();
    }

    public function testCarrierCodeConstant(): void
    {
        $this->assertSame('pppickup', PickupMethodResolverInterface::CARRIER_CODE);
    }

    /**
     * @return array<string, array{0: ?string, 1: ?string}>
     */
    public static function methodProvider(): array
    {
        return [
            'simple' => ['pppickup_main', 'main'],
            'underscore in code' => ['pppickup_main_2', 'main_2'],
            'other carrier' => ['flatrate_flatrate', null],
            'empty code' => ['pppickup_', null],
            'carrier prefix extended' => ['pppickupx_main', null],
            'empty string' => ['', null],
            'null' => [null, null],
            'uppercase code' => ['pppickup_Main', null],
            'trailing newline' => ["pppickup_main\n", null],
            'too long code' => ['pppickup_' . str_repeat('a', 65), null],
            'max length code' => ['pppickup_' . str_repeat('a', 64), str_repeat('a', 64)],
        ];
    }

    /**
     * @dataProvider methodProvider
     */
    public function testResolvesLocationCodeAndPickupFlag(?string $method, ?string $expected): void
    {
        $this->assertSame($expected, $this->resolver->getLocationCode($method));
        $this->assertSame($expected !== null, $this->resolver->isPickupMethod($method));
    }

    public function testToShippingMethod(): void
    {
        $this->assertSame('pppickup_main', $this->resolver->toShippingMethod('main'));
    }

    public function testToShippingMethodRejectsInvalidCode(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->resolver->toShippingMethod('Main');
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function codeProvider(): array
    {
        return [
            'word' => ['main', true],
            'single char' => ['a', true],
            'digits underscore' => ['store_01', true],
            '64 chars' => [str_repeat('a', 64), true],
            'empty' => ['', false],
            'uppercase' => ['Main', false],
            'space' => ['main store', false],
            'umlaut' => ['café', false],
            'hyphen' => ['main-1', false],
            '65 chars' => [str_repeat('a', 65), false],
            'trailing newline' => ["main\n", false],
        ];
    }

    /**
     * @dataProvider codeProvider
     */
    public function testIsValidLocationCode(string $code, bool $expected): void
    {
        $this->assertSame($expected, $this->resolver->isValidLocationCode($code));
    }
}
