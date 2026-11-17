<?php declare(strict_types=1);

namespace PixelPerfect\StorePickup\Block\Adminhtml\Form\Field;

use Magento\Framework\View\Element\AbstractBlock;

/**
 * Multi-line cell of an admin grid field, for street lines, opening hours and notes.
 *
 * @method string getInputName()
 * @method string getInputId()
 * @method string getColumnName()
 */
class TextareaColumn extends AbstractBlock
{
    /**
     * @inheritDoc
     */
    protected function _toHtml(): string
    {
        // Id and name carry the grid's "<%- _id %>" template placeholder, which escaping would break.
        return '<textarea id="' . $this->getInputId() . '"'
            . ' name="' . $this->getInputName() . '"'
            . ' class="input-text" rows="3" style="min-width: 12em;"'
            . '><%- ' . $this->getColumnName() . ' %></textarea>';
    }
}
