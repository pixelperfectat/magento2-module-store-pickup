<?php declare(strict_types=1);

namespace PixelPerfect\StorePickup\Block\Adminhtml\Form\Field;

use Magento\Framework\Exception\LocalizedException;

/**
 * Grid of per-store-view texts for pickup locations in the carrier configuration.
 */
class LocationTexts extends AbstractGrid
{
    /**
     * Define the grid columns.
     *
     * @return void
     * @throws LocalizedException
     */
    protected function _prepareToRender(): void
    {
        $this->addColumn('code', ['label' => __('Code'), 'size' => 10]);
        $this->addColumn('name', ['label' => __('Name')]);
        $this->addColumn('opening_hours', ['label' => __('Opening Hours'), 'renderer' => $this->textarea()]);
        $this->addColumn('note', ['label' => __('Note'), 'renderer' => $this->textarea()]);
        $this->_addAfter = false;
        $this->_addButtonLabel = (string) __('Add Text');
    }

    /**
     * Create a multi-line column renderer.
     *
     * @return TextareaColumn
     * @throws LocalizedException
     */
    private function textarea(): TextareaColumn
    {
        /** @var TextareaColumn $block */
        $block = $this->getLayout()->createBlock(TextareaColumn::class, '', ['data' => []]);

        return $block;
    }
}
