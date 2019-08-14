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

class CueConnect_Cue_Model_Observer
{
    const XML_PATH_CUSTOMER_SYNCED_WITH_ERROR = 'cueconnect/customer_sync_%s/error_synced_customer';
    const XML_PATH_CUSTOMER_SYNC_STATUS = 'cueconnect/customer_sync_%s/status';
    const XML_PATH_CUSTOMER_SYNC_SCHEDULED = 'cueconnect/customer_sync/scheduled';

    const CUSTOMER_SYNC_FAILED = 'failed';
    const CUSTOMER_SYNC_COMPLETE = 'complete';
    const CUSTOMER_SYNC_PROCESSING = 'processing';

    const SYNC_MASS_ACTION_NAME = 'cue_sync_mass_action';

    /**
     * Current Store Id
     *
     * @var string $_currentStoreId
     */
    protected $_currentStoreId = false;

    /**
     * Current Store Name
     *
     * @var string $_currentStoreName
     */
    protected $_currentStoreName = false;

    /**
     * Errors messages
     *
     * @var array $_errors
     */
    protected $_errors = array();



    /**########################################################################################################################
     *
     * List of Function for > CUE Configuration
     *
     * ########################################################################################################################
     */


    /**
     * @action : Cue tools Status Sycn (Magento -> Cue)
     * @description : Sync tools status "enabled/disabled" with Cue
     * @author : Imad.T - itouil@cueconnect.com
     * @param  Varien_Event_Observer $observer
     */
    public function ConfigUpdated(Varien_Event_Observer $observer)
    {

        $storeId = Mage::app()
            ->getWebsite(true)
            ->getDefaultGroup()
            ->getDefaultStoreId();
        $store = Mage::getModel('core/store')->load($storeId);

        if ($store) {

            $post = Mage::app()->getRequest()->getPost();
            $params['showMyList'] = $post['groups']['collection']['fields']['enabled']['value'];
            $params['showShareButton'] = $post['groups']['ob']['fields']['enabled']['value'];
            $params['showAddToWishlist'] = $post['groups']['favorite']['fields']['enabled']['value'];
            $params['showPriceAlert'] = $post['groups']['alert']['fields']['enabled']['value'];
            $params['showTracking'] = $post['groups']['tracking']['fields']['enabled']['inherit'];
            $params['version'] =2;

            $placeApiKey = $store->getConfig('cueconnect/credentials/api_key');
            $str = "v2" . Mage::helper('cueconnect')->getWebhookConfigurationChangedKey() . Mage::helper('cueconnect')->getWebhookConfigurationChangedUrl() . $placeApiKey;
            $key = sha1($str) . '$' . $placeApiKey;

            // Submit config changes to Cue
            $result = $this->_doRequest(Mage::helper('cueconnect')->getWebhookConfigurationChangedUrl(), $key, $params);
            $soap_auth = Mage::helper('cueconnect')->getRetailer($store);
            $retailerId = (int)$result;
            // Check if API Key is valid
            if($result === "0"){
                // Say API key is wrong to the admin
                $message = Mage::helper('cueconnect')->__(
                    'Cue Authentication Failure : Incorrect API Key. Find your API Key by visiting www.cueconnect.com > Login > Code Implementation.',
                    $store->getName()
                );
                Mage::getSingleton('adminhtml/session')->addError($message);
            }
            elseif(!$soap_auth){
                // Say SOAP Credentials are wrong to the admin
                $message = Mage::helper('cueconnect')->__(
                    'Cue Authentication Failure : Incorrect email or password. You can reset your password by visiting www.cueconnect.com > Forgot password',
                    $store->getName()
                );
                Mage::getSingleton('adminhtml/session')->addError($message);
            }
            elseif ($retailerId) {
                Mage::getModel('core/config')->saveConfig('cueconnect/credentials/retailer_id', $retailerId);
                Mage::app()->getCacheInstance()->cleanType('config');
            }

        }
    }

    /**
     * @action : Cue tools Status Sycn (Magento -> Cue)
     * @description : Check if Remote Sync Needed From Cue
     * @author : Imad.T - itouil@cueconnect.com
     * @param  $action (products or customers)
     * @return bool
     */
    public function getRemoteSyncStatus($action = "products")
    {
        $storeId = Mage::app()
            ->getWebsite(true)
            ->getDefaultGroup()
            ->getDefaultStoreId();
        $store = Mage::getModel('core/store')->load($storeId);

        if($store){

            $params['version'] =2;
            $params['sync_type'] = $action;
            $params['remote_sync_check'] = true;

            $placeApiKey = $store->getConfig('cueconnect/credentials/api_key');
            $str = "v2" . Mage::helper('cueconnect')->getWebhookConfigurationChangedKey() . Mage::helper('cueconnect')->getWebhookConfigurationChangedUrl() . $placeApiKey;
            $key = sha1($str) . '$' . $placeApiKey;

            // Submit config changes to Cue

            $result = $this->_doRequest(Mage::helper('cueconnect')->getWebhookConfigurationChangedUrl(), $key, $params);

            if($result === "enabled") return true;
            return false;
        }

    }


    /**########################################################################################################################
     *
     * List of Function for > PRODUCT / MARK Synchronization with CUE
     *
     * ########################################################################################################################
     */


    /**
     * @action : Individual product
     * @description : Checked updated product SKU Before the event
     * @author : Imad.T - itouil@cueconnect.com
     * @param Varien_Event_Observer $observer
     */
    public function productSkuChanges($observer)
    {
        $product = $observer->getEvent()->getProduct();

        if ($product->hasDataChanges()) {
            try {
                /* @var string $newSku */
                $newSku = ($product->getData('sku')) ? $product->getData('sku') : null;
                /* @var string $oldSku */
                $oldSku = ($product->getOrigData('sku')) ? $product->getOrigData('sku') : null;

                if ($newSku && $oldSku && ($newSku != $oldSku)) {
                    Mage::register('old_product_sku', $oldSku);
                }
            } catch (Exception $e) {
                Mage::log($e->getTraceAsString(), null, 'product_changes_fault.log');
            }
        }
    }

    /**
     * @action : Multiple product
     * @description : Sync updated products data with CUE on bulk product's attributes update (Magento -> Cue)
     * @author : Imad.T - itouil@cueconnect.com
     * @param Varien_Event_Observer $observer
     * @return $this
     * @support enabled
     */
    public function productMassUpdate(Varien_Event_Observer $observer)
    {

        $productIds = $observer->getProductIds();
        $attributesData = $observer->getAttributesData();
        $this->_syncMultipleProduct($productIds, $attributesData);

    }

    /**
     * @action : Individual product
     * @description : Update product in Cue when updated in Magento
     * @author : Imad.T - itouil@cueconnect.com
     * @param  Varien_Event_Observer $observer
     * @support enabled
     */
    public function productUpdated(Varien_Event_Observer $observer)
    {

        // Init vars
        $product = $observer->getEvent()->getProduct();
        $old_sku = null;

        // Check if SKU changed, if Yes grab
        if (Mage::registry('old_product_sku')) {
            $old_sku = Mage::registry('old_product_sku');
            Mage::unregister('old_product_sku');
        }
        
        // Get Store and its API Key
        $storeids = $product->getStoreIds();
        $store = Mage::getModel('core/store')->load($storeids[0]);
        $placeApiKey = $store->getConfig('cueconnect/credentials/api_key');

        if($placeApiKey){

            // Generate the Auth Key
            $str = $product->getSku() . Mage::helper('cueconnect')->getWebhookProductChangedKey() . Mage::helper('cueconnect')->getWebhookProductChangedUrl() . $product->getId();
            $key = sha1($str) . '$' . $placeApiKey;

            // Generate Image data
            $width = Mage::getStoreConfig('cueconnect/image/width');
            $height = Mage::getStoreConfig('cueconnect/image/height');
            $image = 'https://www.cueconnect.com/images/no_image.gif';
            if ($product->getData('small_image') && $product->getData('small_image') !== 'no_selection') {
                $image = (string)Mage::helper('catalog/image')->init($product, 'small_image')->resize($width, $height);
            }

            // Format Product Data
            $params = array(
                'id'                    => $product->getId(),
                'sku'                   => (string)$product->getSku(),
                'name'                  => $product->getName(),
                'description'           => (string)$product->getDescription(),
                'brand'                 => (string)$product->getAttributeText('manufacturer'),
                'upc'                   => uniqid(),
                'sms_name'              => $product->getName(),
                'sms_desc'              => (string)$product->getDescription(),
                'url'                   => $product->getProductUrl(),
                'taxonomy_id'           => Mage::getStoreConfig('cueconnect/taxomomy_id'),
                'image'                 => $image,
                'price'                 => number_format(Mage::helper('core')->currency($product->getPrice(), false, false), 2),
            );

            if($old_sku) $params['old_sku'] = $old_sku;

            // Get Cue WebHook URL
            $url = Mage::helper('cueconnect')->getWebhookProductChangedUrl();
            // Make request
            $this->_doRequest($url, $key, $params);

        }else{
            // Say API key is wrong to the admin
            $message = Mage::helper('cueconnect')->__(
                'Cue Connect authentication issue :: Go to System > Configuration > Under Catalog select Cue Connect, and set/verify your Cue credentials ',
                $store->getName()
            );
            Mage::getSingleton('adminhtml/session')->addError($message);

            // Send support request to Cue Support team
            $cueModel = Mage::getModel('cueconnect/cueconnect');
            $cueModel->sendEmailToSupport(
                "Observer > productUpdated()",
                $store->getBaseUrl(Mage_Core_Model_Store::URL_TYPE_WEB),
                $store->getName(),
                Mage::getStoreConfig('cueconnect/credentials/retailer_id'),
                Mage::getStoreConfig('cueconnect/credentials/login', $store->getId()),
                "<br/>Debug<br/>placeApiKey Not defined"
            );
        }


    }

    /**
     * @action : Individual product
     * @description : delete product from e-List when deleted in Magento (Magento -> Cue)
     * @author : Imad.T - itouil@cueconnect.com
     * @param  Varien_Event_Observer $observer
     */
    public function deleteProduct(Varien_Event_Observer $observer)
    {
        // Get catalog product
        $product = $observer->getEvent()->getProduct();

        // For each related stores
        $storeids = $product->getStoreIds();
        // Get store
        $store = Mage::getModel('core/store')->load($storeids[0]);

        if($store->getConfig('cueconnect/enabled/enabled')){

            $placeApiKey = $store->getConfig('cueconnect/credentials/api_key');
            $str = $product->getSku() . Mage::helper('cueconnect')->getWebhookProductDeletedKey() . Mage::helper('cueconnect')->getWebhookProductDeletedUrl() . $product->getId();
            $key = sha1($str) . '$' . $placeApiKey;

            $params = array(
                'id'  => $product->getId(),
                'sku' => (string)$product->getSku()
            );

            $url = Mage::helper('cueconnect')->getWebhookProductDeletedUrl();
            $this->_doRequest($url, $key, $params);

        }

    }

    /**
     * @action : Multiple product
     * @description : Sync imported products data with CUE (Magento -> Cue)
     * @author : Imad.T - itouil@cueconnect.com
     * @param  Varien_Event_Observer $observer
     * @support enabled
     */
    public function productsImportUpdate(Varien_Event_Observer $observer)
    {
        $adapter = $observer->getEvent()->getAdapter();
        $productIds = $adapter->getAffectedEntityIds();
        $this->_syncMultipleProduct($productIds);

    }

    /**
     * @action : Multiple products
     * @description : Sync updated products data with CUE
     * @author : Imad.T - itouil@cueconnect.com
     * @support enabled
     */
    protected function _syncMultipleProduct($productIds, $attributesData = null){

        if (count($productIds)) {

            $productUpdateModel = Mage::getModel('cueconnect/cueconnect');
            $productCollection = $productUpdateModel->getProductsByIds($productIds);

            foreach ($productCollection as $product) {

                // Get Store and its API Key
                $storeids = $product->getStoreIds();
                $store = Mage::getModel('core/store')->load($storeids[0]);
                $placeApiKey = $store->getConfig('cueconnect/credentials/api_key');

                if($placeApiKey){

                    // Generate the Auth Key
                    $str = $product->getSku() . Mage::helper('cueconnect')->getWebhookProductChangedKey() . Mage::helper('cueconnect')->getWebhookProductChangedUrl() . $product->getId();
                    $key = sha1($str) . '$' . $placeApiKey;

                    // Generate Image data
                    $width = Mage::getStoreConfig('cueconnect/image/width');
                    $height = Mage::getStoreConfig('cueconnect/image/height');
                    $image = 'https://www.cueconnect.com/images/no_image.gif';
                    if ($product->getData('small_image') && $product->getData('small_image') !== 'no_selection') {
                        $image = (string)Mage::helper('catalog/image')->init($product, 'small_image')->resize($width, $height);
                    }

                    // Format Product Data
                    $params = array(
                        'id'                    => $product->getId(),
                        'sku'                   => (string)$product->getSku(),
                        'name'                  => $product->getName(),
                        'description'           => (string)$product->getDescription(),
                        'brand'                 => (string)$product->getAttributeText('manufacturer'),
                        'upc'                   => uniqid(),
                        'sms_name'              => $product->getName(),
                        'sms_desc'              => (string)$product->getDescription(),
                        'url'                   => $product->getProductUrl(),
                        'taxonomy_id'           => Mage::getStoreConfig('cueconnect/taxomomy_id'),
                        'image'                 => $image,
                        'price'                 => number_format(Mage::helper('core')->currency($product->getPrice(), false, false), 2),
                    );

                    // Append new data if exist
                    if($attributesData){
                        foreach ($attributesData as $att_key => $att_value){
                            $params[$att_key] = $att_value;
                        }
                    }


                    // Get Cue WebHook URL
                    $url = Mage::helper('cueconnect')->getWebhookProductChangedUrl();

                    // Make request
                    $this->_doRequest($url, $key, $params);

                }else{
                    // Say API key is wrong to the admin
                    $message = Mage::helper('cueconnect')->__(
                        'Cue Connect authentication issue :: Go to System > Configuration > Under Catalog select Cue Connect, and set/verify your Cue credentials ',
                        $store->getName()
                    );
                    Mage::getSingleton('adminhtml/session')->addError($message);

                    // Send support request to Cue Support team
                    $cueModel = Mage::getModel('cueconnect/cueconnect');
                    $cueModel->sendEmailToSupport(
                        "Observer > _syncMultipleProduct()",
                        $store->getBaseUrl(Mage_Core_Model_Store::URL_TYPE_WEB),
                        $store->getName(),
                        Mage::getStoreConfig('cueconnect/credentials/retailer_id'),
                        Mage::getStoreConfig('cueconnect/credentials/login', $store->getId()),
                        "<br/>Debug<br/>placeApiKey Not defined"
                    );
                }

            }

        }

    }


    /**########################################################################################################################
     *
     * List of Function for > CUSTOMER Synchronization with CUE
     *
     * ########################################################################################################################
     */

    /**
     *
     * @action : Multiple customers
     * @description : Sync all customers with CUE
     * @author : Imad.T - itouil@cueconnect.com
     * @support enabled
     * @return bool
     */
    public function syncAllCustomers()
    {

        $notSyncPrev = $this->checkNotSyncedCustomer();
        $storeCollection = Mage::getModel('core/store')->getCollection()->setLoadDefault(true);

        foreach ($storeCollection as $store) {
            $this->_currentStoreId = $store->getId();
            $this->_currentStoreName = $store->getName();

            // return sync status for current store
            $status = $this->_getCustomerSyncStatus($store);

            // skip if customers sync for current store complete
            if ($status == self::CUSTOMER_SYNC_COMPLETE) {
                continue;
            }

            // Skip all if customer sync is processing
            if ($status == self::CUSTOMER_SYNC_PROCESSING) {
                return false;
            }

            // set $status processing for current store
            $this->setCustomersSyncStatus(self::CUSTOMER_SYNC_PROCESSING);
            $customerCollection = Mage::getModel('customer/customer')->getCollection()
                ->addFieldToFilter('store_id', $this->_currentStoreId);
            // start sync of customers with Cue
            foreach ($customerCollection as $customer) {
                $this->_syncCustomer($customer);
            }

            //check the cueconnect_user_sync and find the customers with status - STATUS_ERROR (2) after sync
            $notSyncUserIds = $this->checkNotSyncedCustomer();

            if (count($notSyncPrev) < count($notSyncUserIds)) {
                $error_msg = '';
                if (count($this->_errors)) {
                    $error_msg = 'Errors: ' . '<br/>';
                    $number = 1;
                    foreach ($this->_errors as $error) {
                        $error_msg .= $number . '. ' . $error . '<br/>';
                        ++$number;
                    }
                }

                // Send support request to Cue Support team
                $cueModel = Mage::getModel('cueconnect/cueconnect');
                $cueModel->sendEmailToSupport(
                    "Observer > syncAllCustomers()",
                    $store->getBaseUrl(Mage_Core_Model_Store::URL_TYPE_WEB),
                    $store->getName(),
                    Mage::getStoreConfig('cueconnect/credentials/retailer_id'),
                    Mage::getStoreConfig('cueconnect/credentials/login', $store->getId()),
                    "<br/>Debug<br/>$error_msg"
                );


            }

            $notSyncPrev = $notSyncUserIds;

        }

    }

    /**
     * @description : Get customer Sync status for the store.
     * @author : Imad.T - itouil@cueconnect.com
     * @return mixed
     */
    protected function _getCustomerSyncStatus($store)
    {
        $path = sprintf(self::XML_PATH_CUSTOMER_SYNC_STATUS, $store->getId());
        $status = $store->getConfig($path);

        return $status;
    }

    /**
     *
     * @action : Single customers
     * @description : flag local user as synced and create Cue user
     * @author : Imad.T - itouil@cueconnect.com
     * @support enabled
     * @param  Mage_Customer_Model_Customer $customer
     * @return bool
     */
    protected function _syncCustomer($customer)
    {

        if ($customer) {
            $this->createCueUser($customer);
        }
    }

    /**
     * @action : Single customers
     * @description : Sync user with Cue
     * @author : Imad.T - itouil@cueconnect.com
     * @param  Mage_Customer_Model_Customer $customer
     * @return bool
     */
    protected function createCueUser($customer) {

        if ($customer) {

            $storeId = $customer->getStoreId();

            if  (!$storeId && $this->_currentStoreId ) {
                $storeId = $this->_currentStoreId;
            }
            if (!$storeId) {
                $storeId = Mage::app()->getStore()->getStoreId();
            }
            if (!$storeId) {
                $storeId = Mage::app()->getDefaultStoreView()->getStoreId();
            }

            $store = Mage::getModel('core/store')->load($storeId);
            $placeApiKey = $store->getConfig('cueconnect/credentials/api_key');

            $str = Mage::helper('cueconnect')->getWebhookSaveCustomerKey() . Mage::helper('cueconnect')->getWebhookSaveCustomerUrl() . $customer->getId() . $customer->getEmail();

            $key = sha1($str) . '$' . $placeApiKey;

            $params = array(
                'storeId'   => $storeId,
                'id'        => $customer->getId(),
                'email'     => $customer->getEmail(),
                'fullName'  => $customer->getName(),
                'firstName' => $customer->getFirstname(),
                'lastName'  => $customer->getLastname(),
                'created'   => $customer->getCreatedAt(),
                'dob'       => $customer->getDob(),
                'gender'    => $customer->getGender(),
            );

            $url = Mage::helper('cueconnect')->getWebhookSaveCustomerUrl();

            $response = $this->_doRequest($url, $key, $params);

            return $response;
        }

        return null;
    }

    /**
     * @action : Single customers
     * @description : check the cueconnect_user_sync and find the customers with status - STATUS_ERROR (2)
     * @author : Imad.T - itouil@cueconnect.com
     */
    public function checkNotSyncedCustomer()
    {
        /** @var CueConnect_Cue_Model_UserSync $userSyncModel */
        $userSyncModel = Mage::getModel('cueconnect/userSync');
        $notSyncUserIds = array();
        $userSyncCollection = $userSyncModel->getCollection()
            ->addFieldToFilter('status', $userSyncModel::STATUS_ERROR);
        foreach ($userSyncCollection->getItems() as $user) {
            $notSyncUserIds[] = $user->getId();
        }

        return $notSyncUserIds;
    }

    /**
     * @description : Set and save customers sync status for the store
     * @author : Imad.T - itouil@cueconnect.com
     * @param string $value
     */
    protected function setCustomersSyncStatus($value)
    {
        $path = sprintf(self::XML_PATH_CUSTOMER_SYNC_STATUS, $this->_currentStoreId);
        Mage::getModel('core/config')->saveConfig($path, $value);
        Mage::app()->getCacheInstance()->cleanType('config');
    }

    /**
     * @description : Remove schedule customer Sync. Set flat to 0
     * @author : Imad.T - itouil@cueconnect.com
     */
    public function removeScheduleCustomerSync()
    {
        Mage::getModel('core/config')->saveConfig(self::XML_PATH_CUSTOMER_SYNC_SCHEDULED, 0);
        Mage::app()->getStore()->setConfig(self::XML_PATH_CUSTOMER_SYNC_SCHEDULED, 0);
        Mage::app()->getCacheInstance()->cleanType('config');
    }

    /**
     * @description : Schedule customer Sync. Set flat to 1
     * @author : Imad.T - itouil@cueconnect.com
     */
    public function scheduleCustomerSync()
    {
        Mage::getModel('core/config')->saveConfig(self::XML_PATH_CUSTOMER_SYNC_SCHEDULED, 1);
        Mage::app()->getCacheInstance()->cleanType('config');
    }

    /*### To be reviewed ###*/
    /**
     * Check customer sync status for the stores, return true when resync should be running.
     * @todo: to remove with /Cue/Block/System/Config/Customerresync.php
     * @return bool
     */
    public function isCustomerReSyncNeeded()
    {
        $scheduled = Mage::getStoreConfigFlag(self::XML_PATH_CUSTOMER_SYNC_SCHEDULED);
        if ($scheduled) {

            return false;
        }
        $storeCollection = Mage::getModel('core/store')->getCollection()
            ->setLoadDefault(true);
        foreach ($storeCollection as $store) {
            $path = sprintf(self::XML_PATH_CUSTOMER_SYNC_STATUS, $store->getId());
            $status = $store->getConfig($path);
            if ($status == self::CUSTOMER_SYNC_PROCESSING) {

                return false;
            }
            if ($status == self::CUSTOMER_SYNC_FAILED) {

                return true;
            }

        }
        $retailer_id = Mage::getStoreConfig('cueconnect/credentials/retailer_id');
        if (!is_null($retailer_id)) {

            $this->scheduleCustomerSync();
        }

        return false;
    }

    /**
     * accessing e-List - used to sync saved items to Cue if not already done
     * @param  Varien_Event_Observer $observer
     */
    public function viewElist(Varien_Event_Observer $observer) {
        if (Mage::getSingleton('customer/session')->isLoggedIn()) {
            $event = $observer->getEvent();
            $customer = $event->getCustomer();

            if ($customer) {
                $wishList = Mage::getModel('wishlist/wishlist')->loadByCustomer($customer);

                if ($wishList) {
                    $wishListItemCollection = $wishList->getItemCollection();

                    if (count($wishListItemCollection)) {
                        foreach ($wishListItemCollection as $item) {
                            $this->_syncMark($item->getProduct(), $item->getDescription(), $customer);
                        }
                    }
                }
            }
        }
    }

    /**
     * Get order ids for the multishipping checkout, add it to session
     * @todo: to review
     * @param Varien_Event_Observer $observer
     */
    public function getOrderIds(Varien_Event_Observer $observer)
    {
        $orderIds = $observer->getOrderIds();
        if ($orderIds && count($orderIds)) {
            Mage::getSingleton('checkout/session')->setFirstOrderId($orderIds[0]);
        }
    }

    /**
     * sync customer account with Cue account after login
     * @param  Varien_Event_Observer $observer
     */
    public function customerLogin(Varien_Event_Observer $observer) {
        if (Mage::getSingleton('customer/session')->isLoggedIn()) {
            $event = $observer->getEvent();
            $customer = $event->getCustomer();

            if ($customer) {
                $this->_syncCustomer($customer);
            }
        }
    }

    /**
     * sync magento customer profile with cue user (Magento -> Cue)
     * @param  Varien_Event_Observer $observer
     */
    public function customerSaveProfile(Varien_Event_Observer $observer) {
        $event = $observer->getEvent();
        $customer = $event->getCustomer();

        if ($customer) {
            $this->_syncCustomer($customer);
        }
    }

    /**########################################################################################################################
     *
     * List of Local Functions
     *
     * ########################################################################################################################
     */


    /**
     * @description : Request to send data to Cue Connect
     * @author : Imad.T - itouil@cueconnect.com
     * @param string  $url
     * @param string $key
     * @param array $params
     * @return array
     */
    protected function _doRequest($url, $key, $params)
    {
        $response = null;

        // do POST - use curl
        if (function_exists('curl_version')) {

            try {
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_HEADER, 0);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER,array('X-Cue-Mage-Auth: ' . $key));

                $response = curl_exec($ch);
                $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

                curl_close($ch);

            } catch (Exception $e) {
                $message = $e->getMessage();
                Mage::log($message);
                $this->_errors[] = $message;
            }
        }
        // do GET
        else {

            $params['key'] = $key;
            $queryString  = '?' . http_build_query($params);

            try {
                $response = file_get_contents($url . $queryString);
            } catch (Exception $e) {
                $message = $e->getMessage();
                Mage::log($message);
                $this->_errors[] = $message;
            }
        }

        return $response;
    }

    /**
     * Copy native wishlist items to e-List saves when accessing e-List for first time
     * @author : Imad.T - itouil@cueconnect.com
     * @param  [type] $product
     * @param  [type] $description
     * @param  [type] $customer
     * @todo: To review
     */
    protected function _syncMark($product, $description, $customer)
    {
        if ($customer && $product) {
            $exception = false;
            $wishlistSyncModel = Mage::getModel('cueconnect/wishlistSync');

            if ($wishlistSyncModel) {
                $row = $wishlistSyncModel->getCollection()
                    ->addFieldToFilter('product_id', $product->getId())
                    ->addFieldToFilter('customer_id', $customer->getId())
                    ->addFieldToFilter('status', $wishlistSyncModel::STATUS_DONE)
                    ->getFirstItem();

                if (!$row->getData()) {
                    $wishlistSyncModel->setData(array(
                        'customer_id' => $customer->getId(),
                        'product_id' => $product->getId(),
                        'status' => $wishlistSyncModel::STATUS_WAITING,
                        'created_at' => date('Y-m-d H:i:s')
                    ));

                    $id = $wishlistSyncModel->save()->getId();

                    // sync product/customer
                    $response = $this->createCueProduct($product, $description, $customer);
                    $exception = ($response != 1);

                    // update status
                    $row = $wishlistSyncModel->load($id);
                    if ($row->getData()) {
                        $row->addData(array(
                            'status' => ($response) ? $wishlistSyncModel::STATUS_DONE : $wishlistSyncModel::STATUS_ERROR,
                        ));
                        $row->save();
                    }
                }
            }

            // if unable to save local flag, then sync product/customer with Cue anyway => this means the op will be executed each time we access eList
            if ($exception) {
                $this->createCueProduct($product, $customer);
            }
        }
    }

    /**
     * Create product
     * @todo: To remove with _syncMark()
     * @param $product
     * @param $description
     * @param $customer
     *
     * @return null
     */
    protected function createCueProduct($product, $description, $customer)
    {
        if ($product && $customer) {
            $storeId = $customer->getStoreId();
            if (!$storeId) {
                $storeId = Mage::app()->getStore()->getStoreId();
            }

            $store = Mage::getModel('core/store')->load($storeId);
            $placeApiKey = $store->getConfig('cueconnect/credentials/api_key');

            $str = Mage::helper('cueconnect')->getWebhookSaveMarkKey() . Mage::helper('cueconnect')->getWebhookSaveMarkUrl() . $customer->getId() . $customer->getEmail();

            $key = sha1($str) . '$' . $placeApiKey;

            $width = Mage::getStoreConfig('cueconnect/image/width');
            $height = Mage::getStoreConfig('cueconnect/image/height');

            $image = 'https://www.cueconnect.com/images/no_image.gif';
            if ($product->getData('small_image') && $product->getData('small_image') !== 'no_selection') {
                $image = (string)Mage::helper('catalog/image')->init($product, 'small_image')->resize($width, $height);
            }

            $params = array(
                // customer
                'storeId'               => $storeId,
                'id'                    => $customer->getId(),
                'email'                 => $customer->getEmail(),
                'fullName'              => $customer->getName(),
                'firstName'             => $customer->getFirstname(),
                'lastName'              => $customer->getLastname(),
                'created'               => $customer->getCreatedAt(),
                'dob'                   => $customer->getDob(),
                'gender'                => $customer->getGender(),

                // product
                'sku'                   => (string)$product->getSku(),
                'name'                  => $product->getName(),
                'description'           => (string)$product->getDescription(),
                'comment'               => (string)$description,
                'brand'                 => (string)$product->getAttributeText('manufacturer'),
                'upc'                   => uniqid(),
                'sms_name'              => $product->getName(),
                'sms_desc'              => (string)$product->getDescription(),
                'url'                   => $product->getProductUrl(),
                'taxonomy_id'           => Mage::getStoreConfig('cueconnect/taxomomy_id'),
                'image'                 => $image,
                'live'                  => '1',
                'price'                 => number_format(Mage::helper('core')->currency($product->getPrice(), false, false), 2),
            );

            $url = Mage::helper('cueconnect')->getWebhookSaveMarkUrl();
            $response = $this->_doRequest($url, $key, $params);

            return $response;
        }

        return null;
    }


}