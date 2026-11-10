<?php declare(strict_types=1);

namespace PixelPerfect\StorePickup\Test\Unit\I18n;

use Magento\Framework\File\Csv;
use PHPUnit\Framework\TestCase;

class TranslationFilesTest extends TestCase
{
    private const LOCALES = ['en_US', 'de_DE', 'it_IT'];

    /**
     * @return array<string, string> source => translation
     */
    private function load(string $locale): array
    {
        $file = dirname(__DIR__, 3) . '/src/i18n/' . $locale . '.csv';
        $this->assertFileExists($file);

        $csv = new Csv(new \Magento\Framework\Filesystem\Driver\File());
        $map = [];
        foreach ($csv->getData($file) as $row) {
            $this->assertCount(2, $row, $locale . ' has a row that is not source,translation: ' . implode('|', $row));
            $map[$row[0]] = $row[1];
        }

        return $map;
    }

    public function testAllLocalesHaveTheSameKeys(): void
    {
        $reference = array_keys($this->load('en_US'));
        sort($reference);
        $this->assertNotEmpty($reference);

        foreach (self::LOCALES as $locale) {
            $keys = array_keys($this->load($locale));
            sort($keys);
            $this->assertSame($reference, $keys, $locale . ' keys differ from en_US');
        }
    }

    public function testNoTranslationIsEmpty(): void
    {
        foreach (self::LOCALES as $locale) {
            foreach ($this->load($locale) as $source => $translation) {
                $this->assertNotSame('', trim($translation), $locale . ' has an empty translation for "' . $source . '"');
            }
        }
    }
}
