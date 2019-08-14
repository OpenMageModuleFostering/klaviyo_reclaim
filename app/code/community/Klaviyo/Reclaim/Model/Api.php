<?php
/**
 * @category    Klaviyo
 * @package     Klaviyo_Reclaim
 * @copyright   Copyright (c) 2013 Klaviyo Inc. (http://www.klaviyo.com)
 */


/**
 * Reclaim Api
 *
 * @category   Klaviyo
 * @package    Klaviyo_Reclaim
 * @author     Klaviyo Team <support@klaviyo.com>
 */
class Klaviyo_Reclaim_Model_Api
{

    /**
     * Api instance
     *
     * @var Reclaim_Klaviyo_KlaviyoApi
     * @access protected
     */
    protected $_api = null;

    /**
     * Error status code
     *
     * @var integer
     * @access public
     */
    public $error_code = null;

    /**
     * API error message
     *
     * @var string
     * @access public
     */
    public $error_message = null;

    /**
     * Initialize API
     *
     * @param array $args
     * @return void
     */
    public function __construct($args) {
        $store_name = Mage::app()->getRequest()->getParam('store');

        if (strlen($store_name)){
            $store_id = Mage::getModel('core/store')->load($store_name)->getId();
            $private_api_key = Mage::getStoreConfig('reclaim/general/private_api_key', $store_id);
        }

        else{
            $private_api_key = (!isset($args['api_key']) ? Mage::helper('klaviyo_reclaim')->getPrivateApiKey() : $args['api_key']);
        }

        $this->_api = new Klaviyo_Reclaim_Model_KlaviyoApi($private_api_key);
    }

    /**
     * Magic __call method
     *
     * @link http://www.php.net/manual/en/language.oop5.overloading.php#language.oop5.overloading.methods
     * @param string $method
     * @param array $args
     * @return mixed
     */
    public function __call($method, $args=null) {
        $this->error_code = null;
        $this->error_message = null;

        return $this->call($method, $args);
    }

    /**
     * Perform API call, also can be used "directly"
     *
     * @param string $method Method to call
     * @param array $args OPTIONAL parameters to pass
     * @return mixed
     */
    public function call($method, $args) {
        try {
            $this->_logApiRequest($this->_api->api_key);
            $this->_logApiRequest($method);
            $this->_logApiRequest($args);

            if ($args) {
                $result = call_user_func_array(array($this->_api, $method), $args);
            } else {
                $result = $this->_api->{$method}();
            }

            $this->_logApiRequest($result);

            if ($this->_api->error_message) {
                $this->error_code = $this->_api->error_code;
                $this->error_message = $this->_api->error_message;

                $this->_logApiRequest('Error: ' . $this->_api->error_message . ', Code: ' . $this->_api->error_code);

                return (string) $this->_api->error_message;
            }

            return $result;

        } catch (Exception $ex) {
            Mage::logException($ex);
            return $ex->getMessage();
        }

        return FALSE;
    }

    /**
     * Log API calls.
     *
     * @param mixed $data
     * @return void
     */
    protected function _logApiRequest($data) {
        Mage::log($data, Zend_Log::INFO, Mage::helper('klaviyo_reclaim')->getLogFile());
    }

}