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

class CueConnect_Cue_Block_Adminhtml_Catalog_Product extends Mage_Adminhtml_Block_Catalog_Product {
    
    /**
     * Prepare button and grid
     *
     * @return Mage_Adminhtml_Block_Catalog_Product
     */
    protected function _prepareLayout()
    {
        $this->_addButton('cueconnect_sync_catalog', array(
            'label'   => Mage::helper('catalog')->__('Export catalog to Cue'),
            'onclick' => "setLocation('{$this->getUrl('cueconnect/adminhtml_sync/export')}')",
            'class'   => ''
        ));
        
        return parent::_prepareLayout();
    }
    
}