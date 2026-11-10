<?php declare(strict_types=1);

namespace PixelPerfect\StorePickup\Model\ResourceModel;

use Magento\Framework\App\ResourceConnection;
use Magento\Sales\Model\Order;

/**
 * Counts the quotes and orders that carry a pickup location code.
 */
class LocationUsage
{
    /**
     * Order states that no longer need the location.
     *
     * @var list<string>
     */
    private readonly array $finishedStates;

    /**
     * Constructor.
     *
     * @param ResourceConnection $resourceConnection
     * @param list<string> $finishedStates
     */
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        array $finishedStates = [Order::STATE_COMPLETE, Order::STATE_CLOSED, Order::STATE_CANCELED]
    ) {
        $this->finishedStates = $finishedStates;
    }

    /**
     * Number of active quotes with the code.
     *
     * @param string $code
     * @return int
     */
    public function countOpenQuotes(string $code): int
    {
        $connection = $this->resourceConnection->getConnection('checkout');
        $select = $connection->select()
            ->from($this->resourceConnection->getTableName('quote', 'checkout'), ['COUNT(*)'])
            ->where('pp_pickup_location_code = ?', $code)
            ->where('is_active = ?', 1);

        return (int) $connection->fetchOne($select);
    }

    /**
     * Number of orders with the code whose state is not finished.
     *
     * @param string $code
     * @return int
     */
    public function countOpenOrders(string $code): int
    {
        $connection = $this->resourceConnection->getConnection('sales');
        $select = $connection->select()
            ->from($this->resourceConnection->getTableName('sales_order', 'sales'), ['COUNT(*)'])
            ->where('pp_pickup_location_code = ?', $code)
            ->where('state NOT IN (?)', $this->finishedStates);

        return (int) $connection->fetchOne($select);
    }
}
