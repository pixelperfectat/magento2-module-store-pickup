<?php declare(strict_types=1);

namespace PixelPerfect\StorePickup\Service;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use PixelPerfect\StorePickup\Api\Service\ConfigInterface;

class Config implements ConfigInterface
{
    /**
     * Constructor.
     *
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * @inheritDoc
     */
    public function isActive(?int $storeId = null): bool
    {
        return $this->flag(self::XML_PATH_ACTIVE, $storeId);
    }

    /**
     * @inheritDoc
     */
    public function getTitle(?int $storeId = null): string
    {
        return $this->string(self::XML_PATH_TITLE, $storeId);
    }

    /**
     * @inheritDoc
     */
    public function getDefaultPrice(?int $storeId = null): float
    {
        return (float) $this->string(self::XML_PATH_PRICE, $storeId);
    }

    /**
     * @inheritDoc
     */
    public function isReadyEmailEnabled(?int $storeId = null): bool
    {
        return $this->flag(self::XML_PATH_READY_EMAIL_ENABLED, $storeId);
    }

    /**
     * @inheritDoc
     */
    public function getReadyEmailIdentity(?int $storeId = null): string
    {
        return $this->string(self::XML_PATH_READY_EMAIL_IDENTITY, $storeId);
    }

    /**
     * @inheritDoc
     */
    public function getReadyEmailTemplate(bool $guest, ?int $storeId = null): string
    {
        return $this->string(
            $guest ? self::XML_PATH_READY_EMAIL_GUEST_TEMPLATE : self::XML_PATH_READY_EMAIL_TEMPLATE,
            $storeId
        );
    }

    /**
     * @inheritDoc
     */
    public function getReadyEmailCopyTo(?int $storeId = null): array
    {
        return $this->list(self::XML_PATH_READY_EMAIL_COPY_TO, $storeId);
    }

    /**
     * @inheritDoc
     */
    public function getReadyEmailCopyMethod(?int $storeId = null): string
    {
        return $this->string(self::XML_PATH_READY_EMAIL_COPY_METHOD, $storeId);
    }

    /**
     * @inheritDoc
     */
    public function getPickupOnlyPaymentMethods(?int $storeId = null): array
    {
        return $this->list(self::XML_PATH_PICKUP_ONLY_METHODS, $storeId);
    }

    /**
     * @inheritDoc
     */
    public function getHiddenForPickupPaymentMethods(?int $storeId = null): array
    {
        return $this->list(self::XML_PATH_HIDDEN_FOR_PICKUP, $storeId);
    }

    /**
     * Reads a yes/no value.
     *
     * @param string $path
     * @param int|null $storeId
     * @return bool
     */
    private function flag(string $path, ?int $storeId): bool
    {
        return $this->scopeConfig->isSetFlag($path, ScopeInterface::SCOPE_STORE, $storeId);
    }

    /**
     * Reads a scalar value as string; anything else yields an empty string.
     *
     * @param string $path
     * @param int|null $storeId
     * @return string
     */
    private function string(string $path, ?int $storeId): string
    {
        $value = $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE, $storeId);

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * Splits a comma-separated value (text field or multiselect), trimming and dropping empty entries.
     *
     * @param string $path
     * @param int|null $storeId
     * @return list<string>
     */
    private function list(string $path, ?int $storeId): array
    {
        $items = [];
        foreach (explode(',', $this->string($path, $storeId)) as $item) {
            $item = trim($item);
            if ($item !== '') {
                $items[] = $item;
            }
        }

        return $items;
    }
}
