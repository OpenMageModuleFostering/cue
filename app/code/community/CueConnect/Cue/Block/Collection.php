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

class CueConnect_Cue_Block_Collection extends CueConnect_Cue_Block_BaseCueBlock {
    public function isCollectionEnabled() {
	    $store = Mage::app()->getStore();
	    if ($store->getConfig('cueconnect/collection/enabled') && $store->getConfig('cueconnect/collection/enabled')) {
            return true;
        }
        else {
            return false;
        }
    }
    
}
