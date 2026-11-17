<?php declare(strict_types=1);

namespace PixelPerfect\StorePickup\Test\Unit\Model\Config\Backend;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Model\StoreManagerInterface;
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
     * @param array<string, string> $scopeValues stored value per config scope type, falls back to $oldValue
     * @return Locations|LocationTexts
     */
    private function backend(
        string $class,
        mixed $value,
        ?string $oldValue = null,
        array $inUse = [],
        array $scopeValues = []
    ): Locations|LocationTexts {
        $context = $this->createMock(Context::class);
        $context->method('getEventDispatcher')->willReturn($this->createMock(\Magento\Framework\Event\ManagerInterface::class));
        $context->method('getCacheManager')->willReturn($this->createMock(\Magento\Framework\App\CacheInterface::class));
        $context->method('getLogger')->willReturn($this->createMock(\Psr\Log\LoggerInterface::class));
        $validator = $this->createMock(\Magento\Framework\Model\ActionValidator\RemoveAction::class);
        $validator->method('isAllowed')->willReturn(true);
        $context->method('getActionValidator')->willReturn($validator);

        $config = $this->createMock(ScopeConfigInterface::class);
        $config->method('getValue')->willReturnCallback(
            static fn (?string $path, ?string $scope = null): ?string => $scopeValues[$scope] ?? $oldValue
        );
        $website = $this->createMock(WebsiteInterface::class);
        $website->method('getCode')->willReturn('w1');
        $store = $this->createMock(StoreInterface::class);
        $store->method('getWebsiteId')->willReturn(5);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);
        $storeManager->method('getWebsite')->willReturn($website);
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
            ? new Locations(...$common, ...[$usage, new Json(), $storeManager], ...$tail)
            : new LocationTexts(...$common, ...[new Json()], ...$tail);
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

    public function testEmptyStringBecomesNoRows(): void
    {
        $backend = $this->backend(Locations::class, '');

        $backend->beforeSave();

        $this->assertSame('[]', $backend->getValue());
    }

    public function testNullBecomesNoRows(): void
    {
        $backend = $this->backend(Locations::class, null);

        $backend->beforeSave();

        $this->assertSame('[]', $backend->getValue());
    }

    public function testJsonStringIsValidatedAndSaved(): void
    {
        $json = $this->stored([$this->row('main')]);
        $backend = $this->backend(Locations::class, $json);

        $backend->beforeSave();

        $this->assertSame($json, $backend->getValue());
    }

    public function testJsonStringWithInvalidCodeIsRejected(): void
    {
        $backend = $this->backend(Locations::class, $this->stored([$this->row('Bad Code')]));

        $this->expectException(LocalizedException::class);
        $backend->beforeSave();
    }

    public function testInvalidJsonIsRejected(): void
    {
        $backend = $this->backend(Locations::class, '{not json');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('The pickup location rows are not valid JSON.');
        $backend->beforeSave();
    }

    public function testJsonScalarIsRejected(): void
    {
        $backend = $this->backend(Locations::class, '"text"');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('The pickup location rows must be a JSON list of rows.');
        $backend->beforeSave();
    }

    public function testJsonStringThatRemovesACodeInUseIsRejected(): void
    {
        $old = $this->stored([$this->row('main'), $this->row('second')]);
        $backend = $this->backend(Locations::class, $this->stored([$this->row('second')]), $old, ['main']);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Pickup location "main" is used by open carts or orders.');
        $backend->beforeSave();
    }

    public function testEmptyStringThatRemovesACodeInUseIsRejected(): void
    {
        $backend = $this->backend(Locations::class, '', $this->stored([$this->row('main')]), ['main']);

        $this->expectException(LocalizedException::class);
        $backend->beforeSave();
    }

    public function testTextsJsonStringIsValidatedAndSaved(): void
    {
        $json = $this->stored([['code' => 'main', 'name' => 'Negozio']]);
        $backend = $this->backend(LocationTexts::class, $json);

        $backend->beforeSave();

        $this->assertSame($json, $backend->getValue());
    }

    public function testTextsJsonStringWithInvalidCodeIsRejected(): void
    {
        $backend = $this->backend(LocationTexts::class, $this->stored([['code' => 'Bad Code']]));

        $this->expectException(LocalizedException::class);
        $backend->beforeSave();
    }

    public function testTextsInvalidJsonIsRejected(): void
    {
        $backend = $this->backend(LocationTexts::class, '{not json');

        $this->expectException(LocalizedException::class);
        $backend->beforeSave();
    }

    public function testTextsEmptyStringPasses(): void
    {
        $backend = $this->backend(LocationTexts::class, '');

        $backend->beforeSave();

        $this->assertSame('[]', $backend->getValue());
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

    /**
     * @param string $scope
     * @param array<string, string> $scopeValues
     * @param list<string> $inUse
     */
    private function deletion(string $scope, array $scopeValues, array $inUse): Locations
    {
        $backend = $this->backend(Locations::class, null, $scopeValues[$scope], $inUse, $scopeValues);
        $this->assertInstanceOf(Locations::class, $backend);
        $backend->setPath('carriers/pppickup/locations');
        $backend->setScope($scope);
        $backend->setScopeCode('x');

        return $backend;
    }

    public function testRevertingAWebsiteToDefaultIsBlockedWhenAnInUseCodeVanishes(): void
    {
        $backend = $this->deletion(
            'websites',
            ['websites' => $this->stored(['_1' => $this->row('main')]), 'default' => $this->stored([])],
            ['main']
        );

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Pickup location "main" is used by open carts or orders.');
        $backend->beforeDelete();
    }

    public function testRevertingAWebsiteToDefaultPassesForAnUnusedCode(): void
    {
        $backend = $this->deletion(
            'websites',
            ['websites' => $this->stored(['_1' => $this->row('main')]), 'default' => $this->stored([])],
            []
        );

        $this->assertSame($backend, $backend->beforeDelete());
    }

    public function testRevertingAWebsiteToDefaultPassesWhenDefaultStillHasTheCode(): void
    {
        $stored = $this->stored(['_1' => $this->row('main')]);
        $backend = $this->deletion('websites', ['websites' => $stored, 'default' => $stored], ['main']);

        $this->assertSame($backend, $backend->beforeDelete());
    }

    public function testRevertingAStoreComparesWithTheWebsiteValue(): void
    {
        $backend = $this->deletion(
            'stores',
            ['stores' => $this->stored(['_1' => $this->row('main')]), 'websites' => $this->stored([])],
            ['main']
        );

        $this->expectException(LocalizedException::class);
        $backend->beforeDelete();
    }

    public function testDeletingAtDefaultScopeIsNotGuarded(): void
    {
        $backend = $this->deletion('default', ['default' => $this->stored(['_1' => $this->row('main')])], ['main']);

        $this->assertSame($backend, $backend->beforeDelete());
    }

    public function testWebsiteSaveReadsTheOldValueAtTheWebsiteScope(): void
    {
        $backend = $this->backend(
            Locations::class,
            ['_1' => $this->row('second')],
            $this->stored([]),
            ['main'],
            ['websites' => $this->stored(['_1' => $this->row('main')])]
        );
        $backend->setScope('websites');

        $this->expectException(LocalizedException::class);
        $backend->beforeSave();
    }
}
