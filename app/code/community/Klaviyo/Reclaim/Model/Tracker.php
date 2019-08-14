<?php
/**
 * @category    Klaviyo
 * @package     Klaviyo_Reclaim
 * @copyright   Copyright (c) 2013 Klaviyo Inc. (http://www.klaviyo.com)
 */


/**
 * Reclaim Tracker
 *
 * @category   Klaviyo
 * @package    Klaviyo_Reclaim
 * @author     Klaviyo Team <support@klaviyo.com>
 */

class Klaviyo_Reclaim_Model_Tracker {
    public $api_key;
    public $host = 'https://a.klaviyo.com/';

    protected $TRACK_ONCE_KEY = '__track_once__';
    
    public function __construct($api_key) {
        $this->api_key = $api_key;
    }
    
    function track($event, $customer_properties=array(), $properties=array(), $timestamp=NULL) {
        if ((!array_key_exists('$email', $customer_properties) || empty($customer_properties['$email']))
            && (!array_key_exists('$id', $customer_properties) || empty($customer_properties['$id']))) {
            
            throw new Exception('You must identify a user by email or ID.');
        }

        $params = array(
            'token' => $this->api_key,
            'service' => 'magento',
            'event' => $event,
            'properties' => $properties,
            'customer_properties' => $customer_properties
        );

        if (!is_null($timestamp)) {
            $params['time'] = $timestamp;
        }

        $encoded_params = $this->build_params($params);
        return $this->make_request('api/track', $encoded_params);
    }

    function track_once($event, $customer_properties=array(), $properties=array(), $timestamp=NULL) {
        $properties[$TRACK_ONCE_KEY] = true;
        return $this->track($event, $customer_properties, $properties, $timestamp);
    }

    function identify($properties) {
        if ((!array_key_exists('$email', $properties) || empty($properties['$email']))
            && (!array_key_exists('$id', $properties) || empty($properties['$id']))) {
            
            throw new Exception('You must identify a user by email or ID.');
        }

        $params = array(
            'token' => $this->api_key,
            'properties' => $properties
        );

        $encoded_params = $this->build_params($params);
        return $this->make_request('api/identify', $encoded_params);
    }

    protected function build_params($params) {
        return 'data=' . urlencode(base64_encode(json_encode($params)));
    }

    protected function make_request($path, $params) {
        $url = $this->host . $path . '?' . $params;
        
        $client = new Varien_Http_Client($url);
        $client->setMethod(Varien_Http_Client::GET);

        $response = $client->request();
        return $response->isSuccessful() && $response->getBody() == '1';
    }
};

?>