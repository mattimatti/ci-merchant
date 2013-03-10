<?php

/*
 * CI-Merchant Library
 *
 * Copyright (c) 2011-2012 Adrian Macneil
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:

 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.

 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 */

require_once __DIR__.'/../vendor/autoload.php';

use Omnipay\Common\Exception\OmnipayException;
use Omnipay\Common\Message\ResponseInterface;
use Omnipay\Common\GatewayFactory;

/**
 * Merchant Class
 *
 * Payment processing for CodeIgniter
 */
class merchant
{
    protected $factory;
    protected $driver;
    protected $valid_drivers;

    public function __construct()
    {
        $this->factory = new GatewayFactory;
    }

    /**
     * Load the specified driver
     */
    public function load($driver)
    {
        try {
            $this->driver = $this->factory->create(str_replace('Merchant_', '', $driver));

            return true;
        } catch (OmnipayException $e) {
            return false;
        }
    }

    /**
     * Initialize driver settings
     */
    public function initialize($settings)
    {
        $this->driver->initialize($this->camel_keys($settings));
    }

    /**
     * Get driver settings
     *
     * @return array
     */
    public function settings()
    {
        return $this->driver->getParameters();
    }

    /**
     * Get driver default settings
     */
    public function default_settings()
    {
        $defaults = $this->driver->getDefaultParameters();

        // convert defaults to ci-merchant format
        foreach ($defaults as $key => $value) {
            if (is_array($value)) {
                $defaults[$key] = array(
                    'type' => 'select',
                    'default' => reset($value),
                    'options' => array(),
                );
                foreach ($value as $option) {
                    $defaults[$key]['options'][$option] = strtolower('merchant_'.$option);
                }
            }
        }

        return $defaults;
    }

    /**
     * Returns the name of the currently loaded driver
     */
    public function active_driver()
    {
        return $this->driver->getShortName();
    }

    public function valid_drivers()
    {
        if (null === $this->valid_drivers) {
            $this->valid_drivers = array_map('strtolower', $this->factory->find());
        }

        return $this->valid_drivers;
    }

    public function can_authorize()
    {
        return $this->driver->supportsAuthorize();
    }

    public function can_capture()
    {
        return $this->driver->supportsCapture();
    }

    public function can_refund()
    {
        return $this->driver->supportsRefund();
    }

    public function can_return()
    {
        return method_exists($this->driver, 'completePurchase');
    }

    public function authorize($params)
    {
        return $this->call('authorize', $params, Merchant_response::AUTHORIZED);
    }

    public function authorize_return($params)
    {
        return $this->call('completeAuthorize', $params, Merchant_response::AUTHORIZED);
    }

    public function capture($params)
    {
        return $this->call('capture', $params, Merchant_response::COMPLETE);
    }

    public function purchase($params)
    {
        return $this->call('purchase', $params, Merchant_response::COMPLETE);
    }

    public function purchase_return($params)
    {
        return $this->call('completePurchase', $params, Merchant_response::COMPLETE);
    }

    public function refund($params)
    {
        return $this->call('refund', $params, Merchant_response::REFUNDED);
    }

    protected function call($method, $params, $successStatus)
    {
        $params = $this->normalize_params($params);

        try {
            $response = $this->driver->$method($params)->send();

            return new Merchant_response($response, $successStatus);
        } catch (Exception $ex) {
            return new Merchant_failure_response($ex);
        }
    }

    protected function normalize_params($params)
    {
        $data = array('card' => $this->camel_keys($params));

        // map card parameters
        $card_params = array(
            'cardNo'   => 'number',
            'expMonth'  => 'expiryMonth',
            'expYear'   => 'expiryYear',
            'csc'       => 'cvv',
            'region'    => 'state',
        );

        foreach ($card_params as $old => $new) {
            if (isset($data['card'][$old])) {
                $data['card'][$new] = $data['card'][$old];
                unset($data['card'][$old]);
            }
        }

        // support both card_name and name parameters
        if (!isset($data['card']['name']) && isset($data['card']['cardName'])) {
            $data['card']['name'] = $data['card']['cardName'];
        }
        unset($data['card']['cardName']);

        // fix incorrect UK country code
        if (isset($data['card']['country']) AND strtoupper($data['card']['country']) == 'UK') {
            $data['card']['country'] = 'GB';
        }

        // map request parameters
        $request_params = array(
            'amount'        => 'amount',
            'currency'      => 'currency',
            'transactionId' => 'transactionId',
            'reference'     => 'transactionReference',
            'description'   => 'description',
            'returnUrl'     => 'returnUrl',
            'cancelUrl'     => 'cancelUrl',
            'token'         => 'cardReference',
        );

        foreach ($request_params as $old => $new) {
            if (isset($data['card'][$old])) {
                $data[$new] = $data['card'][$old];
                unset($data['card'][$old]);
            }
        }

        // specify amount in smallest unit
        if (isset($data['amount'])) {
            $data['amount'] = round($data['amount'] * 100);
        }

        return $data;
    }

    /**
     * Convert array keys to camelCase
     */
    protected function camel_keys($data)
    {
        $out = array();

        foreach ((array) $data as $key => $value) {
            $newKey = preg_replace_callback(
                '/_([a-z])/',
                function ($match) {
                    return strtoupper($match[1]);
                },
                $key
            );
            $out[$newKey] = $value;
        }

        return $out;
    }
}

class Merchant_response
{
    const AUTHORIZED = 'authorized';
    const COMPLETE = 'complete';
    const FAILED = 'failed';
    const REDIRECT = 'redirect';
    const REFUNDED = 'refunded';

    protected $response;
    protected $successStatus;

    public function __construct(ResponseInterface $response, $successStatus)
    {
        $this->response = $response;
        $this->successStatus = $successStatus;
    }

    /**
     * The response status.
     * One of self::AUTHORIZED, self::COMPLETE, self::FAILED, self::REDIRECT, or self::REFUNDED
     */
    public function status()
    {
        if ($this->response->isRedirect()) {
            return self::REDIRECT;
        } elseif ($this->response->isSuccessful()) {
            return $this->successStatus;
        }

        return self::FAILED;
    }

    /**
     * Whether the request was successful.
     */
    public function success()
    {
        return $this->status() !== self::FAILED;
    }

    /**
     * A plain text message returned by the payment gateway.
     */
    public function message()
    {
        return $this->response->getMessage();
    }

    /**
     * A transaction reference generated by the payment gateway.
     */
    public function reference()
    {
        return $this->response->getTransactionReference();
    }

    /**
     * The raw response data returned by the payment gateway.
     */
    public function data()
    {
        return $this->response->getData();
    }

    /**
     * Does this response require a redirect?
     */
    public function is_redirect()
    {
        return $this->response->isRedirect();
    }

    /**
     * If this response requires a redirect, the URL which must be redirected to.
     */
    public function redirect_url()
    {
        if ($this->response->isRedirect()) {
            return $this->response->getRedirectUrl();
        }
    }

    /**
     * The HTTP redirect method required (either "GET" or "POST").
     */
    public function redirect_method()
    {
        if ($this->response->isRedirect()) {
            return $this->response->getRedirectMethod();
        }
    }

    /**
     * If this response requires a POST redirect, the HTTP form data which must be submitted.
     */
    public function redirect_data()
    {
        if ($this->response->isRedirect()) {
            return $this->response->getRedirectData();
        }
    }

    /**
     * Perform the required redirect. If no redirect is required, returns FALSE.
     */
    public function redirect()
    {
        if ($this->response->isRedirect()) {
            $this->response->redirect();
        }

        return false;
    }
}

class Merchant_failure_response extends Merchant_response
{
    protected $exception;

    public function __construct(Exception $exception)
    {
        $this->exception = $exception;
    }

    public function success()
    {
        return false;
    }

    public function status()
    {
        return static::FAILED;
    }

    public function is_redirect()
    {
        return false;
    }

    public function message()
    {
        return $this->exception->getMessage();
    }

    public function data()
    {
        return $this->exception;
    }

    public function reference()
    {
        return null;
    }
}
