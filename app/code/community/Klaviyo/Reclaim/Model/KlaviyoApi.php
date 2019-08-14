<?php
/**
 * @category    Klaviyo
 * @package     Klaviyo_Reclaim
 * @copyright   Copyright (c) 2013 Klaviyo Inc. (http://www.klaviyo.com)
 */


/**
 * Klaviyo Api
 *
 * @category   Klaviyo
 * @package    Klaviyo_Reclaim
 * @author     Klaviyo Team <support@klaviyo.com>
 */
class Klaviyo_Reclaim_Model_KlaviyoApi
{
    var $api_version = '1';
    var $api_url;

    var $error_message;
    var $error_code;

    /**
     * Default to a 300 second timeout on server calls
     */
    var $timeout = 300;

    /**
     * Default to a 8K chunk size
     */
    var $chunk_size = 8192;

    /**
     * Cache the user api_key so we only have to log in once per client instantiation
     */
    var $api_key;

    /**
     * @var array Request params storage
     */
    public $request_params = array();

    /**
     * Connect to the Klaviyo API for a given list.
     *
     * @param string $apikey Your Klaviyo apikey
     * @param string $secure Whether or not this should use a secure connection
     */
    function __construct($api_key, $secure=true) {
        $this->api_key = $api_key;
        $this->secure = $secure;
        $this->api_base_url = 'https://a.klaviyo.com/api/v' . $this->api_version . '/';
    }

    function setTimeout($seconds) {
        if (is_int($seconds)) {
            $this->timeout = $seconds;
            return true;
        }
    }

    function getTimeout() {
        return $this->timeout;
    }

    function lists($page=0, $count=50) {
        $params = array();
        $params['type'] = 'list';
        $params['page'] = $page;
        $params['count'] = $count;
        return $this->callServer('GET', 'lists', $params);
    }

    function listDetail($list_id) {
        $params = array();
        return $this->callServer('GET', 'list/' . $list_id, $params);
    }

    function listSubscriberAdd($list_id, $email, $confirm_optin=true) {
        $params = array();
        $params['email'] = $email;
        $params['confirm_optin'] = $confirm_optin ? 'true' : 'false';
        return $this->callServer('POST', 'list/' . $list_id . '/members', $params);
    }

    function listSubscriberDelete($list_id, $email) {
        $params = array();
        $params['email'] = $email;
        return $this->callServer('POST', 'list/' . $list_id . '/members/delete', $params);
    }

    function callServer($method, $path, $params) {

        $this->request_params = $params;

        $params['api_key'] = $this->api_key;

        $this->errorMessage = '';
        $this->errorCode = '';

        $client = new Varien_Http_Client($this->api_base_url . $path);
        $client->setMethod($method);

        if ($method == 'GET') {
            $client->setParameterGet($params);
        } else if ($method == 'POST') {
            $client->setParameterPost($params);
        } else {
            $client->setRawData(http_build_query($params));
        }

        try {
            $response = $client->request();
            if (!$response->isSuccessful()) {
                $this->error_message = $response->getBody();
                $this->error_code = $response->getStatus();
                return false;
            }
        } catch (Exception $ex) {
            Mage::logException($ex);
            return $ex->getMessage();
        }

        $json_response = Zend_Json::decode($response->getBody());

        if(is_array($json_response) && isset($json_response['errors'])) {
            $this->error_message = $json_response['errors'];
            $this->error_code = $response->getStatus();
            return false;

        }

        return $json_response;
    }

}