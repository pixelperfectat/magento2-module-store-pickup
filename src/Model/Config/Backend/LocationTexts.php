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
 * Validates the codes of the store-view text rows; the texts themselves are free.
 */
class LocationTexts extends ArraySerialized
{
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
     * Reject rows whose code does not match the location code pattern.
     *
     * @return $this
     * @throws LocalizedException
     */
    public function beforeSave()
    {
        $rows = $this->getValue();
        if (is_array($rows)) {
            foreach ($rows as $key => $row) {
                if ($key === '__empty') {
                    continue;
                }
                $code = is_array($row) && is_scalar($row['code'] ?? null) ? (string) $row['code'] : '';
                if (!$this->resolver->isValidLocationCode($code)) {
                    throw new LocalizedException(
                        __('Pickup location code "%1" must match ^[a-z0-9_]{1,64}$.', $code)
                    );
                }
            }
        }

        return parent::beforeSave();
    }
}
