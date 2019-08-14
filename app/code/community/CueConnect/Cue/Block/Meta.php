<?php
/**
 * CueConnect_Cue
 * 
 * @category    CueConnect
 * @package     CueConnect_Cue
 * @copyright   Copyright (c) 2015 Cue Connect
 * @author      Cue Connect (http://www.cueconnect.com)
 * @license     http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

class CueConnect_Cue_Block_Meta extends CueConnect_Cue_Block_BaseCueBlock {
    
    /**
     * return current product information in order to feed the meta tags
     * @return array
     */
    public function getProductData() {
        $current_product = Mage::registry('current_product');
        if ($current_product) {
            $width = Mage::getStoreConfig('cueconnect/image/width');
            $height = Mage::getStoreConfig('cueconnect/image/height');

            return array(
                'name'          => $this->escape($current_product->getName()),
                'description'   => $this->escape(strip_tags(($current_product->getShortDescription()))),
                'sku'           => $this->escape($current_product->getSku()),
                'brand'         => $this->escape(($current_product->getAttributeText('manufacturer')) ? $current_product->getAttributeText('manufacturer') : Mage::app()->getStore()->getName()),
                'price'         => $this->escape(number_format(Mage::helper('core')->currency($current_product->getPrice(), false, false), 2)),
                'currency'      => $this->escape(Mage::app()->getStore(Mage::app()->getStore()->getId())->getCurrentCurrencyCode()),
                'picture'       => $this->escape($this->helper('catalog/image')->init($current_product, 'small_image')->resize($width, $height)),
                'url'           => $this->escape($current_product->getProductUrl()),
            );
        }

        return array();
    }


    protected function escape($str)
    {
        return addslashes($this->htmlEscape($str));
    }
}
