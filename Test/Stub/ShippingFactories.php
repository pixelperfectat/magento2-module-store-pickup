<?php declare(strict_types=1);

namespace Magento\Shipping\Model\Rate {
    if (!class_exists(ResultFactory::class)) {
        /**
         * Test stand-in for the auto-generated factory, which does not exist in a standalone checkout.
         */
        class ResultFactory
        {
            /**
             * @param array<string, mixed> $data
             * @return Result
             */
            public function create(array $data = []): Result
            {
                throw new \LogicException('Stub; mock this method.');
            }
        }
    }
}

namespace Magento\Quote\Model\Quote\Address\RateResult {
    if (!class_exists(MethodFactory::class)) {
        /**
         * Test stand-in for the auto-generated factory, which does not exist in a standalone checkout.
         */
        class MethodFactory
        {
            /**
             * @param array<string, mixed> $data
             * @return Method
             */
            public function create(array $data = []): Method
            {
                throw new \LogicException('Stub; mock this method.');
            }
        }
    }

    if (!class_exists(ErrorFactory::class)) {
        /**
         * Test stand-in for the auto-generated factory, which does not exist in a standalone checkout.
         */
        class ErrorFactory
        {
            /**
             * @param array<string, mixed> $data
             * @return Error
             */
            public function create(array $data = []): Error
            {
                throw new \LogicException('Stub; mock this method.');
            }
        }
    }
}
