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

class CueConnect_Cue_Block_Adminhtml_System_Config_Form_Field_Readonly extends Mage_Adminhtml_Block_System_Config_Form_Field
{
    protected function _getElementHtml($element) {
    	$element->setDisabled('disabled');
        return parent::_getElementHtml($element);
    }
}
