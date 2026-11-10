<?php declare(strict_types=1);

namespace PixelPerfect\StorePickup\Test\Unit\Model\Config\Backend;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\TestCase;
use PixelPerfect\StorePickup\Api\Service\LocationUsageInterface;
use PixelPerfect\StorePickup\Model\Config\Backend\LocationTexts;
use PixelPerfect\StorePickup\Model\Config\Backend\Locations;
use PixelPerfect\StorePickup\Service\PickupMethodResolver;

class LocationsTest extends TestCase
{
    /**
     * @param class-string<Locations|LocationTexts> $class
     * @param list<string> $inUse codes that open carts or orders use
     * @return Locations|LocationTexts
     */
    private function backend(
        string $class,
        mixed $value,
        ?string $oldValue = null,
        array $inUse = []
    ): Locations|LocationTexts {
        $context = $this->createMock(Context::class);
        $context->method('getEventDispatcher')->willReturn($this->createMock(\Magento\Framework\Event\ManagerInterface::class));
        $context->method('getCacheManager')->willReturn($this->createMock(\Magento\Framework\App\CacheInterface::class));
        $context->method('getLogger')->willReturn($this->createMock(\Psr\Log\LoggerInterface::class));
        $context->method('getActionValidator')->willReturn(
            $this->createMock(\Magento\Framework\Model\ActionValidator\RemoveAction::class)
        );

        $config = $this->createMock(ScopeConfigInterface::class);
        $config->method('getValue')->willReturn($oldValue);
        $usage = $this->createMock(LocationUsageInterface::class);
        $usage->method('isInUse')->willReturnCallback(
            static fn (string $code): bool => in_array($code, $inUse, true)
        );

        $common = [
            $context,
            $this->createMock(Registry::class),
            $config,
            $this->createMock(TypeListInterface::class),
            new PickupMethodResolver(),
        ];
        $tail = [null, null, [], new Json()];
        $backend = $class === Locations::class
            ? new Locations(...$common, ...[$usage, new Json()], ...$tail)
            : new LocationTexts(...$common, ...$tail);
        $backend->setValue($value);

        return $backend;
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, string>
     */
    private function row(string $code, array $overrides = []): array
    {
        return $overrides + [
            'code' => $code,
            'name' => 'Main Store',
            'street' => '1 Example Street',
            'postcode' => '1010',
            'city' => 'Vienna',
            'country_id' => 'AT',
            'active' => '1',
        ];
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidCodeProvider(): array
    {
        return [
            'empty' => [''],
            'uppercase' => ['Main'],
            'space' => ['main store'],
            'umlaut' => ['café'],
            'hyphen' => ['main-1'],
            '65 chars' => [str_repeat('a', 65)],
            'trailing newline' => ["main\n"],
        ];
    }

    /**
     * @dataProvider invalidCodeProvider
     */
    public function testInvalidCodeIsRejected(string $code): void
    {
        $backend = $this->backend(Locations::class, ['_1' => $this->row($code)]);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage(
            sprintf('Pickup location code "%s" must match ^[a-z0-9_]{1,64}$.', $code)
        );
        $backend->beforeSave();
    }

    public function testDuplicateCodeIsRejected(): void
    {
        $backend = $this->backend(Locations::class, ['_1' => $this->row('main'), '_2' => $this->row('main')]);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Pickup location code "main" is used twice.');
        $backend->beforeSave();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function requiredColumnProvider(): array
    {
        return [
            'name' => ['name'], 'street' => ['street'], 'postcode' => ['postcode'],
            'city' => ['city'], 'country' => ['country_id'],
        ];
    }

    /**
     * @dataProvider requiredColumnProvider
     */
    public function testRowWithoutRequiredValueIsRejected(string $column): void
    {
        $backend = $this->backend(Locations::class, ['_1' => $this->row('main', [$column => '  '])]);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Pickup location "main" needs a name, street, postcode, city and country.');
        $backend->beforeSave();
    }

    public function testMissingColumnIsRejected(): void
    {
        $row = $this->row('main');
        unset($row['city']);
        $backend = $this->backend(Locations::class, ['_1' => $row]);

        $this->expectException(LocalizedException::class);
        $backend->beforeSave();
    }

    public function testValidRowsAreSerialized(): void
    {
        $rows = ['_1' => $this->row('main'), '_2' => $this->row('second_2'), '__empty' => ''];
        $backend = $this->backend(Locations::class, $rows);

        $backend->beforeSave();

        $this->assertSame(
            ['_1' => $this->row('main'), '_2' => $this->row('second_2')],
            (new Json())->unserialize($backend->getValue())
        );
    }

    public function testNonArrayValueIsLeftAlone(): void
    {
        $backend = $this->backend(Locations::class, '');

        $backend->beforeSave();

        $this->assertSame('', $backend->getValue());
    }

    /**
     * @dataProvider invalidCodeProvider
     */
    public function testTextsRejectInvalidCode(string $code): void
    {
        $backend = $this->backend(LocationTexts::class, ['_1' => ['code' => $code, 'name' => 'Negozio']]);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage(
            sprintf('Pickup location code "%s" must match ^[a-z0-9_]{1,64}$.', $code)
        );
        $backend->beforeSave();
    }

    public function testTextsOnlyCheckTheCode(): void
    {
        $rows = ['_1' => ['code' => 'main', 'name' => '', 'opening_hours' => '', 'note' => ''], '__empty' => ''];
        $backend = $this->backend(LocationTexts::class, $rows);

        $backend->beforeSave();

        $this->assertSame(
            ['_1' => ['code' => 'main', 'name' => '', 'opening_hours' => '', 'note' => '']],
            (new Json())->unserialize($backend->getValue())
        );
    }

    public function testTextsWithoutCodeAreRejected(): void
    {
        $backend = $this->backend(LocationTexts::class, ['_1' => ['name' => 'Negozio']]);

        $this->expectException(LocalizedException::class);
        $backend->beforeSave();
    }

    public function testRowsAfterTheEmptyMarkerAreStillChecked(): void
    {
        $backend = $this->backend(Locations::class, ['__empty' => '', '_1' => $this->row('Bad')]);

        $this->expectException(LocalizedException::class);
        $backend->beforeSave();
    }

    public function testRowsAfterANonRowEntryAreStillChecked(): void
    {
        $backend = $this->backend(Locations::class, ['_0' => 'junk', '_1' => $this->row('Bad')]);

        $this->expectException(LocalizedException::class);
        $backend->beforeSave();
    }

    public function testNonRowEntryIsSkipped(): void
    {
        $backend = $this->backend(Locations::class, ['_0' => 'junk', '_1' => $this->row('main')]);

        $backend->beforeSave();

        $this->assertArrayHasKey('_1', (new Json())->unserialize($backend->getValue()));
    }

    public function testNumericCodeIsAccepted(): void
    {
        $backend = $this->backend(Locations::class, ['_1' => $this->row('7')]);
        $backend->setValue(['_1' => ['code' => 7] + $this->row('7')]);

        $backend->beforeSave();

        $this->assertIsString($backend->getValue());
    }

    public function testTextsRowsAfterTheEmptyMarkerAreStillChecked(): void
    {
        $backend = $this->backend(LocationTexts::class, ['__empty' => '', '_1' => ['code' => 'Bad']]);

        $this->expectException(LocalizedException::class);
        $backend->beforeSave();
    }

    public function testTextsRejectANonRowEntry(): void
    {
        $backend = $this->backend(LocationTexts::class, ['_0' => 'junk']);

        $this->expectException(LocalizedException::class);
        $backend->beforeSave();
    }

    public function testTextsNumericCodeIsAccepted(): void
    {
        $backend = $this->backend(LocationTexts::class, ['_1' => ['code' => 7]]);

        $backend->beforeSave();

        $this->assertIsString($backend->getValue());
    }

    /**
     * @param array<string, array<string, string>> $rows
     */
    private function stored(array $rows): string
    {
        return (new Json())->serialize($rows);
    }

    public function testRemovingACodeInUseIsRejected(): void
    {
        $old = $this->stored(['_1' => $this->row('main'), '_2' => $this->row('second')]);
        $backend = $this->backend(Locations::class, ['_2' => $this->row('second')], $old, ['main']);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage(
            'Pickup location "main" is used by open carts or orders. '
            . 'Deactivate it instead of removing or renaming it.'
        );
        $backend->beforeSave();
    }

    public function testRenamingTheCodeOfALocationInUseIsRejected(): void
    {
        $old = $this->stored(['_1' => $this->row('main')]);
        $backend = $this->backend(Locations::class, ['_1' => $this->row('central')], $old, ['main']);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Pickup location "main" is used by open carts or orders.');
        $backend->beforeSave();
    }

    public function testDeactivatingACodeInUseIsSaved(): void
    {
        $old = $this->stored(['_1' => $this->row('main')]);
        $backend = $this->backend(Locations::class, ['_1' => $this->row('main', ['active' => '0'])], $old, ['main']);

        $backend->beforeSave();

        $this->assertIsString($backend->getValue());
    }

    public function testRemovingAnUnusedCodeIsSaved(): void
    {
        $old = $this->stored(['_1' => $this->row('main'), '_2' => $this->row('second')]);
        $backend = $this->backend(Locations::class, ['_1' => $this->row('main')], $old, ['main']);

        $backend->beforeSave();

        $this->assertIsString($backend->getValue());
    }

    public function testRenamingOnlyTheTitleIsSaved(): void
    {
        $old = $this->stored(['_1' => $this->row('main')]);
        $backend = $this->backend(Locations::class, ['_1' => $this->row('main', ['name' => 'Central'])], $old, ['main']);

        $backend->beforeSave();

        $this->assertIsString($backend->getValue());
    }

    public function testNumericCodeInUseIsProtected(): void
    {
        $old = $this->stored(['_1' => ['code' => 7] + $this->row('7')]);
        $backend = $this->backend(Locations::class, ['_2' => $this->row('8')], $old, ['7']);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Pickup location "7" is used by open carts or orders.');
        $backend->beforeSave();
    }

    public function testUnreadableOldValueDoesNotBlockTheSave(): void
    {
        $backend = $this->backend(Locations::class, ['_1' => $this->row('main')], '{broken', ['main']);

        $backend->beforeSave();

        $this->assertIsString($backend->getValue());
    }

    public function testEmptyOldValueDoesNotBlockTheSave(): void
    {
        $backend = $this->backend(Locations::class, ['_1' => $this->row('main')], '', ['main']);

        $backend->beforeSave();

        $this->assertIsString($backend->getValue());
    }

    public function testOldRowsWithoutACodeAreIgnored(): void
    {
        $old = $this->stored(['_0' => ['name' => 'No code'], '_1' => $this->row('main')]);
        $backend = $this->backend(Locations::class, ['_1' => $this->row('main')], $old, ['main']);

        $backend->beforeSave();

        $this->assertIsString($backend->getValue());
    }
}
