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
    the %s store. You can find more details in the log file.';
    const XML_PATH_PRODUCT_SYNC_STATUS = 'cueconnect/product_sync_%s/status';
    const XML_PATH_PRODUCT_LAST_SYNCED_PRODUCT = 'cueconnect/product_sync_%s/last_synced_product';
    const XML_PATH_PRODUCT_SYNC_SCHEDULED = 'cueconnect/product_sync/scheduled';
    const XML_PATH_CUE_SUPPORT_EMAIL = 'cueconnect/support/email';
    const XML_PATH_CUE_SUPPORT_NAME = 'cueconnect/support/name';
    const XML_PATH_CUE_SUPPORT_SUBJECT = 'cueconnect/support/subject';
    const PRODUCT_SYNC_FAILED = 'failed';
    const PRODUCT_SYNC_COMPLETE = 'complete';
    const PRODUCT_SYNC_PROCESSING = 'processing';

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
     * Execute export and put result in message box
     */
    public static function dailyExport() {
        if (Mage::getStoreConfig('cueconnect/cron/enabled')) {
            // Execute export() for each stores
            foreach (Mage::app()->getStores() as $store) {
                if ($store->getConfig('cueconnect/enabled/enabled')) {
                    // Export
                    $result = self::export($store);

                    // Notification
                    $inbox = Mage::getModel('adminnotification/inbox');
                    if ($result['success']) {
                        $inbox->addNotice(sprintf("%s products has been successfully synced with Cue.", $store->getName()), $result['message']);
                    }
                    else {
                        $inbox->addCritical(sprintf("%s products has not been successfully synced with Cue.", $store->getName()), $result['message']);
                    }
                }
            }
        }
    }
    
    /**
     * Execute export if manually asked and put result in message box
     */
    public static function manualExport() { 
        // Check for demands
        $demand = Mage::getModel('cueconnect/demand');
        $awaiting_demands = Mage::getModel('cueconnect/demand')
                ->getCollection()
                ->addFilter('status', $demand::STATUS_WAITING);
        
        if (count($awaiting_demands)) {
            // Update demands
            foreach ($awaiting_demands as $awaiting_demand) {
                $awaiting_demand->setStatus($demand::STATUS_PROGRESSING);
                $awaiting_demand->setUpdatedAt(date('Y-m-d H:i:s'));
                $awaiting_demand->save();
            }
            
            // Execute export() for each stores
            foreach (Mage::app()->getStores() as $store) {
                if ($store->getConfig('cueconnect/enabled/enabled')) {
                    // Export
                    $result = self::export($store);
                    
                    // Log
                    $log = $result['log'];
                    $log['end_at'] = date('Y-m-d H:i:s');
                    
                    // Notification
                    $inbox = Mage::getModel('adminnotification/inbox');
                    if ($result['success']) {
                        $inbox->addNotice(sprintf("%s products has been successfully synced with Cue.", $store->getName()), $result['message']);
                    }
                    else {
                        $inbox->addCritical(sprintf("%s products has not been successfully synced with Cue.", $store->getName()), $result['message']);
                        $log['status'] = 'error';
                    }
                    
                    Mage::helper('cueconnect')->logExportProgress(json_encode($log));
                }
            }
            
            // Update demands
            foreach ($awaiting_demands as $awaiting_demand) {
                $awaiting_demand->setStatus($demand::STATUS_DONE);
                $awaiting_demand->setUpdatedAt(date('Y-m-d H:i:s'));
                $awaiting_demand->save();
            }             
        }
    }
    
    /**
     * Export catalog products to Cue
     */
    public static function export($store) {
        // Log
        $log = array();
        $log['started_at'] = date('Y-m-d H:i:s');
        $log['status'] = "progressing";
        $log['store'] = $store->getName();
        Mage::helper('cueconnect')->logExportProgress(json_encode($log));
        
        // Check if crententials has been filled
        if (!$store->getConfig('cueconnect/credentials/login') || !$store->getConfig('cueconnect/credentials/password')) {
            $message = "Cue credentials are not filled.";
            return array('success' => false, 'message' => $message, 'log' => $log);
        }
        
        // Retailuser WS
        $soap_client = Mage::helper('cueconnect')->getSoapClient(
                Mage::helper('cueconnect')->getWsUrl('retailuser'),
                $store->getConfig('cueconnect/credentials/login'),
                $store->getConfig('cueconnect/credentials/password')
        );
        
        // Get place ID
        $place_id =  null;
        try {
            $result = $soap_client->get(array(
                'email' => $store->getConfig('cueconnect/credentials/login')
            ));
            $place_id = $result->data->id;
        }
        catch (Exception $e) {
            $message = $e->getMessage();
            return array('success' => false, 'message' => $message, 'log' => $log);
        }
        
        // Product WS
        $soap_client = Mage::helper('cueconnect')->getSoapClient(
                Mage::helper('cueconnect')->getWsUrl('product'),
                $store->getConfig('cueconnect/credentials/login'),
                $store->getConfig('cueconnect/credentials/password')
        );
        
        // Get Cue Catalog
        $cueconnect_products = array();
        $results = $soap_client->get(array('page' => 1, 'pagesize' => 100000, 'clipped' => false));
        foreach ($results->data as $result) {
            if ($result->sku) {
                $cueconnect_products[$result->sku] = $result;
            }
        }
        
        // Catalog products
        $catalog_products = Mage::getModel('catalog/product')
                ->getCollection()
                ->addAttributeToSelect('name')
                ->addAttributeToSelect('description')
                ->addAttributeToSelect('price')
                ->addAttributeToSelect('image')
                ->addAttributeToSelect('url_path')
                ->addStoreFilter($store->getId());
        $catalog_products_skus = array();
        $new_data = array();
        $updated_data = array();
        foreach ($catalog_products as $catalog_product) {
            $catalog_products_skus[] = $catalog_product->getSku();
            
            // Product image
            $icon = "http://www.cueconnect.com/images/no_image.gif";
            if ($catalog_product->getData('image')) {
                $icon = $catalog_product->getMediaConfig()->getMediaUrl($catalog_product->getData('image'));
            }

            // Product URL
            $url = $store->getBaseUrl(Mage_Core_Model_Store::URL_TYPE_LINK).$catalog_product->getUrlPath();
            
            // Check if product exists in Cue database
            if (in_array($catalog_product->getSku(), array_keys($cueconnect_products))) {
                $updated_data[] = array(
                    'product_imic' => null,
                    'sku' => $catalog_product->getSku(),
                    'name' => $catalog_product->getName(),
                    'description' => $catalog_product->getDescription(),
                    'sms_name' => $catalog_product->getName(),
                    'sms_desc' => $catalog_product->getDescription(),
                    'url' => $url,
                    'taxonomy_id' => Mage::getStoreConfig('cueconnect/taxomomy_id'),
                    'icon' => $icon,
                    'live' => '1',
                    'price' => $catalog_product->getPrice()
                );
            }
            else {
                $new_data[] = array(
                    'sku' => $catalog_product->getSku(),
                    'upc' => uniqid(),
                    'name' => $catalog_product->getName(),
                    'description' => $catalog_product->getDescription(),
                    'sms_name' => $catalog_product->getName(),
                    'sms_desc' => $catalog_product->getDescription(),
                    'url' => $url,
                    'taxonomy_id' => Mage::getStoreConfig('cueconnect/taxomomy_id'),
                    'icon' => $icon,
                    'live' => '1',
                    'price' => $catalog_product->getPrice()
                );
            }
        }
        // Product to delete
        $skus_to_delete = array_diff(array_keys($cueconnect_products), $catalog_products_skus);
        $imics_to_delete = array();
        foreach ($skus_to_delete as $sku_to_delete) {
            $imics_to_delete[] = $cueconnect_products[$sku_to_delete]->product_imic;
        }
        
        // Log
        $log['total_products'] = count($catalog_products);
        $log['total_products_to_create'] = count($new_data);
        $log['total_products_to_update'] = count($updated_data);
        $log['total_products_to_delete'] = count($imics_to_delete);
        $log['total_products_created'] = 0;
        $log['total_products_updated'] = 0;
        $log['total_products_deleted'] = 0;
        Mage::helper('cueconnect')->logExportProgress(json_encode($log));
        
        // Send new data data to Cue (per slices)
        if (count($new_data)) {
            $new_data_slices = Mage::helper('cueconnect')->getSlicesFromArray($new_data, 20);
            foreach ($new_data_slices as $new_data_slice) {
                try {
                    $soap_client->create(array(
                        'place_id' => $place_id,
                        'data' => $new_data_slice,
                        'count' => count($new_data_slice)
                    ));
                    // Log
                    $log['total_products_created'] = $log['total_products_created'] + count($new_data_slice);
                    Mage::helper('cueconnect')->logExportProgress(json_encode($log));
                }
                catch (Exception $e) {
                    return array('success' => false, 'message' => $e->getMessage(), 'log' => $log);
                }
            }
        }
        
        // Send updated data data to Cue (per slices)
        if (count($updated_data)) {
            $updated_data_slices = Mage::helper('cueconnect')->getSlicesFromArray($updated_data, 20);
            foreach ($updated_data_slices as $updated_data_slice) {
                try {
                    $soap_client->set(array(
                        'place_id' => $place_id,
                        'data' => $updated_data_slice,
                        'count' => count($updated_data_slice)
                    ));
                    // Log
                    $log['total_products_updated'] = $log['total_products_updated'] + count($updated_data_slice);
                    Mage::helper('cueconnect')->logExportProgress(json_encode($log));
                }
                catch (Exception $e) {
                    return array('success' => false, 'message' => $e->getMessage(), 'log' => $log);
                }
            }
        }
        
        // Delete products which are not in the Magento catalog anymore
        if (count($imics_to_delete)) {
            $imics_to_delete_slices = Mage::helper('cueconnect')->getSlicesFromArray($imics_to_delete, 50);
            foreach ($imics_to_delete_slices as $imics_to_delete_slice) {
                try {
                    $soap_client->delete(array(
                        'place_id' => $place_id,
                        'data' => $imics_to_delete_slice,
                        'count' => count($imics_to_delete_slice)
                    ));
                    // Log
                    $log['total_products_deleted'] = $log['total_products_deleted'] + count($imics_to_delete_slice);
                    Mage::helper('cueconnect')->logExportProgress(json_encode($log));
                }
                catch (Exception $e) {
                    return array('success' => false, 'message' => $e->getMessage(), 'log' => $log);
                }
            }
        }
        
        // Log
        $log['end_at'] = date('Y-m-d H:i:s');
        $log['status'] = "done";
        Mage::helper('cueconnect')->logExportProgress(json_encode($log));
        
        // Return
        return array(
            'success' => true,
            'created_count' => count($new_data),
            'updated_count' => count($updated_data),
            'deleted_count' => count($imics_to_delete),
            'message' => sprintf("%s product(s) has been created. %s product(s) has been updated. %s product(s) has been deleted.", count($new_data), count($updated_data), count($imics_to_delete)),
            'log' => $log
        );
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
     * Cue data sync
     */
    public function sync()
    {
        Mage::getModel('cueconnect/observer')->syncAllCustomers();
        $this->syncAllProducts();
    }

    /**
     * Sync all product with CUE
     *
     * @return bool
     */
    public function syncAllProducts()
    {
        $scheduled = Mage::getStoreConfigFlag(self::XML_PATH_PRODUCT_SYNC_SCHEDULED);
        if (!$scheduled) {

            return false;
        }
        $this->removeScheduleProductSync();
        foreach (Mage::app()->getStores() as $store) {
            $this->_errors = array();
            $this->_lastProductId = false;
            $this->_store = $store;
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
                    $this->_setProductSyncStatus(self::PRODUCT_SYNC_PROCESSING);
                    $this->_getCueProducts();
                    $this->_syncAllProducts();
                }
            }
            $inbox = Mage::getModel('adminnotification/inbox');
            if (count($this->_errors)) {
                array_unshift($this->_errors, Mage::helper('cueconnect')->__('You can find more details below:'));
                $title = Mage::helper('cueconnect')->__(
                    'Product Synchronization has failed for the %s store, an email was sent to Cue Connect support.
                    Contact us on %s for more information',
                    $this->_store->getName(),
                    Mage::getStoreConfig(self::XML_PATH_CUE_SUPPORT_EMAIL)
                );
                $inbox->addCritical($title, $this->_errors);
                $this->_setProductSyncStatus(self::PRODUCT_SYNC_FAILED);
                $message = Mage::helper('cueconnect')->__(
                    'Product Synchronization has failed for the %s store.',
                    $this->_store->getName()
                );
                $message = $message . ' ' . implode(',', $this->_errors);
                $this->sendEmailToSupport($message);
            } else {
                $title = Mage::helper('cueconnect')->__(
                    'Products data has been successfully synced with Cue for the %s store',
                    $this->_store->getName()
                );
                $description = Mage::helper('cueconnect')->__('Congratulation!') . ' ' . $title;
                $inbox->addNotice($title, $description);
                $this->_setProductSyncStatus(self::PRODUCT_SYNC_COMPLETE);
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
            Mage::helper('cueconnect')->getWsUrl('retailuser'),
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
            Mage::log($e->getMessage());
            $this->_errors[] = Mage::helper('cueconnect')->__(
                self::CUE_ERROR_MESSAGE_PRODUCT_SYNC,
                $this->_store->getName()
            );

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
            $icon = "http://www.cueconnect.com/images/no_image.gif";
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
                    // TODO: check this if we need full log
                    // Log
                    // $log['total_products_created'] = $log['total_products_created'] + count($new_data_slice);
                    // Mage::helper('cueconnect')->logExportProgress(json_encode($log));
                } catch (Exception $e) {
                    Mage::log($e->getMessage());
                    $this->_errors[] = Mage::helper('cueconnect')->__(
                        self::CUE_ERROR_MESSAGE_PRODUCT_SYNC,
                        $this->_store->getName()
                    );
                    // TODO: check this if we need full log
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
                    // TODO: check this if we need full log
                    // Log
                    // $log['total_products_updated'] = $log['total_products_updated'] + count($updated_data_slice);
                    // Mage::helper('cueconnect')->logExportProgress(json_encode($log));
                } catch (Exception $e) {
                    Mage::log($e->getMessage());
                    $this->_errors[] = Mage::helper('cueconnect')->__(
                        self::CUE_ERROR_MESSAGE_PRODUCT_SYNC,
                        $this->_store->getName()
                    );
                    // TODO: check this if we need full log
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
     * Send email to the CUE support
     *
     * @param string $message
     */
    public function sendEmailToSupport($message = 'hello')
    {
        Mage::getModel('core/email')
            ->setType('html')
            ->setToName(Mage::getStoreConfig(self::XML_PATH_CUE_SUPPORT_NAME))
            ->setToEmail(Mage::getStoreConfig(self::XML_PATH_CUE_SUPPORT_EMAIL))
            ->setSubject(Mage::getStoreConfig(self::XML_PATH_CUE_SUPPORT_SUBJECT))
            ->setFromEmail(Mage::getStoreConfig('trans_email/ident_general/email'))
            ->setFromName(Mage::getStoreConfig('trans_email/ident_sales/name'))
            ->setBody($message)
            ->send();
    }
}
