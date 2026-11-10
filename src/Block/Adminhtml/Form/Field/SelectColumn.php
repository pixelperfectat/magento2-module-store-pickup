<?php declare(strict_types=1);

namespace PixelPerfect\StorePickup\Block\Adminhtml\Form\Field;

use Magento\Framework\View\Element\Html\Select;

/**
 * Select cell of an admin grid field.
 */
class SelectColumn extends Select
{
    /**
     * Name given by the grid.
     *
     * @param string $value
     * @return $this
     */
    public function setInputName(string $value): self
    {
        $this->setName($value);

        return $this;
    }

    /**
     * Id given by the grid.
     *
     * @param string $value
     * @return $this
     */
    public function setInputId(string $value): self
    {
        $this->setId($value);

        return $this;
    }
}
