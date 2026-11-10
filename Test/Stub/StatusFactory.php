<?php declare(strict_types=1);

namespace Magento\Sales\Model\Order {
    if (!class_exists(StatusFactory::class)) {
        /**
         * Test stand-in for the auto-generated factory, which does not exist in a standalone checkout.
         */
        class StatusFactory
        {
            /**
             * @param array<string, mixed> $data
             * @return Status
             */
            public function create(array $data = []): Status
            {
                throw new \LogicException('Stub; mock this method.');
            }
        }
    }
}
