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
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Store\Model\StoreManagerInterface;
use PixelPerfect\StorePickup\Api\Service\LocationUsageInterface;
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
     * @param LocationUsageInterface $usage
     * @param SerializerInterface $oldValueSerializer
     * @param StoreManagerInterface $storeManager
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
        private readonly LocationUsageInterface $usage,
        private readonly SerializerInterface $oldValueSerializer,
        private readonly StoreManagerInterface $storeManager,
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
     * Reject rows with an invalid or repeated code, with missing address data, or without a code that is still in use.
     *
     * @return $this
     * @throws LocalizedException
     */
    public function beforeSave()
    {
        $rows = $this->getValue();
        if (is_array($rows)) {
            $this->validate($rows);
            $this->guardCodesInUse($rows);
        }

        return parent::beforeSave();
    }

    /**
     * Reject reverting a website or store override to "Use default" when that drops a code that is still in use.
     *
     * The admin deletes the config row for an inherited field and never calls beforeSave, so the codes that the
     * deleted override held are compared with the codes the parent scope serves after the delete.
     *
     * @return $this
     * @throws LocalizedException
     */
    public function beforeDelete()
    {
        $parent = $this->parentRows();
        if ($parent !== null) {
            $this->guardRemovedCodes($this->codes($this->oldRows()), $this->codes($parent));
        }

        return parent::beforeDelete();
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
     * Reject the removal (or rename) of a code that open carts or orders still use.
     *
     * @param array<array-key, mixed> $rows
     * @return void
     * @throws LocalizedException
     */
    private function guardCodesInUse(array $rows): void
    {
        $this->guardRemovedCodes($this->codes($this->oldRows()), $this->codes($rows));
    }

    /**
     * Throw for the first code that is in $before, missing from $after and in use.
     *
     * @param list<string> $before
     * @param list<string> $after
     * @return void
     * @throws LocalizedException
     */
    private function guardRemovedCodes(array $before, array $after): void
    {
        foreach ($before as $code) {
            if (!in_array($code, $after, true) && $this->usage->isInUse($code)) {
                throw new LocalizedException(
                    __(
                        'Pickup location "%1" is used by open carts or orders. '
                        . 'Deactivate it instead of removing or renaming it.',
                        $code
                    )
                );
            }
        }
    }

    /**
     * The rows the parent scope serves (website for a store, default for a website); null at default scope.
     *
     * @return array<array-key, mixed>|null
     */
    private function parentRows(): ?array
    {
        $scope = $this->getScope();
        if ($scope === 'websites') {
            $value = $this->_config->getValue($this->getPath(), 'default');
        } elseif ($scope === 'stores') {
            $websiteId = (int) $this->storeManager->getStore($this->getScopeCode())->getWebsiteId();
            $websiteCode = $this->storeManager->getWebsite($websiteId)->getCode();
            $value = $this->_config->getValue($this->getPath(), 'websites', $websiteCode);
        } else {
            return null;
        }

        $stored = is_array($value) ? $this->oldValueSerializer->serialize($value) : (string) $value;

        return $this->unserializeRows($stored);
    }

    /**
     * The rows saved before this change; empty when there are none or they cannot be read.
     *
     * @return array<array-key, mixed>
     */
    private function oldRows(): array
    {
        return $this->unserializeRows($this->getOldValue());
    }

    /**
     * Rows of a stored value; empty when it is empty or cannot be read.
     *
     * @param string $stored
     * @return array<array-key, mixed>
     */
    private function unserializeRows(string $stored): array
    {
        if ($stored === '') {
            return [];
        }
        try {
            $rows = $this->oldValueSerializer->unserialize($stored);
        } catch (\InvalidArgumentException) {
            return [];
        }

        return is_array($rows) ? $rows : [];
    }

    /**
     * Location codes of the rows, always as strings.
     *
     * @param array<array-key, mixed> $rows
     * @return list<string>
     */
    private function codes(array $rows): array
    {
        $codes = [];
        foreach ($rows as $row) {
            if (is_array($row) && is_scalar($row['code'] ?? null)) {
                $codes[] = (string) $row['code'];
            }
        }

        return $codes;
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
