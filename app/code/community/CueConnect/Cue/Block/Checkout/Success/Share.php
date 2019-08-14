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

class CueConnect_Cue_Block_Checkout_Success_Share extends CueConnect_Cue_Block_BaseCueBlock
{
    const CUE_CART_TRACK_URL = 'https://api.cueconnect.com/imi/cart_track/json';
    protected $_firstProductSku = null;
    protected $_order = null;
    protected $_itemsData = null;

    /**
     * Internal constructor
     */
    protected function _construct()
    {
        parent::_construct();
        $this->_getOrderItemsData();
    }

    /**
     * Get product SKU from the first order item
     *
     * @return string|bool
     */
    public function getFirstProductSku()
    {
        return $this->_firstProductSku;
    }

    /**
     * Get items data
     *
     * @return null
     */
    public function getItemsData()
    {
        return $this->_getOrderItemsData();
    }

    /**
     * Get tracking source
     *
     * @return string
     */
    public function getTrackingSource()
    {
        $str = self::CUE_CART_TRACK_URL .
            '?api_key=' . $this->getApiKey() .
            '&place_id=' . $this->getRetailerId() .
            '&email=' . $this->_getOrder()->getCustomerEmail() .
            '&cart=' . json_encode($this->getItemsData()) .
            '&order_id=' . $this->_getOrder()->getId();

        return $this->escapeUrl($str);
    }

    /**
     * Get order (onepage|multishipping)
     *
     * @return Mage_Sales_Model_Order
     */
    protected function _getOrder()
    {
        if (is_null($this->_order)) {
            $session = Mage::getSingleton('checkout/session');
            $order = $session->getLastRealOrder();
            if (!$order->getId()) {
                $orderId = $session->getFirstOrderId(true);
                if ($orderId) {
                    $order = Mage::getModel('sales/order')->load($orderId);
                }
            }
            $this->_order = $order ? $order : false;
        }

        return $this->_order;
    }

    /**
     * Get and prepare order item data (get product sku's)
     *
     * @return array
     */
    protected function _getOrderItemsData()
    {
        if (is_null($this->_itemsData)) {
            $productIds = array();
            $itemData = array();
            $order = $this->_getOrder();
            if ($order->getId()) {
                foreach ($order->getAllItems() as $item) {
                    $productIds[] = $item->getProductId();
                }
                if (count($productIds)) {
                    $productSkus = Mage::helper('cueconnect/product')->getProductSkusByIds($productIds);
                }
                foreach ($order->getAllItems() as $item) {
                    $productId = $item->getProductId();
                    $productSku = array_key_exists($productId, $productSkus) ? $productSkus[$productId] : false;
                    if (is_null($this->_firstProductSku)) {
                        $this->_firstProductSku = $productSku;
                    }
                    if ($productSku) {
                        $itemData[$productSku] = $item->getQtyOrdered() * 1;
                    }
                }
            }
            $this->_itemsData = $itemData;
        }

        return $this->_itemsData;
    }

    /**
     * Checks if Tracking is enabled
     *
     * @return bool
     */
    public function isTrackingEnabled()
    {
        return Mage::helper('cueconnect')->isTrackingEnabled();
    }
}
