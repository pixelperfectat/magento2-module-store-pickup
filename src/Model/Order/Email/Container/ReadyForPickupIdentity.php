<?php declare(strict_types=1);

namespace PixelPerfect\StorePickup\Model\Order\Email\Container;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Sales\Model\Order\Email\Container\Container;
use Magento\Sales\Model\Order\Email\Container\IdentityInterface;
use Magento\Store\Model\StoreManagerInterface;
use PixelPerfect\StorePickup\Api\Service\ConfigInterface;

/**
 * Sender identity, templates and copies of the ready-for-pickup email, read for the store of the order.
 */
class ReadyForPickupIdentity extends Container implements IdentityInterface
{
    /**
     * Constructor.
     *
     * @param ScopeConfigInterface $scopeConfig
     * @param StoreManagerInterface $storeManager
     * @param ConfigInterface $config
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        StoreManagerInterface $storeManager,
        private readonly ConfigInterface $config
    ) {
        parent::__construct($scopeConfig, $storeManager);
    }

    /**
     * Whether the email is enabled.
     *
     * @return bool
     */
    public function isEnabled(): bool
    {
        return $this->config->isReadyEmailEnabled($this->storeId());
    }

    /**
     * Copy recipients, or false when there are none (as the core identities return).
     *
     * @return list<string>|false
     */
    public function getEmailCopyTo(): array|false
    {
        return $this->config->getReadyEmailCopyTo($this->storeId()) ?: false;
    }

    /**
     * Copy method: bcc or copy (separate email).
     *
     * @return string
     */
    public function getCopyMethod(): string
    {
        return $this->config->getReadyEmailCopyMethod($this->storeId());
    }

    /**
     * Template id for guest orders.
     *
     * @return string
     */
    public function getGuestTemplateId(): string
    {
        return $this->config->getReadyEmailTemplate(true, $this->storeId());
    }

    /**
     * Template id for customer orders.
     *
     * @return string
     */
    public function getTemplateId(): string
    {
        return $this->config->getReadyEmailTemplate(false, $this->storeId());
    }

    /**
     * Sender identity code.
     *
     * @return string
     */
    public function getEmailIdentity(): string
    {
        return $this->config->getReadyEmailIdentity($this->storeId());
    }

    /**
     * Id of the store the email is sent for.
     *
     * @return int
     */
    private function storeId(): int
    {
        return (int) $this->getStore()->getId();
    }
}
