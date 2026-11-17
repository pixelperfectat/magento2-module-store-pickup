<?php declare(strict_types=1);

namespace PixelPerfect\StorePickup\Model\Config\Backend;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Serialize\SerializerInterface;

/**
 * Turns the JSON string that `config:set`, `config:import` and code saves hand to a grid backend model into rows.
 *
 * The admin form submits an array; every other write path submits the serialized text, which would otherwise be
 * stored without validation.
 */
trait DecodesStringValue
{
    /**
     * Replace a string value with the rows it encodes; arrays are left alone.
     *
     * @param SerializerInterface $serializer
     * @return void
     * @throws LocalizedException When the string is not a serialized list of rows.
     */
    private function decodeStringValue(SerializerInterface $serializer): void
    {
        $value = $this->getValue();
        if ($value === null || $value === '') {
            $this->setValue([]);

            return;
        }
        if (!is_string($value)) {
            return;
        }
        try {
            $rows = $serializer->unserialize($value);
        } catch (\InvalidArgumentException) {
            throw new LocalizedException(__('The pickup location rows are not valid JSON.'));
        }
        if (!is_array($rows)) {
            throw new LocalizedException(__('The pickup location rows must be a JSON list of rows.'));
        }
        $this->setValue($rows);
    }
}
