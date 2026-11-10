<?php declare(strict_types=1);

namespace PixelPerfect\StorePickup\Test\Unit\I18n;

use Magento\Framework\File\Csv;
use Magento\Framework\Filesystem\Driver\File;
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

        $csv = new Csv(new File());
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

    public function testTheFailedEmailWarningIsTranslated(): void
    {
        $source = 'Order %1 was marked as ready for pickup, but the email could not be sent.';
        $expected = [
            'en_US' => $source,
            'de_DE' => 'Bestellung %1 wurde als abholbereit markiert, aber die E-Mail konnte nicht gesendet werden.',
            'it_IT' => "L'ordine %1 è stato segnato come pronto per il ritiro, ma non è stato possibile inviare l'e-mail.",
        ];

        foreach ($expected as $locale => $translation) {
            $this->assertSame($translation, $this->load($locale)[$source] ?? null, $locale);
        }
    }

    public function testTheFixRoundOneMessagesAreTranslated(): void
    {
        $expected = [
            'Order %1 could not be marked as ready for pickup. See the store pickup log.' => [
                'de_DE' => 'Bestellung %1 konnte nicht als abholbereit markiert werden. Details stehen im Store-Pickup-Log.',
                'it_IT' => "Non è stato possibile segnare l'ordine %1 come pronto per il ritiro. Dettagli nel log del ritiro in negozio.",
            ],
            'The order could not be found.' => [
                'de_DE' => 'Die Bestellung wurde nicht gefunden.',
                'it_IT' => "L'ordine non è stato trovato.",
            ],
        ];

        foreach ($expected as $source => $translations) {
            $translations['en_US'] = $source;
            foreach ($translations as $locale => $translation) {
                $this->assertSame($translation, $this->load($locale)[$source] ?? null, $locale . ': ' . $source);
            }
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
