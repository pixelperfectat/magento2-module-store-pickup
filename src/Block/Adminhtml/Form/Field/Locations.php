<?php declare(strict_types=1);

namespace PixelPerfect\StorePickup\Block\Adminhtml\Form\Field;

use Magento\Backend\Block\Template\Context;
use Magento\Config\Model\Config\Source\Yesno;
use Magento\Directory\Model\Config\Source\Country;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;

/**
 * Grid of pickup locations in the carrier configuration.
 */
class Locations extends AbstractGrid
{
    /** @var array<string, SelectColumn> */
    private array $selects = [];

    /**
     * Constructor.
     *
     * @param Context $context
     * @param Country $countries
     * @param Yesno $yesNo
     * @param array<string, mixed> $data
     */
    public function __construct(
        Context $context,
        private readonly Country $countries,
        private readonly Yesno $yesNo,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

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
        $this->addColumn('street', ['label' => __('Street'), 'renderer' => $this->textarea()]);
        $this->addColumn('postcode', ['label' => __('Postcode'), 'size' => 8]);
        $this->addColumn('city', ['label' => __('City')]);
        $this->addColumn('country_id', ['label' => __('Country'), 'renderer' => $this->select('country_id')]);
        $this->addColumn('region_id', ['label' => __('Region ID'), 'size' => 5]);
        $this->addColumn('region', ['label' => __('Region')]);
        $this->addColumn('telephone', ['label' => __('Telephone'), 'size' => 12]);
        $this->addColumn('opening_hours', ['label' => __('Opening Hours'), 'renderer' => $this->textarea()]);
        $this->addColumn('note', ['label' => __('Note'), 'renderer' => $this->textarea()]);
        $this->addColumn('price', ['label' => __('Price'), 'size' => 6]);
        $this->addColumn('sort_order', ['label' => __('Sort Order'), 'size' => 4]);
        $this->addColumn('active', ['label' => __('Active'), 'renderer' => $this->select('active')]);
        $this->_addAfter = false;
        $this->_addButtonLabel = (string) __('Add Location');
    }

    /**
     * Preselect the stored option of the select columns.
     *
     * @param DataObject $row
     * @return void
     */
    protected function _prepareArrayRow(DataObject $row): void
    {
        $attrs = [];
        foreach ($this->selects as $column => $select) {
            $value = $row->getData($column);
            if ($value !== null) {
                $attrs['option_' . $select->calcOptionHash((string) $value)] = 'selected="selected"';
            }
        }
        $row->setData('option_extra_attrs', $attrs);
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

    /**
     * Create the select renderer of a column once.
     *
     * @param string $column
     * @return SelectColumn
     * @throws LocalizedException
     */
    private function select(string $column): SelectColumn
    {
        if (!isset($this->selects[$column])) {
            /** @var SelectColumn $block */
            $block = $this->getLayout()->createBlock(
                SelectColumn::class,
                '',
                ['data' => ['is_render_to_js_template' => true, 'class' => 'admin__control-select']]
            );
            $block->setOptions(
                $column === 'active' ? $this->yesNo->toOptionArray() : $this->countries->toOptionArray(false)
            );
            $this->selects[$column] = $block;
        }

        return $this->selects[$column];
    }
}
