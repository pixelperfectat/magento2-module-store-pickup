<?php declare(strict_types=1);

namespace PixelPerfect\StorePickup\Block\Adminhtml\Form\Field;

use Magento\Config\Block\System\Config\Form\Field\FieldArray\AbstractFieldArray;
use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * Wide admin grid that scrolls sideways instead of squeezing the field label.
 */
abstract class AbstractGrid extends AbstractFieldArray
{
    /**
     * Wrap the grid in a horizontally scrolling box.
     *
     * @param AbstractElement $element
     * @return string
     */
    protected function _getElementHtml(AbstractElement $element)
    {
        return '<div style="display: block; width: 0; min-width: 100%; overflow-x: auto;">'
            . parent::_getElementHtml($element)
            . '</div>';
    }
}
