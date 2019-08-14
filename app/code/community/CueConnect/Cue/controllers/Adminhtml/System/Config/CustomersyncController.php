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

class CueConnect_Cue_Adminhtml_System_Config_CustomersyncController extends Mage_Adminhtml_Controller_Action
{
    /**
     * Run customer resync
     *
     * @return void
     */
    public function indexAction()
    {
        Mage::getModel('cueconnect/observer')->scheduleCustomerSync();
        $this->getResponse()->setBody(true);
    }
}