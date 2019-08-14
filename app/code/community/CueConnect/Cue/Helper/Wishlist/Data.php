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

class CueConnect_Cue_Helper_Wishlist_Data extends Mage_Wishlist_Helper_Data
{
    /**
     * return true if extension is enabled
     * @return boolean
     */
    protected function isEnabled()
    {
        $store = Mage::app()->getStore();
        return $store->getConfig('cueconnect/enabled/enabled');
    }

    /**
     * Disallow use of the native Magento wishlist in Shopping Cart
     *
     * @return bool
     */
    public function isAllowInCart() {
        return !$this->isEnabled();
    }

    /**
     * Disallow use of the native Magento wishlist in all pages
     *
     * @return bool
     */
    public function isAllow() {
        return !$this->isEnabled();
    }
}
