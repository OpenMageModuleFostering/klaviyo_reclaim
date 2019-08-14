<?php

/**
 * Checkout item
 *
 * @author Klaviyo Team (support@klaviyo.com)
 */

class Klaviyo_Reclaim_Model_Checkout extends Mage_Core_Model_Abstract
{

  protected function _construct()
  {
    $this->_init('klaviyo_reclaim/checkout');
  }
}