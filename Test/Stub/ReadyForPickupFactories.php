<?php declare(strict_types=1);

namespace Magento\Sales\Api\Data {
    if (!class_exists(ShipmentCreationArgumentsInterfaceFactory::class)) {
        /**
         * Test stand-in for the auto-generated factory, which does not exist in a standalone checkout.
         */
        class ShipmentCreationArgumentsInterfaceFactory
        {
            /**
             * @param array<string, mixed> $data
             * @return ShipmentCreationArgumentsInterface
             */
            public function create(array $data = []): ShipmentCreationArgumentsInterface
            {
                throw new \LogicException('Stub; mock this method.');
            }
        }
    }
}

namespace Magento\Sales\Model\Order\Email {
    if (!class_exists(SenderBuilderFactory::class)) {
        /**
         * Test stand-in for the auto-generated factory, which does not exist in a standalone checkout.
         */
        class SenderBuilderFactory
        {
            /**
             * @param array<string, mixed> $data
             * @return SenderBuilder
             */
            public function create(array $data = []): SenderBuilder
            {
                throw new \LogicException('Stub; mock this method.');
            }
        }
    }
}

namespace Magento\Framework {
    if (!class_exists(DataObjectFactory::class)) {
        /**
         * Test stand-in for the auto-generated factory, which does not exist in a standalone checkout.
         */
        class DataObjectFactory
        {
            /**
             * Like the generated factory: the arguments are constructor arguments, so the values sit under "data".
             *
             * @param array<string, mixed> $data
             * @return DataObject
             */
            public function create(array $data = []): DataObject
            {
                return new DataObject($data['data'] ?? []);
            }
        }
    }
}
