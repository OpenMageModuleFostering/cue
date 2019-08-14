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
                            $this->syncMark($item->getProduct(), $item->getDescription(), $customer);
                        }
                    }    
                }
            }
        }
    }


    /**
     * Add e-List to menu
     * @param  Varien_Event_Observer $observer
     
    public function regenerateMenu(Varien_Event_Observer $observer) {
        // if module is active
        if (!Mage::getStoreConfig('advanced/modules_disable_output/CueConnect_Cue')) {
            $layout = Mage::getSingleton('core/layout');

            // remove all the blocks you don't want
            //$layout->getUpdate()->addUpdate('<remove name="catalog.topnav"/>');

            // load layout updates by specified handles
            $layout->getUpdate()->load();

            // generate xml from collected text updates
            $layout->generateXml();

            // generate blocks from xml layout
            $layout->generateBlocks();
        }
    }*/
    


    /**
     * sync customer account with Cue account after login
     * @param  Varien_Event_Observer $observer
     */
    public function customerLogin(Varien_Event_Observer $observer) { 
        if (Mage::getSingleton('customer/session')->isLoggedIn()) { 
            $event = $observer->getEvent();
            $customer = $event->getCustomer();

            if ($customer) {
                $this->syncCustomer($customer);
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
            $this->syncCustomer($customer);
        }
    }


    /**
     * sync product data with e-List product (Magento -> Cue)
     * @param  Varien_Event_Observer $observer [description]
     */
    public function updateProduct(Varien_Event_Observer $observer)
    {
        // Get catalog product
        $catalog_product = $observer->getEvent()->getProduct();
        
        // For each related stores
        foreach ($catalog_product->getStoreIds() as $store_id) {
            // Get store
            $store = Mage::getModel('core/store')->load($store_id);
            if ($store->getConfig('cueconnect/enabled/enabled')) {
                // Retailuser SOAP client
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
                    Mage::log($e->getMessage());
                }

                if ($place_id) {
                    // Product SOAP client
                    $soap_client = Mage::helper('cueconnect')->getSoapClient(
                        Mage::helper('cueconnect')->getWsUrl('product'),
                        $store->getConfig('cueconnect/credentials/login'),
                        $store->getConfig('cueconnect/credentials/password')
                    );

                    // Product icon
                    $icon = "http://www.cueconnect.com/images/no_image.gif";
                    if ($catalog_product->getData('image')) {
                        $icon = $catalog_product->getMediaConfig()->getMediaUrl($catalog_product->getData('image'));
                    }

                    // Get product and update/create product
                    try {
                        $result = $soap_client->get(array(
                            'place_id' => $place_id,
                            'sku' => $catalog_product->getSku(),
                            'page' => 1,
                            'page_size' => 1
                        ));
                        if ($result && isset($result->data) && isset($result->data[0]) && isset($result->inpagecount) && $result->inpagecount) {
                            $cueconnect_product = $result->data[0];
                            $data = array(
                                'product_imic' => null,
                                'sku' => $catalog_product->getSku(),
                                'name' => $catalog_product->getName(),
                                'description' => $catalog_product->getDescription(),
                                'sms_name' => $catalog_product->getName(),
                                'sms_desc' => $catalog_product->getDescription(),
                                'url' => $catalog_product->getProductUrl(),
                                'taxonomy_id' => Mage::getStoreConfig('cueconnect/taxomomy_id'),
                                'icon' => $icon,
                                'live' => '1',
                                'price' => $catalog_product->getPrice()
                            );
                            $soap_client->set(array(
                                'place_id' => $place_id,
                                'data' => array(0 => $data),
                                'count' => 1
                            ));
                        }
                        else {
                            $data = array(
                                'sku' => $catalog_product->getSku(),
                                'upc' => uniqid(),
                                'name' => $catalog_product->getName(),
                                'description' => $catalog_product->getDescription(),
                                'sms_name' => $catalog_product->getName(),
                                'sms_desc' => $catalog_product->getDescription(),
                                'url' => $catalog_product->getProductUrl(),
                                'taxonomy_id' => Mage::getStoreConfig('cueconnect/taxomomy_id'),
                                'icon' => $icon,
                                'live' => '1',
                                'price' => $catalog_product->getPrice()
                            );
                            $soap_client->create(array(
                                'place_id' => $place_id,
                                'data' => array(0 => $data),
                                'count' => 1
                            ));
                        }
                    }
                    catch (Exception $e) {
                        Mage::log($e->getMessage());
                    }
                }
            }
        }
    }
    
    /**
     * update config in Cue when updated in Magento (Magento -> Cue)
     * @param  Varien_Event_Observer $observer
     */
    public function adminCueConnectUpdated(Varien_Event_Observer $observer)
    {
        $storeId = $observer->getEvent()->getStore();
        $store = Mage::getModel('core/store')->load($storeId);
        if ($store) {
            $post = Mage::app()->getRequest()->getPost();
            $version = $post['groups']['mode']['fields']['mode']['value'];

            $placeApiKey = $store->getConfig('cueconnect/credentials/api_key');
            $str = "v$version" . Mage::helper('cueconnect')->getWebhookSelectVersionKey() . Mage::helper('cueconnect')->getWebhookSelectVersionUrl() . $placeApiKey;
            $key = sha1($str) . '$' . $placeApiKey;
            
            $params = array(
                'version' => $version
            );

            $retailerId = (int)$this->doRequest(Mage::helper('cueconnect')->getWebhookSelectVersionUrl(), $key, $params);
            
            if ($retailerId) {
                /*$config = new Mage_Core_Model_Config();
                $config->saveConfig('cueconnect/credentials/retailer_id', $retailerId, 'default', 1);
                Mage::app()->getCacheInstance()->cleanType('config');
                */
                Mage::getModel('core/config')->saveConfig('cueconnect/credentials/retailer_id', $retailerId);    
                Mage::app()->getCacheInstance()->cleanType('config');
            }
        }
    }

    /**
     * update product in Cue when updated in Magento (Magento -> Cue)
     * @param  Varien_Event_Observer $observer
     */
    public function adminProductUpdated(Varien_Event_Observer $observer)
    {       
        $product = $observer->getEvent()->getProduct();
        
        // For each related stores
        foreach ($product->getStoreIds() as $storeId) {
            $store = Mage::getModel('core/store')->load($storeId);
            $placeApiKey = $store->getConfig('cueconnect/credentials/api_key');

            $str = $product->getSku() . Mage::helper('cueconnect')->getWebhookPriceChangedKey() . Mage::helper('cueconnect')->getWebhookPriceChangedUrl() . $product->getId();

            $key = sha1($str) . '$' . $placeApiKey;

            $width = Mage::getStoreConfig('cueconnect/image/width');
            $height = Mage::getStoreConfig('cueconnect/image/height');

            $image = 'https://www.cueconnect.com/images/no_image.gif';
            if ($product->getData('small_image') && $product->getData('small_image') !== 'no_selection') {
                $image = (string)Mage::helper('catalog/image')->init($product, 'small_image')->resize($width, $height);
            }

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

            $url = Mage::helper('cueconnect')->getWebhookPriceChangedUrl();

            $this->doRequest($url, $key, $params);
        }
    }
    

    /**
     * delete product from e-List when deleted in Magento (Magento -> Cue)
     * @param  Varien_Event_Observer $observer
     */
    public function deleteProduct(Varien_Event_Observer $observer)
    {
        // Get catalog product
        $catalog_product = $observer->getEvent()->getProduct();
        
        // For each related stores
        foreach ($catalog_product->getStoreIds() as $store_id) {
            // Get store
            $store = Mage::getModel('core/store')->load($store_id);
            if ($store->getConfig('cueconnect/enabled/enabled')) {
                // Retailuser SOAP client
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
                    Mage::log($e->getMessage());
                }

                // Product SOAP client
                $soap_client = Mage::helper('cueconnect')->getSoapClient(
                    Mage::helper('cueconnect')->getWsUrl('product'),
                    $store->getConfig('cueconnect/credentials/login'),
                    $store->getConfig('cueconnect/credentials/password')
                );
                
                // Get and delete Cue Connect product
                try {
                    $result = $soap_client->get(array(
                        'place_id' => $place_id,
                        'sku' => $catalog_product->getSku(),
                        'page' => 1,
                        'page_size' => 1
                    ));
                    if ($result && isset($result->data) && isset($result->data[0]) && isset($result->inpagecount) && $result->inpagecount) {
                        $cueconnect_product = $result->data[0];
                        $result = $soap_client->delete(array(
                            'place_id' => $place_id,
                            'data' => array($cueconnect_product->product_imic),
                            'count' => 1
                        ));
                    }
                }
                catch (Exception $e) {
                    Mage::log($e->getMessage());
                }                
            }
        }
    }


    /**
     * sync function
     * @param  Mage_Customer_Model_Customer $customer
     */
    protected function createCueUser($customer) {
        if ($customer) {
            $storeId = $customer->getStoreId();
            if (!$storeId) {
                $storeId = Mage::app()->getStore()->getStoreId();
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
            $response = $this->doRequest($url, $key, $params);
            
            // do POST - use curl
            /*if (function_exists('curl_version')) {
                try {
                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_URL, $url);
                    curl_setopt($ch, CURLOPT_HEADER);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                    curl_setopt($ch, CURLOPT_HTTPHEADER,array('X-Cue-Mage-Auth: ' . $key));

                    $response = curl_exec($ch);
                    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);  
                    
                    curl_close($ch);
                    
                } catch (Exception $e) {
                    Mage::log($e->getMessage());
                }
            }
            // do GET
            else {
                $params['key'] = $key;
                $queryString  = '?' . http_build_query($params);
                
                try {
                    $response = file_get_contents($url . $queryString);
                } catch (Exception $e) {
                    Mage::log($e->getMessage());
                }
            }*/

            return $response;
        } 

        return null;
    }


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
            $response = $this->doRequest($url, $key, $params);

            // do POST - use curl
            /*if (function_exists('curl_version')) {
                try {
                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_URL, $url);
                    curl_setopt($ch, CURLOPT_HEADER);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                    curl_setopt($ch, CURLOPT_HTTPHEADER,array('X-Cue-Mage-Auth: ' . $key));

                    $response = curl_exec($ch);
                    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);  
                    
                    curl_close($ch);
                    
                } catch (Exception $e) {
                    Mage::log($e->getMessage());
                }
            }
            // do GET
            else {
                $params['key'] = $key;
                $queryString  = '?' . http_build_query($params);
                
                try {
                    $response = file_get_contents($url . $queryString);
                } catch (Exception $e) {
                    Mage::log($e->getMessage());
                }
            }*/

            return $response;
        } 

        return null;
    }



    /**
     * flag local user as synced and create Cue user
     * @param  Mage_Customer_Model_Customer $customer 
     */
    protected function syncCustomer($customer) 
    {
        if ($customer) {
            $exception = false;
            $userSyncModel = Mage::getModel('cueconnect/userSync'); 

            if ($userSyncModel) {
                $row = $userSyncModel->getCollection()
                    ->addFieldToFilter('customer_id', $customer->getId())
                    ->addFieldToFilter('status', $userSyncModel::STATUS_DONE)
                    ->getFirstItem();  

                if (!$row->getData()) {
                    $userSyncModel->setData(array(
                        'customer_id' => $customer->getId(),
                        'status' => $userSyncModel::STATUS_WAITING,
                        'created_at' => date('Y-m-d H:i:s')
                    ));

                    $id = $userSyncModel->save()->getId();

                    // sync customer
                    $response = $this->createCueUser($customer);
                    $exception = ($response != 1);

                    // update status
                    $row = $userSyncModel->load($id);
                    if ($row->getData()) {
                        $row->addData(array(
                            'status' => ($response) ? $userSyncModel::STATUS_DONE : $userSyncModel::STATUS_ERROR,
                        ));
                        $row->save();    
                    }
                }
            }

            // if unable to save local flag, then sync customer with Cue anyway
            if ($exception) {
                $this->createCueUser($customer);
            }
        }
    }


    /**
     * copy native wishlist items to e-List saves when accessing e-List for first time
     * @param  [type] $product  
     * @param  [type] $description 
     * @param  [type] $customer 
     */
    protected function syncMark($product, $description, $customer)
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



    protected function doRequest($url, $key, $params)
    {
        $response = null;

        // do POST - use curl
        if (function_exists('curl_version')) {
            try {
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_HEADER);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER,array('X-Cue-Mage-Auth: ' . $key));

                $response = curl_exec($ch);
                $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);  
                
                curl_close($ch);
                
            } catch (Exception $e) {
                Mage::log($e->getMessage());
            }
        }
        // do GET
        else {
            $params['key'] = $key;
            $queryString  = '?' . http_build_query($params);
            
            try { 
                $response = file_get_contents($url . $queryString);
            } catch (Exception $e) {
                Mage::log($e->getMessage());
            }
        } 

        return $response;
    }
    
}