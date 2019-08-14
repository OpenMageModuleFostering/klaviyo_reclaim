<?php

/**
 * Checkout collection
 *
 * @author Klaviyo Team (support@klaviyo.com)
 */

class Klaviyo_Reclaim_Model_Mysql4_Checkout_Collection extends Mage_Core_Model_Mysql4_Collection_Abstract
{
  protected function _construct()
  {
    $this->_init('klaviyo_reclaim/checkout');
  }
}