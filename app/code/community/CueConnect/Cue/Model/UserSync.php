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

class CueConnect_Cue_Model_UserSync extends Mage_Core_Model_Abstract
{
    const STATUS_WAITING = 0;
    const STATUS_DONE = 1;
    const STATUS_ERROR = 2;
    
    public function _construct()
    {
        parent::_construct();
        $this->_init('cueconnect/userSync');
    }
}