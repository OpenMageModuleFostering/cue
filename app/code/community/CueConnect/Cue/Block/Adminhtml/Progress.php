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

class CueConnect_Cue_Block_Adminhtml_Progress extends Mage_Adminhtml_Block_Template {
    
    /**
     * Get last export progress
     *
     * @return array
     */
    protected function getLastExportProgress()
    {
        // Get data
        $last_export_progress = Mage::helper('cueconnect')->getExportProgress();
        
        if ($last_export_progress && is_object($last_export_progress)) {
            // Percentage
            $last_export_progress->completion = 0;
            if (isset($last_export_progress->total_products)) {
                $total_to_do = $last_export_progress->total_products_to_create + $last_export_progress->total_products_to_update + $last_export_progress->total_products_to_delete;
                $total_done = $last_export_progress->total_products_created + $last_export_progress->total_products_updated + $last_export_progress->total_products_deleted;
                $last_export_progress->completion = ceil(($total_done / $total_to_do) * 100);
            }
            
            // Message
            if (isset($last_export_progress->message) && $last_export_progress->message) {
                $last_export_progress->message = $last_export_progress->message;
            }
            else {
                if ($last_export_progress->completion == 0) {
                    $last_export_progress->message = "Starting...";
                }
                elseif ($last_export_progress->completion == 100) {
                    $last_export_progress->message = "Completed";
                }
                else {
                    $last_export_progress->message = "Progressing...";
                }
            }
            
            return $last_export_progress;
        }
        else {
            return false;
        }
    }
    
}
