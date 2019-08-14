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

class CueConnect_Cue_Model_Mysql4_Demand extends Mage_Core_Model_Mysql4_Abstract
{
    public function _construct()
    {
        $this->_init('cueconnect/demand', 'id');
    }
}