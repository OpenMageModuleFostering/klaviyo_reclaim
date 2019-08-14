<?php

/**
 * Checkout item resource model
 *
 * @author Klaviyo Team (support@klaviyo.com)
 */

class Klaviyo_Reclaim_Model_Mysql4_Checkout extends Mage_Core_Model_Mysql4_Abstract
{

  protected $_isPkAutoIncrement = false;

  protected function _construct()
  {
    $this->_init('klaviyo_reclaim/checkout', 'checkout_id');
  }
}