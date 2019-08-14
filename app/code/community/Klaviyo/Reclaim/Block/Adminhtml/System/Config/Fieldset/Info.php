<?php

class Klaviyo_Reclaim_Block_Adminhtml_System_Config_Fieldset_Info extends Mage_Adminhtml_Block_Abstract
    implements Varien_Data_Form_Element_Renderer_Interface {

    protected $_template = 'klaviyoreclaim/system/config/fieldset/info.phtml';

    /**
     * Render fieldset html
     *
     * @param Varien_Data_Form_Element_Abstract $element
     * @return string
     */
    public function render(Varien_Data_Form_Element_Abstract $element) {
        return $this->toHtml();
    }

    public function getKlaviyoVersion() {
      return (string) Mage::getConfig()->getNode('modules/Klaviyo_Reclaim/version');
    }

    public function getKlaviyoExtensionStatus() {
      $helper = Mage::helper('klaviyo_reclaim');

      $is_enabled = $helper->isEnabled();
      $is_api_key_set = $helper->getPublicApiKey() != NULL;

      $adapter = Mage::getSingleton('core/resource')->getConnection('sales_read');
      $hour_ago = Zend_Date::now();
      $hour_ago->sub(60, Zend_Date::MINUTE);
      $hour_ago = $adapter->convertDateTime($hour_ago);

      $is_cron_running = Mage::getModel('cron/schedule')->getCollection()
        ->addFieldToFilter('status', Mage_Cron_Model_Schedule::STATUS_SUCCESS)
        ->addFieldToFilter('finished_at', array('gteq' => $hour_ago))
        ->count() > 0;

      $has_reclaim_entries = Mage::getModel('klaviyo_reclaim/checkout')->getCollection()->count() > 0;

      $is_extension_failing = $is_enabled && !($is_api_key_set || $is_cron_running || $has_reclaim_entries);
      
      return array($is_extension_failing, $is_api_key_set, $is_cron_running, $has_reclaim_entries);
    }
}

class Klaviyo_Reclaim_Block_Oauth_Credential_Renderer extends Mage_Adminhtml_Block_System_Config_Form_Field
{
    protected function _getElementHtml(Varien_Data_Form_Element_Abstract $element) {
        $element->setDisabled('disabled');

        return parent::_getElementHtml($element);
    }
}
