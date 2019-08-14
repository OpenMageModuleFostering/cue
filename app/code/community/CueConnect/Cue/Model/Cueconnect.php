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

class CueConnect_Cue_Model_CueConnect extends Mage_Core_Model_Abstract
{
    const CUE_GET_PAGE_SIZE = 100000;
    const CUE_SET_PAGE_SIZE = 20;
    const CUE_COLLECTION_PAGE_SIZE = 100;
    const CUE_ERROR_MESSAGE_PRODUCT_SYNC = 'An error occurred while synchronization product data with Cueconnect for
    the %s store. Exception message - ';
    const XML_PATH_PRODUCT_SYNC_STATUS = 'cueconnect/product_sync_%s/status';
    const XML_PATH_PRODUCT_LAST_SYNCED_PRODUCT = 'cueconnect/product_sync_%s/last_synced_product';
    const XML_PATH_PRODUCT_SYNC_SCHEDULED = 'cueconnect/product_sync/scheduled';
    const XML_PATH_CUSTOMER_SYNC_SCHEDULED = 'cueconnect/customer_sync/scheduled';
    const XML_PATH_CUSTOMER_SYNC_STATUS = 'cueconnect/customer_sync_%s/status';
    const XML_PATH_CUE_SUPPORT_EMAIL = 'cueconnect/support/email';
    const XML_PATH_CUE_SUPPORT_NAME = 'cueconnect/support/name';
    const XML_PATH_CUE_SUPPORT_SUBJECT = 'cueconnect/support/subject';
    const PRODUCT_SYNC_FAILED = 'failed';
    const PRODUCT_SYNC_COMPLETE = 'complete';
    const PRODUCT_SYNC_PROCESSING = 'processing';
    const CUSTOMER_SYNC_FAILED = 'failed';
    const CUSTOMER_SYNC_COMPLETE = 'complete';
    const CUSTOMER_SYNC_PROCESSING = 'processing';

    protected $_cueLogin = false;
    protected $_cuePassword = false;
    protected $_placeId = false;
    protected $_productSoapClient = false;
    protected $_store = false;
    protected $_cueProducts = array();
    protected $_newData = array();
    protected $_updateData = array();
    protected $_errors = array();
    protected $_lastProductId = false;

    /**
     * Construct
     */
    public function _construct() {
        $this->_init('cueconnect/cueconnect');
    }

    /**
     * Sync updated products data with CUE
     *
     * @param array $productIds
     * @return array
     */
    public function productsUpdate($productIds)
    {
        foreach (Mage::app()->getStores() as $store) {
            $this->_store = $store;
            if (!$this->_getCredentials() || !$this->_getPlaceId()) {
                continue;
            }
            $this->_getProductSoap();
            if ($this->_productSoapClient) {
                $this->_getCueProducts();
                $this->_prepareProductDataForIds($productIds);
                $this->_addProducts();
                $this->_updatedProducts();
            }
        }

        return $this->_errors;
    }


    /**
     * get products by Ids
     *
     * @param array $productIds
     * @return array
     */
    public function getProductsByIds($productIds)
    {

        foreach (Mage::app()->getStores() as $store) {
            $this->_store = $store;
            if (!$this->_getCredentials()) {
                continue;
            }

            $productCollection = Mage::getModel('catalog/product')
                ->getCollection()
                ->addFieldToFilter('entity_id', array('in' => $productIds))
                ->addAttributeToSelect('name')
                ->addAttributeToSelect('description')
                ->addAttributeToSelect('price')
                ->addAttributeToSelect('image')
                ->addAttributeToSelect('url_path')
                ->addStoreFilter($this->_store->getId());

            return $productCollection;

        }
        
        return false;
    }

    /**
     * Cue data sync
     */
    public function sync()
    {

        $storeId = Mage::app()
            ->getWebsite(true)
            ->getDefaultGroup()
            ->getDefaultStoreId();
        $store = Mage::getModel('core/store')->load($storeId);
        $soap_auth = Mage::helper('cueconnect')->getRetailer($store);

        if($soap_auth){

            $this->_execSyncAllCustomers();
            $this->_exeSyncAllProducts();


            echo "=> Sync Done\n";

        }else{
            echo "\n\nERROR : CUE SOAP Credentials aren't Set Yet \n\n";
        }




    }

    /**
     * Sync all Customers with CUE
     *
     * @return bool
     */
    protected function _execSyncAllCustomers(){

        // Check first if we need to sync
        $scheduled = Mage::getStoreConfigFlag(self::XML_PATH_CUSTOMER_SYNC_SCHEDULED);
        $remote_sync  = Mage::getModel('cueconnect/observer')->getRemoteSyncStatus('customers');
        if ($scheduled || $remote_sync){
            echo "\nStart SyncAllCustomers\n";
            $this->removeScheduleCustomerSync();
            Mage::getModel('cueconnect/observer')->syncAllCustomers();
        }else{

            foreach (Mage::app()->getStores() as $store) {
                $path = sprintf(self::XML_PATH_CUSTOMER_SYNC_STATUS, $store->getId());
                $status = $store->getConfig($path);

                if ($status == self::CUSTOMER_SYNC_FAILED || is_null($status)) {
                    // init sync since last sync failed
                    $this->scheduleCustomerSync();
                }

            }

        }

    }

    /**
     * Sync all product with CUE
     *
     * @return bool
     */
    public function _exeSyncAllProducts()
    {
        $scheduled = Mage::getStoreConfigFlag(self::XML_PATH_PRODUCT_SYNC_SCHEDULED);
        $remote_sync  = Mage::getModel('cueconnect/observer')->getRemoteSyncStatus('products');
        if (!$scheduled && !$remote_sync) {

            return false;
        }

        $retailerId = Mage::getStoreConfig('cueconnect/credentials/retailer_id');
        $this->removeScheduleProductSync();
        foreach (Mage::app()->getStores() as $store) {
            $this->_errors = array();
            $this->_lastProductId = false;
            $this->_store = $store;
            
            if($remote_sync){
                $this->_setProductSyncStatus(self::PRODUCT_SYNC_PROCESSING);
                $this->_getCueProducts();
                $this->_syncAllProducts();
            }else{

                $status = $this->_getProductSyncStatus();
                // Skip all if product sync is processing
                if ($status == self::PRODUCT_SYNC_PROCESSING) {
                    return false;
                }
                // Skip store if product sync is complete
                if ($status == self::PRODUCT_SYNC_COMPLETE) {
                    continue;
                }
                // check credentials and proceed with sync
                if ($this->_getCredentials() && $this->_getPlaceId()) {
                    $this->_getProductSoap();
                    if ($this->_productSoapClient) {
                        echo "\nStart SyncAllProducts\n";
                        $this->_setProductSyncStatus(self::PRODUCT_SYNC_PROCESSING);
                        $this->_getCueProducts();
                        $this->_syncAllProducts();
                    }
                }
            }

            if (count($this->_errors)) {

                $this->_setProductSyncStatus(self::PRODUCT_SYNC_FAILED);

                $merchantEmail = Mage::getStoreConfig('cueconnect/credentials/login', $store->getId());
                array_shift($this->_errors);
                $number = 1;
                $error_msg = '';
                foreach ($this->_errors as $error) {
                    $error_msg .= $number . '. ' . $error . '<br/>';
                        ++$number;
                }

                // Email Cue Support
                $this->sendEmailToSupport(
                    'Cueconnect > _exeSyncAllProducts',
                    $store->getBaseUrl(Mage_Core_Model_Store::URL_TYPE_WEB),
                    $store->getName(),
                    $retailerId,
                    $merchantEmail,
                    $error_msg);

            }
        }

        return false;
    }

    /**
     * Get CUE credentials (login and password)
     *
     * @return bool
     */
    protected function _getCredentials()
    {
        $this->_cueLogin = $this->_store->getConfig('cueconnect/credentials/login');
        $this->_cuePassword = $this->_store->getConfig('cueconnect/credentials/password');
        // Check if credentials has been filled
        if (!$this->_cueLogin || !$this->_cuePassword) {
            $this->_errors[] = Mage::helper('cueconnect')->__(
                'Please check the following Cue API Credentials: E-mail and Password for the %s store.',
                $this->_store->getName()
            );

            return false;
        }

        return true;
    }

    /**
     * Get place id for the CUE account
     *
     * @return bool
     */
    protected function _getPlaceId()
    {
        // Retailuser WS
        $soap_client = Mage::helper('cueconnect')->getSoapClient(
            Mage::helper('cueconnect')->getWsUrl('place'),
            $this->_cueLogin,
            $this->_cuePassword
        );
        // Get place ID
        $place_id =  null;
        try {
            $result = $soap_client->get(array(
                'email' => $this->_cueLogin
            ));
            $this->_placeId = $result->data->id;
        } catch (Exception $e) {
            $message = $e->getMessage();
            Mage::log($message);
            $this->_errors[] = Mage::helper('cueconnect')->__(
                self::CUE_ERROR_MESSAGE_PRODUCT_SYNC,
                $this->_store->getName()
            ) . $message;

            return false;
        }

        return true;
    }

    /**
     * Get SOAP client for product
     */
    protected function _getProductSoap()
    {
        $this->_productSoapClient = Mage::helper('cueconnect')->getSoapClient(
            Mage::helper('cueconnect')->getWsUrl('product'),
            $this->_cueLogin,
            $this->_cuePassword
        );
    }

    /**
     * Get all products (SKU's) from CUE
     */
    protected function _getCueProducts()
    {
        $page = 1;
        do {
            $results = $this->_productSoapClient->get(
                array(
                    'page' => $page,
                    'pagesize' => self::CUE_GET_PAGE_SIZE,
                    'clipped' => false)
            );
            $pages = ceil($results->totalcount / $results->pagesize);
            foreach ($results->data as $result) {
                if ($result->sku) {
                    $this->_cueProducts[$result->sku] = $result;
                }
            }
            unset($results);
        } while ($page++ < $pages);
    }

    /**
     * Getting and preparing product data for specified product ids
     *
     * @param array $productIds
     */
    protected function _prepareProductDataForIds($productIds)
    {
        $productCollection = Mage::getModel('catalog/product')
            ->getCollection()
            ->addFieldToFilter('entity_id', array('in' => $productIds))
            ->addAttributeToSelect('name')
            ->addAttributeToSelect('description')
            ->addAttributeToSelect('price')
            ->addAttributeToSelect('image')
            ->addAttributeToSelect('url_path')
            ->addStoreFilter($this->_store->getId());
        $this->_prepareProductData($productCollection);
    }

    /**
     * Getting and preparing product data, run product sync
     */
    protected function _syncAllProducts()
    {
        $productCollection = Mage::getModel('catalog/product')
            ->getCollection()
            ->addAttributeToSelect('name')
            ->addAttributeToSelect('description')
            ->addAttributeToSelect('price')
            ->addAttributeToSelect('image')
            ->addAttributeToSelect('url_path')
            ->addStoreFilter($this->_store->getId())
            ->addAttributeToSort('entity_id');
        if ($this->_lastProductId) {
            $productCollection->addAttributeToFilter('entity_id', $this->_lastProductId);
        }
        $productCollection->setPageSize(self::CUE_COLLECTION_PAGE_SIZE);
        $pages = $productCollection->getLastPageNumber();
        $currentPage = 1;
        do {
            $productCollection->setCurPage($currentPage);
            $this->_prepareProductData($productCollection);
            $this->_addProducts();
            $this->_updatedProducts();
            $this->_saveLastSyncedProductId();
            $productCollection->clear();
        } while ($currentPage++ <= $pages);
    }

    /**
     * Preparing product data
     *
     * @param $productCollection
     */
    protected function _prepareProductData($productCollection)
    {
        $this->_updateData = array();
        $this->_newData = array();

        foreach ($productCollection as $product) {
            // Product image
            $icon = "https://www.cueconnect.com/images/no_image.gif";
            if ($product->getData('image')) {
                $icon = $product->getMediaConfig()->getMediaUrl($product->getData('image'));
            }
            $url = $this->_store->getBaseUrl(Mage_Core_Model_Store::URL_TYPE_LINK) . $product->getUrlPath();
            $data = array(
                'sku' => $product->getSku(),
                'name' => $product->getName(),
                'description' => $product->getDescription(),
                'sms_name' => $product->getName(),
                'sms_desc' => $product->getDescription(),
                'url' => $url,
                'taxonomy_id' => Mage::getStoreConfig('cueconnect/taxomomy_id'),
                'icon' => $icon,
                'live' => '1',
                'price' => $product->getPrice()
            );
            // Check if product exists in Cue database
            if (in_array($product->getSku(), array_keys($this->_cueProducts))) {
                $data['product_imic'] = null;
                $this->_updateData[] = $data;
            } else {
                $data['upc'] = uniqid();
                $this->_newData[] = $data;
            }
            $this->_lastProductId = $product->getId();
        }
    }

    /**
     * Send new products data to Cue (per slices)
     */
    protected function _addProducts()
    {
        if ($this->_newData) {

            $new_data_slices = Mage::helper('cueconnect')->getSlicesFromArray($this->_newData, self::CUE_SET_PAGE_SIZE);
            foreach ($new_data_slices as $new_data_slice) {
                try {
                    $this->_productSoapClient->create(array(
                        'place_id' => $this->_placeId,
                        'data' => $new_data_slice,
                        'count' => count($new_data_slice)
                    ));
                } catch (Exception $e) {
                    $message = $e->getMessage();
                    Mage::log($message);
                    $this->_errors[] = Mage::helper('cueconnect')->__(
                        self::CUE_ERROR_MESSAGE_PRODUCT_SYNC,
                        $this->_store->getName()
                    ) . $message;
                    // TODO: send email to support
                    //return array('success' => false, 'message' => $e->getMessage(), 'log' => $log);
                }
            }
        }
    }

    /**
     * Send updated data to Cue (per slices)
     */
    protected function _updatedProducts()
    {
        if (count($this->_updateData)) {
            $updated_data_slices = Mage::helper('cueconnect')->getSlicesFromArray($this->_updateData, self::CUE_SET_PAGE_SIZE);
            foreach ($updated_data_slices as $updated_data_slice) {
                try {

                    $this->_productSoapClient->set(array(
                        'place_id' => $this->_placeId,
                        'data' => $updated_data_slice,
                        'count' => count($updated_data_slice)
                    ));
                } catch (Exception $e) {
                    $message = $e->getMessage();
                    Mage::log($message);
                    $this->_errors[] = Mage::helper('cueconnect')->__(
                        self::CUE_ERROR_MESSAGE_PRODUCT_SYNC,
                        $this->_store->getName()
                    ) . $message;
                    // TODO: Send email to support
                    // return array('success' => false, 'message' => $e->getMessage(), 'log' => $log);
                }
            }
        }
    }

    /**
     * Get Product Sync status for the store.
     *
     * @return mixed
     */
    protected function _getProductSyncStatus()
    {
        $path = sprintf(self::XML_PATH_PRODUCT_SYNC_STATUS, $this->_store->getId());
        $status = $this->_store->getConfig($path);
        if ($status == self::PRODUCT_SYNC_FAILED) {
            $lastProductPath = sprintf(self::XML_PATH_PRODUCT_LAST_SYNCED_PRODUCT, $this->_store->getId());
            $this->_lastProductId = $this->_store->getConfig($lastProductPath);
        }

        return $status;
    }

    /**
     * Set and save product sync status for the store
     *
     * @param string $value
     */
    protected function _setProductSyncStatus($value)
    {
        $path = sprintf(self::XML_PATH_PRODUCT_SYNC_STATUS, $this->_store->getId());
        Mage::getModel('core/config')->saveConfig($path, $value);
        Mage::app()->getCacheInstance()->cleanType('config');
    }

    /**
     * Set and save last synced product id
     */
    protected function _saveLastSyncedProductId()
    {
        $path = sprintf(self::XML_PATH_PRODUCT_LAST_SYNCED_PRODUCT, $this->_store->getId());
        Mage::getModel('core/config')->saveConfig($path, $this->_lastProductId);
        Mage::app()->getCacheInstance()->cleanType('config');
    }

    /**
     * Check product sync status for the stores, return true when resync should be running.
     * Moreover check if we have an actual credentials for the CUE account to schedule product
     * sync automatically (only once)
     *
     * @return bool
     */
    public function isProductReSyncNeeded()
    {
        $scheduled = Mage::getStoreConfigFlag(self::XML_PATH_PRODUCT_SYNC_SCHEDULED);
        if ($scheduled) {

            return false;
        }
        $actualCredentials = false;
        foreach (Mage::app()->getStores() as $store) {
            $path = sprintf(self::XML_PATH_PRODUCT_SYNC_STATUS, $store->getId());
            $status = $store->getConfig($path);
            if ($status == self::PRODUCT_SYNC_PROCESSING) {

                return false;
            }
            if ($status == self::PRODUCT_SYNC_FAILED) {

                return true;
            }
            if (!$actualCredentials && is_null($status)) {
                $this->_store = $store;
                if ($this->_getCredentials() && $this->_getPlaceId()) {
                    $actualCredentials = true;
                }
            }
        }
        if ($actualCredentials) {
            $this->scheduleProductSync();
            $this->scheduleCustomerSync(); // init sync setup
        }

        return false;
    }

    /**
     * Schedule Product Sync. Set flat to 1
     */
    public function scheduleProductSync()
    {
        Mage::getModel('core/config')->saveConfig(self::XML_PATH_PRODUCT_SYNC_SCHEDULED, 1);
        Mage::app()->getCacheInstance()->cleanType('config');
    }

    /**
     * Remove schedule Product Sync. Set flat to 0
     */
    public function removeScheduleProductSync()
    {
        Mage::getModel('core/config')->saveConfig(self::XML_PATH_PRODUCT_SYNC_SCHEDULED, 0);
        Mage::app()->getStore()->setConfig(self::XML_PATH_PRODUCT_SYNC_SCHEDULED, 0);
        Mage::app()->getCacheInstance()->cleanType('config');
    }

    /**
     * Schedule customer Sync. Set flat to 1
     */
    public function scheduleCustomerSync()
    {
        Mage::getModel('core/config')->saveConfig(self::XML_PATH_CUSTOMER_SYNC_SCHEDULED, 1);
        Mage::app()->getCacheInstance()->cleanType('config');
    }

    /**
     * Remove schedule Product Sync. Set flat to 0
     */
    public function removeScheduleCustomerSync()
    {
        Mage::getModel('core/config')->saveConfig(self::XML_PATH_CUSTOMER_SYNC_SCHEDULED, 0);
        Mage::app()->getStore()->setConfig(self::XML_PATH_CUSTOMER_SYNC_SCHEDULED, 0);
        Mage::app()->getCacheInstance()->cleanType('config');
    }

    /**
     * Send email to the CUE support
     *
     * @param string $message
     */
    public function sendEmailToSupport($action = 'N/A', $store_url = '', $store_name = '', $pid = '', $merchantEmail = '', $error = '')
    {
        // Form email content
        $subject = Mage::getStoreConfig(self::XML_PATH_CUE_SUPPORT_SUBJECT) . Mage::getBaseUrl (Mage_Core_Model_Store::URL_TYPE_WEB);
        $message = $action.' has failed for '
            . $store_url . ' website '
            . $store_name. ' store '
            . ' at ' . Mage::getModel('core/date')->date('H:i d-m-Y') . '<br/><br/>'
            . 'Please check Technical debug bellow: ' . '<br/><br/>'
            . 'Merchant ID: ' . $pid . '<br/>'
            . 'Merchant email: ' . $merchantEmail . '<br/>'
            . 'Platform: Magento'  . '<br/>'
            . 'Version: '.Mage::getVersion()  . '<br/>'
            . $error
        ;

        // send email
        Mage::getModel('core/email')
            ->setType('html')
            ->setToName(Mage::getStoreConfig(self::XML_PATH_CUE_SUPPORT_NAME))
            ->setToEmail(Mage::getStoreConfig(self::XML_PATH_CUE_SUPPORT_EMAIL))
            ->setSubject($subject)
            ->setFromEmail(Mage::getStoreConfig('trans_email/ident_general/email'))
            ->setFromName(Mage::getStoreConfig('trans_email/ident_sales/name'))
            ->setBody($message)
            ->send();
    }
}
