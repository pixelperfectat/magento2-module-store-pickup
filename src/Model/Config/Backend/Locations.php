<?php declare(strict_types=1);

namespace PixelPerfect\StorePickup\Model\Config\Backend;

use Magento\Config\Model\Config\Backend\Serialized\ArraySerialized;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;
use Magento\Framework\Serialize\Serializer\Json;
use PixelPerfect\StorePickup\Api\Service\PickupMethodResolverInterface;

/**
 * Validates the pickup location rows before they are stored.
 */
class Locations extends ArraySerialized
{
    /**
     * Columns that every location row must fill.
     */
    private const REQUIRED_COLUMNS = ['name', 'street', 'postcode', 'city', 'country_id'];

    /**
     * Constructor.
     *
     * @param Context $context
     * @param Registry $registry
     * @param ScopeConfigInterface $config
     * @param TypeListInterface $cacheTypeList
     * @param PickupMethodResolverInterface $resolver
     * @param AbstractResource|null $resource
     * @param AbstractDb|null $resourceCollection
     * @param array<string, mixed> $data
     * @param Json|null $serializer
     */
    public function __construct(
        Context $context,
        Registry $registry,
        ScopeConfigInterface $config,
        TypeListInterface $cacheTypeList,
        private readonly PickupMethodResolverInterface $resolver,
        ?AbstractResource $resource = null,
        ?AbstractDb $resourceCollection = null,
        array $data = [],
        ?Json $serializer = null
    ) {
        parent::__construct(
            $context,
            $registry,
            $config,
            $cacheTypeList,
            $resource,
            $resourceCollection,
            $data,
            $serializer
        );
    }

    /**
     * Reject rows with an invalid or repeated code or with missing address data.
     *
     * @return $this
     * @throws LocalizedException
     */
    public function beforeSave()
    {
        $rows = $this->getValue();
        if (is_array($rows)) {
            $this->validate($rows);
        }

        return parent::beforeSave();
    }

    /**
     * Validate every row.
     *
     * @param array<array-key, mixed> $rows
     * @return void
     * @throws LocalizedException
     */
    private function validate(array $rows): void
    {
        $seen = [];
        foreach ($rows as $key => $row) {
            if ($key === '__empty' || !is_array($row)) {
                continue;
            }
            $code = is_scalar($row['code'] ?? null) ? (string) $row['code'] : '';
            if (!$this->resolver->isValidLocationCode($code)) {
                throw new LocalizedException(
                    __('Pickup location code "%1" must match ^[a-z0-9_]{1,64}$.', $code)
                );
            }
            if (in_array($code, $seen, true)) {
                throw new LocalizedException(__('Pickup location code "%1" is used twice.', $code));
            }
            $seen[] = $code;

            foreach (self::REQUIRED_COLUMNS as $column) {
                if ($this->cell($row, $column) === '') {
                    throw new LocalizedException(
                        __('Pickup location "%1" needs a name, street, postcode, city and country.', $code)
                    );
                }
            }
        }
    }

    /**
     * Trimmed string value of a row cell.
     *
     * @param array<array-key, mixed> $row
     * @param string $column
     * @return string
     */
    private function cell(array $row, string $column): string
    {
        $value = $row[$column] ?? '';

        return is_scalar($value) ? trim((string) $value) : '';
    }
}
