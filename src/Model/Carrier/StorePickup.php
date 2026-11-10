<?php declare(strict_types=1);

namespace PixelPerfect\StorePickup\Model\Carrier;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Quote\Model\Quote\Address\RateRequest;
use Magento\Quote\Model\Quote\Address\RateResult\ErrorFactory;
use Magento\Quote\Model\Quote\Address\RateResult\MethodFactory;
use Magento\Shipping\Model\Carrier\AbstractCarrier;
use Magento\Shipping\Model\Carrier\CarrierInterface;
use Magento\Shipping\Model\Rate\Result;
use Magento\Shipping\Model\Rate\ResultFactory;
use PixelPerfect\StorePickup\Api\Service\AvailabilityCheckerInterface;
use PixelPerfect\StorePickup\Api\Service\ConfigInterface;
use PixelPerfect\StorePickup\Api\Service\LocationProviderInterface;
use Psr\Log\LoggerInterface;

/**
 * Store pickup carrier: one rate per available pickup location, the method code being the location code.
 */
class StorePickup extends AbstractCarrier implements CarrierInterface
{
    /**
     * @var string
     */
    protected $_code = 'pppickup';

    /**
     * @var bool
     */
    protected $_isFixed = true;

    /**
     * Constructor.
     *
     * @param ScopeConfigInterface $scopeConfig
     * @param ErrorFactory $rateErrorFactory
     * @param LoggerInterface $logger
     * @param ResultFactory $rateResultFactory
     * @param MethodFactory $rateMethodFactory
     * @param LocationProviderInterface $locationProvider
     * @param AvailabilityCheckerInterface $availabilityChecker
     * @param ConfigInterface $pickupConfig
     * @param LoggerInterface $pickupLogger
     * @param array<string, mixed> $data
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        ErrorFactory $rateErrorFactory,
        LoggerInterface $logger,
        private readonly ResultFactory $rateResultFactory,
        private readonly MethodFactory $rateMethodFactory,
        private readonly LocationProviderInterface $locationProvider,
        private readonly AvailabilityCheckerInterface $availabilityChecker,
        private readonly ConfigInterface $pickupConfig,
        private readonly LoggerInterface $pickupLogger,
        array $data = []
    ) {
        parent::__construct($scopeConfig, $rateErrorFactory, $logger, $data);
    }

    /**
     * Collect one rate per available pickup location.
     *
     * @param RateRequest $request
     * @return Result|bool
     */
    public function collectRates(RateRequest $request)
    {
        $storeId = (int) $request->getStoreId();
        if (!$this->pickupConfig->isActive($storeId)) {
            return false;
        }

        $result = $this->rateResultFactory->create();
        $title = $this->pickupConfig->getTitle($storeId);
        $defaultPrice = $this->pickupConfig->getDefaultPrice($storeId);
        $free = (bool) $request->getFreeShipping();

        foreach ($this->locationProvider->getLocations($storeId) as $location) {
            if (!$this->availabilityChecker->isAvailable($location, $request)) {
                continue;
            }
            $price = $free ? 0.0 : ($location->getPrice() ?? $defaultPrice);

            $method = $this->rateMethodFactory->create();
            $method->setCarrier($this->_code);
            $method->setCarrierTitle($title);
            $method->setMethod($location->getCode());
            $method->setMethodTitle($location->getName());
            $method->setPrice($price);
            $method->setCost($price);
            $result->append($method);
        }

        return $result;
    }

    /**
     * Method code to name for the locations of the current store.
     *
     * @return array<string, string>
     */
    public function getAllowedMethods()
    {
        $methods = [];
        try {
            foreach ($this->locationProvider->getLocations((int) $this->getData('store')) as $location) {
                $methods[$location->getCode()] = $location->getName();
            }
        } catch (\Throwable $e) {
            $this->pickupLogger->error('Could not list the pickup locations: ' . $e->getMessage());
        }

        return $methods;
    }
}
