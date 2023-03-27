<?php

namespace Visma;

class VismaPay
{
    protected $api_key;
    protected $private_key;
    protected $connector;
    protected $version;
    protected $customer = [];
    protected $products = [];
    protected $payment_method = [];
    protected $charge = [];

    const API_URL = 'https://vismapay.local/pbwapi';

    public function __construct($api_key, $private_key, $version = 'w3.1', VismaPayConnector $connector = null)
    {
        $this->api_key = $api_key;
        $this->private_key = $private_key;
        $this->connector = $connector ? $connector : new VismaPayCurl();
        $this->version = $version;
        $this->charge = null;
    }

    protected function makeRequest($url, $params)
    {
        $params['version'] = isset($params['version']) ? $params['version'] : $this->version;
        $params['api_key'] = $this->api_key;

        $result = $this->connector->request($url, $params);

        if ($json = json_decode($result)) {
            if (isset($json->result)) {
                return $json;
            }
        }

        throw new VismaPayException('VismaPay::makeRequest - response from Visma Pay API is not valid JSON', 2);
    }

    protected function makeChargeRequest($url)
    {
        $authcode_string = $this->api_key . '|' . $this->charge['order_number'];

        if (isset($this->charge['card_token'])) {
            $authcode_string .= '|' . $this->charge['card_token'];
        }

        $payment_data = $this->charge;
        $payment_data['authcode'] = $this->calcAuthcode($authcode_string);

        if (!empty($this->payment_method)) {
            $payment_data['payment_method'] = $this->payment_method;
        }

        if (!empty($this->customer)) {
            $payment_data['customer'] = $this->customer;
        }

        if (!empty($this->products)) {
            $payment_data['products'] = $this->products;
        }

        $payment_data['plugin_info'] = 'Prestashop8|';

        if (defined('_PS_VERSION_')) {
            $payment_data['plugin_info'] .= _PS_VERSION_;
        } else {
            $payment_data['plugin_info'] .= '0';
        }

        $payment_data['plugin_info'] .= '|8.0.0';

        return $this->makeRequest($url, $payment_data);
    }

    protected function calcAuthcode($input)
    {
        return strtoupper(hash_hmac('sha256', $input, $this->private_key));
    }

    public function addCharge(array $fields)
    {
        $this->charge = $fields;
    }

    public function addCustomer(array $fields)
    {
        $this->customer = $fields;
    }

    public function addProduct(array $fields)
    {
        array_push($this->products, $fields);
    }

    public function addPaymentMethod(array $fields)
    {
        $this->payment_method = $fields;
    }

    public function createCharge()
    {
        return $this->makeChargeRequest('auth_payment');
    }

    public function chargeWithCardToken()
    {
        return $this->makeChargeRequest('charge_card_token');
    }

    public function checkStatusWithToken($token)
    {
        return $this->makeRequest('check_payment_status', [
            'token' => $token,
            'authcode' => $this->calcAuthcode($this->api_key . '|' . $token),
        ]);
    }

    public function checkStatusWithOrderNumber($order_number)
    {
        return $this->makeRequest('check_payment_status', [
            'order_number' => $order_number,
            'authcode' => $this->calcAuthcode($this->api_key . '|' . $order_number),
        ]);
    }

    public function settlePayment($order_number)
    {
        return $this->makeRequest('capture', [
            'order_number' => $order_number,
            'authcode' => $this->calcAuthcode($this->api_key . '|' . $order_number),
        ]);
    }

    public function cancelPayment($order_number)
    {
        return $this->makeRequest('cancel', [
            'order_number' => $order_number,
            'authcode' => $this->calcAuthcode($this->api_key . '|' . $order_number),
        ]);
    }

    public function getCardToken($card_token)
    {
        return $this->makeRequest('get_card_token', [
            'authcode' => $this->calcAuthcode($this->api_key . '|' . $card_token),
            'card_token' => $card_token,
        ]);
    }

    public function deleteCardToken($card_token)
    {
        return $this->makeRequest('delete_card_token', [
            'authcode' => $this->calcAuthcode($this->api_key . '|' . $card_token),
            'card_token' => $card_token,
        ]);
    }

    public function getMerchantPaymentMethods($currency = '')
    {
        return $this->makeRequest('merchant_payment_methods', [
            'authcode' => $this->calcAuthcode($this->api_key),
            'version' => '2',
            'currency' => $currency,
        ]);
    }

    public function checkReturn($return_data)
    {
        if (array_key_exists('RETURN_CODE', $return_data) && array_key_exists('ORDER_NUMBER', $return_data) && array_key_exists('AUTHCODE', $return_data)) {
            $mac_input = $return_data['RETURN_CODE'] . '|' . $return_data['ORDER_NUMBER'];

            if (array_key_exists('SETTLED', $return_data)) {
                $mac_input .= '|' . $return_data['SETTLED'];
            }

            if (array_key_exists('CONTACT_ID', $return_data)) {
                $mac_input .= '|' . $return_data['CONTACT_ID'];
            }

            if (array_key_exists('INCIDENT_ID', $return_data)) {
                $mac_input .= '|' . $return_data['INCIDENT_ID'];
            }

            if ($return_data['AUTHCODE'] == $this->calcAuthcode($mac_input)) {
                return (object) $return_data;
            }

            throw new VismaPayException('VismaPay::checkReturn - MAC authentication failed', 4);
        }

        throw new VismaPayException('VismaPay::checkReturn - unable to calculate MAC, not enough data given', 5);
    }
}
