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

class CueConnect_Cue_Helper_Data extends Mage_Core_Helper_Abstract
{
    const XML_PATH_MYLIST_ENABLED = 'cueconnect/collection/enabled';
    const XML_PATH_TRACKING_ENABLED = 'cueconnect/tracking/enabled';

    /**
     * Checks if Tracking is enabled
     *
     * @return bool
     */
    public function isTrackingEnabled()
    {
        return Mage::getStoreConfigFlag(self::XML_PATH_TRACKING_ENABLED);
    }

    /**
     * check if module is enabled
     *
     * @return string
     */
    public function isEnabled() {

        $status = Mage::getStoreConfig('cueconnect/enabled');

        if(is_array($status)){
            if (isset($status['enabled'])) {
                return $status['enabled'];
            }
        }

        return 0;
    }

    /**
     * Get retailer id from config
     *
     * @return string
     */
    public function getRetailerId() {
        return Mage::getStoreConfig('cueconnect/credentials/retailer_id');
    }

    /**
     * Get api key
     *
     * @return string
     */
    public function getApiKey() {
        return Mage::getStoreConfig('cueconnect/crendentials/api_key');
    }

    /**
     * Get my e-list menu generation mode
     *
     * @return bool
     */
    public function setMyListLinkAuto() {

        $enabled = Mage::getStoreConfig('cueconnect/collection');
        if (is_array($enabled)) {
            if (isset($enabled['enabled'])) {
                return $enabled['enabled'];
            }
        }

        return 0;
    }

    /**
     * Get retailer object via SOAP
     *
     * @return string
     * @deprecated
     */
    public function getRetailer($store) {
        $soap_client = Mage::helper('cueconnect')->getSoapClient(
                Mage::helper('cueconnect')->getWsUrl('place'),
                $store->getConfig('cueconnect/credentials/login'),
                $store->getConfig('cueconnect/credentials/password'));
        try {
            $result = $soap_client->get();
        }
        catch (Exception $e) {
            return;
        }
        return $result->data;
    }
    
    
    
    /**
     * Cut an array in multiple smaller array
     *
     * @return string
     * @deprecated
     */
    public function getSlicesFromArray($data, $slice_size = 30) {
        $slices = array();
        if (count($data) > $slice_size) {
            $i = 0;
            while ($i < count($data)) {
                if ($i % $slice_size == 0) {
                    $slices[] = array_slice($data, $i, $slice_size);
                }
                $i++;
            }
        }
        else {
            $slices[] = $data;
        }
        return $slices;
    }
    
    /**
     * Log JSON data in Cue export log
     *
     * @return string
     * @deprecated
     */
    public function logExportProgress($json) {
        // Create folder
        $cueconnect_var_dir = Mage::getBaseDir('var').'/cueconnect/';
        if (!file_exists($cueconnect_var_dir)) {
            mkdir($cueconnect_var_dir, 0777);
        }
        
        // Create log file
        $cueconnect_import_log_file = $cueconnect_var_dir.'export.log';
        
        // Log json in file
        $file = fopen($cueconnect_import_log_file, "w+");
        fwrite($file, $json);
        fclose($file);
    }
    
    /**
     * Get export log data
     *
     * @return string
     * @deprecated
     */
    public function getExportProgress() {
        // File path
        $cueconnect_var_dir = Mage::getBaseDir('var').'/cueconnect/';
        $cueconnect_import_log_file = $cueconnect_var_dir.'export.log';
        if (file_exists($cueconnect_import_log_file)) {
            // Read file
            $file = fopen($cueconnect_import_log_file, "r");
            $data = fread($file, 100000);
            fclose($file);

            // Return decoded data
            return json_decode($data);
        }
    }

    /**
     * Get WSSE Header
     *
     * @return string
     * @deprecated
     */
    public function getWsseHeader($login, $password) {
        $authheader = sprintf('
            <wsse:Security xmlns:wsse="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd">
                 <wsse:UsernameToken>
                    <wsse:Username>%s</wsse:Username>
                    <wsse:Password>%s</wsse:Password>
                 </wsse:UsernameToken>
            </wsse:Security>
        ', htmlspecialchars($login), htmlspecialchars($password));
        return $authheader;
    }
    
    /**
     * Get webservice URL by service name
     *
     * @return string
     * @deprecated
     */
    public function getWsUrl($service) {
        return $this->getHost('rapi').'/'.$service."?wsdl";
    }
    
    /**
     * Get SOAP client by URL
     *
     * @return string
     * @deprecated
     */
    public function getSoapClient($url, $login, $password) {
        $client = new SOAPClient($url, array('trace' => 1, 'soap_version' => SOAP_1_1));
        $authvars = new SoapVar($this->getWsseHeader($login, $password), XSD_ANYXML);
        $header = new SoapHeader("http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd", "Security",  $authvars);
        $client->__setSoapHeaders($header);
        return $client;
    }


    /**
     * Get webhook URL for adding customers to Cue
     *
     * @return string
     */
    public function getWebhookSaveCustomerUrl() {
        return $this->getHost() . Mage::getStoreConfig('cueconnect/webhook/save_customer/url');
    }

    /**
     * Get webhook auth key for adding customers to Cue
     *
     * @return string
     */
    public function getWebhookSaveCustomerKey() {

        return Mage::getStoreConfig('cueconnect/webhook/save_customer/key');
    }

    /**
     * Get webhook URL for adding marks to Cue
     *
     * @return string
     */
    public function getWebhookSaveMarkUrl() {

        return $this->getHost() . Mage::getStoreConfig('cueconnect/webhook/save_mark/url');
    }

    /**
     * Get webhook auth key for adding marks to Cue
     *
     * @return string
     */
    public function getWebhookSaveMarkKey() {

        return Mage::getStoreConfig('cueconnect/webhook/save_mark/key');
    }

    /**
     * Get webhook auth key for version change
     *
     * @return string
     */
    public function getWebhookConfigurationChangedKey() {

        return Mage::getStoreConfig('cueconnect/webhook/configuration_changed/key');
    }

    /**
     * Get webhook url for version change
     *
     * @return string
     */
    public function getWebhookConfigurationChangedUrl() {

        return $this->getHost() . Mage::getStoreConfig('cueconnect/webhook/configuration_changed/url');
    }

    /**
     * Get webhook auth key for product change
     *
     * @return string
     */
    public function getWebhookProductChangedKey() {

        return Mage::getStoreConfig('cueconnect/webhook/product_changed/key');
    }

    /**
     * Get webhook url for product change
     *
     * @return string
     */
    public function getWebhookProductChangedUrl() {

        return $this->getHost() . Mage::getStoreConfig('cueconnect/webhook/product_changed/url');
    }

    /**
     * Get webhook auth key for product deleted
     *
     * @return string
     */
    public function getWebhookProductDeletedKey() {

        return Mage::getStoreConfig('cueconnect/webhook/product_deleted/key');
    }

    /**
     * Get webhook url for product deleted
     *
     * @return string
     */
    public function getWebhookProductDeletedUrl() {

        return $this->getHost() . Mage::getStoreConfig('cueconnect/webhook/product_deleted/url');
    }

    /**
     * Get cue host depends on selected env
     *
     * @return string
     */
    public function getHost($application = 'business'){

        $ext = Mage::getStoreConfig('cueconnect/environment/env');
        if($ext && $ext != ''){
            return 'https://'.$ext.'-'.$application.'.cueconnect.net';
        }else{
            return 'https://'.$application.'.cueconnect.com';
        }

    }
    
}
