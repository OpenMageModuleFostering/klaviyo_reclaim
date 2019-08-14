<?php

class Klaviyo_Reclaim_Model_System_Config_Source_List
{

  /**
   * Lists for Klaviyo account.
   *
   * @access protected
   * @var array of List objects.
   */
  protected $_lists = null;

  /**
   * Fetch lists and store on class property
   *
   * @return void
   */
  public function __construct()
  {
    if (is_null($this->_lists)) {
      $this->_lists = Mage::getSingleton('klaviyo_reclaim/api')->lists();
    }
  }

  /**
   * @return array
   */
  public function toOptionArray()
  {
    $lists = array();

    if (is_array($this->_lists)) {
      foreach ($this->_lists['data'] as $list) {
        $lists []= array('value' => $list['id'], 'label' => $list['name'] . ' (' . $list['person_count'] . ' ' . Mage::helper('klaviyo_reclaim')->__('members') . ')');
      }
    } else {
      $lists []= array('value' => '', 'label' => Mage::helper('klaviyo_reclaim')->__('--- No data ---'));
    }

    return $lists;
  }
}