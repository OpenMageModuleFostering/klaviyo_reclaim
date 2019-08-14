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
}