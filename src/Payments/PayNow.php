<?php
namespace Paynow\Payments;

use Paynow\Util\Hash;
use Paynow\Http\Client;
use Paynow\Core\Constants;
use Paynow\Http\RequestInfo;
use Paynow\Core\InitResponse;
use Paynow\Core\StatusResponse;


class Paynow
{
    /**
     * Merchant's return url
     * @var string
     */
    protected $returnUrl = "http://localhost";
    /**
     * Merchant's result url
     * @var string
     */
    protected $resultUrl = "http://localhost";
    /**
     * Client for making http requests
     * @var Client
     */
    private $client;
    /**
     * Merchant's integration id
     * @var string
     */
    private $integrationId = "";
    /**
     * Merchant's integration key
     * @var string
     */
    private $integrationKey = "";

    /**
     * The currently supported mobile money methods for express checkout
     * @var array
     */
    private $availableMobileMethods = [
        'ecocash'
    ];

    /**
     * Default constructor.
     *
     * @param Client $client Client for making http requests
     * @param string $id Merchant's integration id
     * @param string $key Merchant's integration key
     */
    public function __construct(Client $client, $id, $key)
    {
        $this->client = $client;

        $this->integrationId = $id;
        $this->integrationKey = $key;
    }

    /**
     * @param string|null $ref Transaction reference
     * @param string|array|null $item
     * @param string|null $amount
     *
     * @return FluentBuilder
     */
    public function createPayment( $ref = null, $item = null, $amount = null)
    {
        return new FluentBuilder($item, $ref, $amount);
    }

    /**
     * Send a transaction to Paynow
     *
     * @param FluentBuilder $builder
     *
     * @throws HashMismatchException
     * @throws \Paynow\Http\ConnectionException
     * @throws \Paynow\Payments\EmptyCartException
     * @throws \Paynow\Payments\EmptyTransactionReferenceException
     * @throws InvalidIntegrationException
     *
     * @return InitResponse
     */
    public function send(FluentBuilder $builder)
    {
        if (is_null($builder->ref)) {
            throw new EmptyTransactionReferenceException($builder);
        }

        if ($builder->count == 0) {
            throw new EmptyCartException($builder);
        }

        return $this->init($builder);
    }

    /**
     * Send a mobile transaction
     *
     * @param $phone
     * @param FluentBuilder $builder
     *
     * @return InitResponse
     *
     * @throws HashMismatchException
     * @throws NotImplementedException
     * @throws InvalidIntegrationException
     * @throws \Paynow\Http\ConnectionException
     */
    public function sendMobile(FluentBuilder $builder, $phone)
    {
        if (is_null($builder->ref)) {
            throw new EmptyTransactionReferenceException($builder);
        }

        $number = [];
        if (!preg_match('/07([7,8])((\1=7)[1-9]|[2-5])\d{6}/', $phone, $number)) {
            throw new \InvalidArgumentException("Invalid mobile number entered");
        }

        if ($builder->count == 0) {
            throw new EmptyCartException($builder);
        }

        return $this->initMobile($builder, $number[0]);
    }

    /**
     * Initiate a new Paynow transaction
     *
     * @param FluentBuilder $builder The transaction to be sent to Paynow
     *
     * @throws HashMismatchException
     * @throws \Paynow\Http\ConnectionException
     * @throws InvalidIntegrationException
     *
     * @return InitResponse The response from Paynow
     * @throws InvalidIntegrationException
     */
    protected function init(FluentBuilder $builder)
    {
        $request = $this->formatInit($builder);

        $response = $this->client->execute($request);

        if (arr_has($response, 'hash')) {
            if (!Hash::verify($response, $this->integrationKey)) {
                throw new HashMismatchException();
            }
        }

        return new InitResponse($response);
    }

    /**
     * Initiate a new Paynow transaction
     *
     * @param FluentBuilder $builder The transaction to be sent to Paynow
     * @param string $phone The user's phone number
     * @param string $method The mobile transaction method i.e ecocash, telecash
     *
     * @throws HashMismatchException
     * @throws NotImplementedException
     * @throws InvalidIntegrationException
     * @throws \Paynow\Http\ConnectionException
     *
     * @note Only ecocash is currently supported
     *
     * @return InitResponse The response from Paynow
     */
    protected function initMobile(FluentBuilder $builder, $phone, $method = 'ecocash')
    {
        if (!arr_contains($this->availableMobileMethods, 'ecocash')) {
            throw new NotImplementedException("The mobile money method {$method} is currently not supported for Paynow express checkout");
        }

        $request = $this->formatInitMobile($builder, $phone, $method);

        $response = $this->client->execute($request);

        if (arr_has($response, 'hash')) {
            if (!Hash::verify($response, $this->integrationKey)) {
                throw new HashMismatchException();
            }
        }

        return new InitResponse($response);
    }


    /**
     * Format a request before it's sent to Paynow
     *
     * @param FluentBuilder $builder The transaction to send to Paynow
     *
     * @return RequestInfo The formatted transaction
     */
    private function formatInit(FluentBuilder $builder)
    {
        $items = $builder->toArray();

        $items['resulturl'] = $this->resultUrl;
        $items['returnurl'] = $this->returnUrl;
        $items['id'] = $this->integrationId;


        foreach ($items as $key => $item) {
            $items[$key] = trim(utf8_encode($item));
        }

        $items['hash'] = Hash::make($items, $this->integrationKey);

        return RequestInfo::create(Constants::URL_INITIATE_TRANSACTION, 'POST', $items);
    }

    /**
     * Format a request before it's sent to Paynow
     *
     * @param FluentBuilder $builder The transaction to send to Paynow
     *
     * @param string $phone The mobile phone making the payment
     * @param string $method The mobile money method
     *
     * @return RequestInfo The formatted transaction
     */
    private function formatInitMobile(FluentBuilder $builder, $phone, $method)
    {
        $items = $builder->toArray();

        $items['resulturl'] = $this->resultUrl;
        $items['returnurl'] = $this->returnUrl;
        $items['id'] = $this->integrationId;
        $items['phone'] = $phone;
        $items['method'] = $method;

//        foreach ($items as $key => $item) {
//            $items[$key] = urlencode($item);
//        }

        $items['hash'] = Hash::make($items, $this->integrationKey);

        return RequestInfo::create(Constants::URL_INITIATE_MOBILE_TRANSACTION, 'POST', $items);
    }

    /**
     * Get the merchant's return url
     * @return string
     */
    public function getReturnUrl()
    {
        return $this->returnUrl;
    }

    /**
     * Sets the merchant's return url
     *
     * @param string $returnUrl
     */
    public function setReturnUrl($returnUrl)
    {
        $this->returnUrl = $returnUrl;
    }

    /**
     * Check the status of a transaction
     *
     * @param $url
     *
     * @throws \Paynow\Http\ConnectionException
     * @throws HashMismatchException
     *
     * @return StatusResponse
     */
    public function pollTransaction($url)
    {
        $response = $this->client->execute(RequestInfo::create(trim($url), 'METHOD', []));

        if (arr_has($response, 'hash')) {
            if (!Hash::verify($response, $this->integrationKey)) {
                throw new HashMismatchException();
            }
        }

        return new StatusResponse($response);
    }

    /**
     * Process a status update from Paynow
     *
     * @return StatusResponse
     * @throws HashMismatchException
     */
    public function processStatusUpdate()
    {
        $data = $_POST;

        if (arr_has($data, 'hash')) {
            if (!Hash::verify($data, $this->integrationKey)) {
                throw new HashMismatchException();
            }
        }

        return new StatusResponse($data);
    }

    /**
     * Get the result url for the merchant
     *
     * @return string
     */
    public function getResultUrl()
    {
        return $this->resultUrl;
    }

    /**
     * Sets the merchant's result url
     *
     * @param string $resultUrl
     */
    public function setResultUrl($resultUrl)
    {
        $this->resultUrl = $resultUrl;
    }
}