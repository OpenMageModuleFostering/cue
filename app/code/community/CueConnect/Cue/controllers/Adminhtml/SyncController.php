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

class CueConnect_Cue_Adminhtml_SyncController extends Mage_Adminhtml_Controller_Action
{
    /**
     * View export progression and last asked exports
     */
    public function indexAction() {
        // Render layout
        $this->loadLayout();
        $this->renderLayout();
    }
    
    /**
     * Manually ask for an export 
     */
    public function exportAction() {
        // Create a demand
        $demand = Mage::getModel('cueconnect/demand');
        $demand->setStatus($demand::STATUS_WAITING);
        $demand->setCreatedAt(date('Y-m-d H:i:s'));
        $demand->setUpdatedAt(date('Y-m-d H:i:s'));
        $demand->save();
        
        // Notice
        Mage::getSingleton('adminhtml/session')->addSuccess("The catalog will be exported in a few moments. Pleae make sure that Magento cron is correctly configured.");
        
        // Redirect to catalog
        return $this->_redirect('cueconnect/adminhtml_sync/index');
    }
}
