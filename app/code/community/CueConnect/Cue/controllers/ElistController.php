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

class CueConnect_Cue_ElistController extends Mage_Core_Controller_Front_Action
{
    /**
     * View export progression and last asked exports
     */
    public function indexAction() {
    	// if PB, redirect to home page - only CP is allowed here
		if (1 == Mage::helper('cueconnect')->getElistMode()) {
			Mage::app()->getResponse()->setRedirect(Mage::getBaseUrl());
		}

    	if (!Mage::getSingleton('customer/session')->isLoggedIn()){
    		Mage::getSingleton('customer/session')->setBeforeAuthUrl(Mage::helper('core/url')->getCurrentUrl());
    		Mage::getSingleton('customer/session')->setAfterAuthUrl(Mage::helper('core/url')->getCurrentUrl());
		}
		else {
			// fire custom event for CP so we sync accounts and wishlist items with Cue
			Mage::dispatchEvent('elist_view', array('customer' => Mage::getSingleton('customer/session')->getCustomer()));
		}
		
		// Render layout
        $this->loadLayout();

        // update page title
		$this->getLayout()->getBlock("head")->setTitle($this->__("Cue My List"));

		// show breadcrumbs
		$breadcrumbs = $this->getLayout()->getBlock("breadcrumbs");
		$breadcrumbs->addCrumb("home", array(
			"label" => $this->__("Home"),
			"title" => $this->__("Home"),
			"link"  => Mage::getBaseUrl()
		));

		$breadcrumbs->addCrumb("elist", array(
			"label" => $this->__("My-List"),
			"title" => $this->__("My-List")
		));

		// render layout
        $this->renderLayout();
    }
}
