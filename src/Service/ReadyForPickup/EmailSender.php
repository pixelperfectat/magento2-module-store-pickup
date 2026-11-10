<?php declare(strict_types=1);

namespace PixelPerfect\StorePickup\Service\ReadyForPickup;

use Magento\Framework\App\Area;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\DataObject;
use Magento\Framework\DataObjectFactory;
use Magento\Framework\Locale\ListsInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Container\IdentityInterface;
use Magento\Sales\Model\Order\Email\Container\Template;
use Magento\Sales\Model\Order\Email\SenderBuilderFactory;
use Magento\Store\Model\ScopeInterface;
use PixelPerfect\StorePickup\Api\Data\PickupLocationInterface;
use PixelPerfect\StorePickup\Api\Service\ReadyForPickupEmailSenderInterface;
use Psr\Log\LoggerInterface;

/**
 * Sends the ready-for-pickup email the way Magento sends its order emails (identity container and sender builder).
 */
class EmailSender implements ReadyForPickupEmailSenderInterface
{
    private const string XML_PATH_LOCALE = 'general/locale/code';

    /**
     * Constructor.
     *
     * @param Template $templateContainer
     * @param IdentityInterface $identityContainer
     * @param SenderBuilderFactory $senderBuilderFactory
     * @param DataObjectFactory $dataObjectFactory
     * @param ListsInterface $translatedLists
     * @param ScopeConfigInterface $scopeConfig
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly Template $templateContainer,
        private readonly IdentityInterface $identityContainer,
        private readonly SenderBuilderFactory $senderBuilderFactory,
        private readonly DataObjectFactory $dataObjectFactory,
        private readonly ListsInterface $translatedLists,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @inheritDoc
     */
    public function send(OrderInterface $order, PickupLocationInterface $location): bool
    {
        if (!$order instanceof Order) {
            $this->logger->error(
                'Ready-for-pickup email not sent: ' . $order::class . ' is no order model.'
            );

            return false;
        }

        $store = $order->getStore();
        $storeId = (int) $store->getId();
        $this->identityContainer->setStore($store);
        $this->templateContainer->setTemplateOptions(['area' => Area::AREA_FRONTEND, 'store' => $storeId]);
        $this->templateContainer->setTemplateVars([
            'order' => $order,
            'order_id' => (int) $order->getId(),
            'store' => $store,
            'location' => $this->locationVariable($location, $storeId),
            'order_data' => [
                'customer_name' => $order->getCustomerName(),
                'frontend_status_label' => $order->getFrontendStatusLabel(),
                'is_not_virtual' => $order->getIsNotVirtual(),
                'email_customer_note' => $order->getEmailCustomerNote(),
            ],
        ]);
        $this->templateContainer->setTemplateId(
            $order->getCustomerIsGuest()
                ? $this->identityContainer->getGuestTemplateId()
                : $this->identityContainer->getTemplateId()
        );
        $this->identityContainer->setCustomerName($order->getCustomerName());
        $this->identityContainer->setCustomerEmail($order->getCustomerEmail());

        $sender = $this->senderBuilderFactory->create([
            'templateContainer' => $this->templateContainer,
            'identityContainer' => $this->identityContainer,
        ]);
        try {
            $sender->send();
        } catch (\Throwable $e) {
            $this->logger->error(
                'Ready-for-pickup email for order ' . $order->getIncrementId() . ' failed: ' . $e->getMessage(),
                ['exception' => $e]
            );

            return false;
        }
        if ($this->identityContainer->getCopyMethod() === 'copy') {
            try {
                $sender->sendCopyTo();
            } catch (\Throwable $e) {
                $this->logger->error(
                    'Ready-for-pickup email copy for order ' . $order->getIncrementId() . ' failed: '
                    . $e->getMessage(),
                    ['exception' => $e]
                );
            }
        }

        return true;
    }

    /**
     * The location as template variable, with the country name in the locale of the store.
     *
     * @param PickupLocationInterface $location
     * @param int $storeId
     * @return DataObject
     */
    private function locationVariable(PickupLocationInterface $location, int $storeId): DataObject
    {
        $locale = $this->scopeConfig->getValue(self::XML_PATH_LOCALE, ScopeInterface::SCOPE_STORE, $storeId);
        $country = (string) $this->translatedLists->getCountryTranslation(
            $location->getCountryId(),
            is_string($locale) ? $locale : null
        );

        return $this->dataObjectFactory->create([
            'data' => [
                'name' => $location->getName(),
                'street' => implode(', ', $location->getStreet()),
                'postcode' => $location->getPostcode(),
                'city' => $location->getCity(),
                'country' => $country !== '' ? $country : $location->getCountryId(),
                'telephone' => $location->getTelephone(),
                'opening_hours' => $location->getOpeningHours(),
                'note' => $location->getNote(),
            ],
        ]);
    }
}
