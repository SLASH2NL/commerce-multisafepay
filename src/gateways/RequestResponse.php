<?php

namespace craft\commerce\multisafepay\gateways;

use craft\commerce\omnipay\base\RequestResponse as BaseRequestResponse;
use Omnipay\MultiSafepay\Message\RestCompletePurchaseResponse;

class RequestResponse extends BaseRequestResponse {

    /**
     * @inheritdoc
     */
    public function isProcessing(): bool
    {
        if(! ($this->response instanceof RestCompletePurchaseResponse)) {
            return false;
        }

        return $this->response->isInitialized() || $this->response->isUncleared();;
    }
}