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

class CueConnect_Cue_Block_BaseCueBlock extends Mage_Core_Block_Template {
    /**
     * return current retailer id
     * @return integer
     */
    public function getRetailerId() {
        return Mage::helper('cueconnect')->getRetailerId();

        $store = Mage::app()->getStore();
        $retailer = Mage::helper('cueconnect')->getRetailer($store);
        if ($retailer && $retailer->id) {
            return $retailer->id;
        }
    }

    /**
     * return api key for current retailer
     * @return string
     */
    public function getApiKey() {
        $store = Mage::app()->getStore();
        return $store->getConfig('cueconnect/credentials/api_key');
    }

    /**
     * check whether the functionalities are enabled or not
     * @return boolean
     */
    public function isEnabled() {
        $store = Mage::app()->getStore();
        return $store->getConfig('cueconnect/enabled/enabled');
    }

    /**
     * get e-List mode
     * @return integer
     */
    public function getMode() {
        $store = Mage::app()->getStore();
        $mode = $store->getConfig('cueconnect/mode');
        if (is_array($mode)) {
            if (isset($mode['mode'])) {
                return $mode['mode'];
            }
        }

        return 1;
    }
    
    /**
     * return current product sku
     * @return string
     */
    public function getSku() {
        $current_product = Mage::registry('current_product');
        return $current_product->getSku();
    }


    /**
     * check whether a session is open
     * @return boolean
     */
    public function isLoggedIn(){
        return Mage::getSingleton('customer/session')->isLoggedIn();
    }


    /**
     * Get CID
     *
     * @return string
     */
    public function getCID() {
        return ($this->isLoggedIn()) ? 'true' : 'null';
    }


    /**
     * Get elist path
     *
     * @return string
     */
    public function getElistPath() {
        $store = Mage::app()->getStore();
        return $this->getUrl($store->getConfig('cueconnect/path/elist'));
    }


    
}