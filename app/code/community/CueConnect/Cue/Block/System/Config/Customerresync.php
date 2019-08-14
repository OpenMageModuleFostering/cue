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

class CueConnect_Cue_Block_System_Config_Customerresync extends Mage_Adminhtml_Block_System_Config_Form_Field
{
    /**
     * Set template to itself
     *
     * @return CueConnect_Cue_Block_System_Config_Customerresync
     */
    protected function _prepareLayout()
    {
        parent::_prepareLayout();
        if (!$this->getTemplate()) {
            $this->setTemplate('cueconnect/customerresync.phtml');
        }

        return $this;
    }

    /**
     * Unset some non-related element parameters
     *
     * @param Varien_Data_Form_Element_Abstract $element
     * @return string
     */
    public function render(Varien_Data_Form_Element_Abstract $element)
    {
        $element->unsScope()->unsCanUseWebsiteValue()->unsCanUseDefaultValue();
        return parent::render($element);
    }

    /**
     * Get the button and scripts contents if needed
     *
     * @param Varien_Data_Form_Element_Abstract $element
     * @return string
     */
    protected function _getElementHtml(Varien_Data_Form_Element_Abstract $element)
    {
        $showButton = Mage::getModel('cueconnect/observer')->isCustomerReSyncNeeded();
        if (!$showButton) {

            return '';
        }
        $originalData = $element->getOriginalData();
        $this->addData(array(
            'button_label' => Mage::helper('cueconnect')->__($originalData['button_label']),
            'html_id' => $element->getHtmlId(),
            'ajax_url' => $this->getUrl('cueconnect/adminhtml_system_config_customersync/index')
        ));

        return $this->_toHtml();
    }
}