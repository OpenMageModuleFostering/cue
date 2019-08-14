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

class CueConnect_Cue_Helper_Product extends Mage_Core_Helper_Abstract
{
    /**
     * Get product SKU's by ID's
     *
     * @param string $productIds
     * @return string
     */
    public function getProductSkusByIds($productIds)
    {
        $adapter = Mage::getSingleton('core/resource')->getConnection('core_read');
        $productTableName = Mage::getSingleton('core/resource')->getTableName('catalog/product');
        $select = $adapter->select()
            ->from($productTableName, array('entity_id', 'sku'))
            ->where('entity_id IN (?)', $productIds);

        return $adapter->fetchPairs($select);
    }
}