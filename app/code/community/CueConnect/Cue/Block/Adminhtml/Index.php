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

class CueConnect_Cue_Block_Adminhtml_Index extends Mage_Adminhtml_Block_Template {
    
    /**
     * Get last export demands
     *
     * @return array
     */
    protected function getDemands()
    {
        $demands = Mage::getModel('cueconnect/demand')
                ->getCollection()
                ->setOrder('updated_at', 'desc')
                ->setPageSize(10);
        
        return $demands;
    }
    
}