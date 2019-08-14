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
}
