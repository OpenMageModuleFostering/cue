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

class CueConnect_Cue_Block_Elist extends CueConnect_Cue_Block_BaseCueBlock {
    public function getCustomerEmail() {
        if (Mage::getSingleton('customer/session')->isLoggedIn()) {
            $customer = Mage::getSingleton('customer/session')->getCustomer();

            if ($customer) {
                return $customer->getEmail();
            }
        }

        return false;
    }
    
    public function getCustomerId() {
        if (Mage::getSingleton('customer/session')->isLoggedIn()) {
            $customer = Mage::getSingleton('customer/session')->getCustomer();

            if ($customer) {
                return $customer->getId();
            }
        }

        return 0;
    }
    
    
}
