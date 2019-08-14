<?php
/* Rule REST API
*
* @category    Klaviyo
* @package     Klaviyo_Reclaim
* @author      Klaviyo Team <support@klaviyo.com>
*/
class Klaviyo_Reclaim_Model_Api2_Rule_Rest_Admin_V1 extends Klaviyo_Reclaim_Model_Api2_Rule
{
    /**
     * Retrieve list of coupon codes.
     *
     * @return array
     */
    protected function _retrieveCollection()
    {
        $data = array();

        $rules = Mage::getResourceModel('salesrule/rule_collection')->load();
        foreach ($rules as $rule) {
            array_push($data, $rule->getData());
        }

        return $data;
    }
}
