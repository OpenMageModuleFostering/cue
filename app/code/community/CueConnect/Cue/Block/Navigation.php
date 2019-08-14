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

class CueConnect_Cue_Block_Navigation extends Mage_Page_Block_Switch 
{
    public function addMyElistLink() {

        if (Mage::helper('core/data')->isModuleEnabled('CueConnect_Cue')) {
            if (Mage::helper('cueconnect')->isEnabled()) {
                if (Mage::helper('cueconnect')->setMyListLinkAuto()) {
                    $parentBlock = $this->getParentBlock();
                    if ($parentBlock) {
                        $parentBlock->addLink('My List', $this->getUrl('apps/mylist'), 'My List', false, array(), 200, null, '');
                    }    
                }
            }
        }

        return $this;
    }
}
