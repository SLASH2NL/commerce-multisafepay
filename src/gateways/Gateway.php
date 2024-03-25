<?php

namespace craft\commerce\multisafepay\gateways;

use Craft;
use craft\commerce\base\RequestResponseInterface;
use craft\commerce\models\payments\BasePaymentForm;
use craft\commerce\models\Transaction;
use craft\commerce\multisafepay\gateways\RequestResponse as GatewaysRequestResponse;
use craft\commerce\omnipay\base\OffsiteGateway;
use craft\commerce\omnipay\events\GatewayRequestEvent;
use craft\helpers\App;
use craft\helpers\Json;
use Omnipay\Common\AbstractGateway;
use Omnipay\MultiSafepay\Message\RestRefundRequest;
use Omnipay\MultiSafepay\RestGateway as OmnipayGateway;
use yii\base\NotSupportedException;
use craft\commerce\Plugin as Commerce;
use craft\commerce\records\Transaction as TransactionRecord;
use craft\web\Response;
use Omnipay\Common\Message\RequestInterface;

/**
 * Gateway represents MultiSafePay gateway
 *
 * @author    Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since     1.0
 *
 * @property bool $testMode
 * @property string $apiKey
 * @property string $locale
 * @property-read null|string $settingsHtml
 */
class Gateway extends OffsiteGateway
{
    /**
     * @var string|null
     */
    private ?string $_apiKey = null;

    /**
     * @var bool|string
     */
    private bool|string $_testMode = false;

    /**
     * @var string|null
     */
    private ?string $_locale = null;

    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return Craft::t('commerce', 'MultiSafepay REST');
    }

    /**
     * @inheritdoc
     */
    public function getSettings(): array
    {
        $settings = parent::getSettings();
        $settings['apiKey'] = $this->getApiKey(false);
        $settings['testMode'] = $this->getTestMode(false);
        $settings['locale'] = $this->getLocale(false);

        return $settings;
    }

    /**
     * @param bool $parse
     * @return bool|string
     * @since 4.0.0
     */
    public function getTestMode(bool $parse = true): bool|string
    {
        return $parse ? App::parseBooleanEnv($this->_testMode) : $this->_testMode;
    }

    /**
     * @param bool|string $testMode
     * @return void
     * @since 4.0.0
     */
    public function setTestMode(bool|string $testMode): void
    {
        $this->_testMode = $testMode;
    }

    /**
     * @param bool $parse
     * @return string|null
     * @since 4.0.0
     */
    public function getApiKey(bool $parse = true): ?string
    {
        return $parse ? App::parseEnv($this->_apiKey) : $this->_apiKey;
    }

    /**
     * @param string|null $apiKey
     * @return void
     * @since 4.0.0
     */
    public function setApiKey(?string $apiKey): void
    {
        $this->_apiKey = $apiKey;
    }

    /**
     * @param bool $parse
     * @return string|null
     * @since 4.0.0
     */
    public function getLocale(bool $parse = true): ?string
    {
        return $parse ? App::parseEnv($this->_locale) : $this->_locale;
    }

    /**
     * @param string|null $locale
     * @return void
     * @since 4.0.0
     */
    public function setLocale(?string $locale): void
    {
        $this->_locale = $locale;
    }

    /**
     * @inheritdoc
     */
    public function getPaymentTypeOptions(): array
    {
        return [
            'purchase' => Craft::t('commerce', 'Purchase (Authorize and Capture Immediately)'),
        ];
    }

    /**
     * @inheritdoc
     */
    public function supportsWebhooks(): bool
    {
        return true;
    }

    /**
     * @return Response
     * @throws \Throwable
     * @throws CurrencyException
     * @throws OrderStatusException
     * @throws TransactionException
     * @throws ElementNotFoundException
     * @throws Exception
     * @throws InvalidConfigException
     */
    public function processWebHook(): Response
    {
        $response = Craft::$app->getResponse();

        $transactionHash = $this->getTransactionHashFromWebhook();
        $transaction = Commerce::getInstance()->getTransactions()->getTransactionByHash($transactionHash);

        if (!$transaction) {
            Craft::warning('Transaction with the hash “' . $transactionHash . '“ not found.', 'commerce');
            $response->data = 'ok';

            return $response;
        }

        // Check to see if a successful purchase child transaction already exist and skip out early if they do
        $successfulPurchaseChildTransaction = TransactionRecord::find()->where([
            'parentId' => $transaction->id,
            'status' => TransactionRecord::STATUS_SUCCESS,
            'type' => TransactionRecord::TYPE_PURCHASE,
        ])->count();

        if ($successfulPurchaseChildTransaction) {
            Craft::warning('Successful child transaction for “' . $transactionHash . '“ already exists.', 'commerce');
            $response->data = 'ok';

            return $response;
        }

        $id = Craft::$app->getRequest()->getQueryParam('transactionid');

        $gateway = $this->createGateway();
        /** @var FetchTransactionRequest $request */
        $request = $gateway->fetchTransaction(['transactionId' => $id]);

        $res = $request->send();

        if (!$res->isSuccessful()) {
            Craft::warning('MSP request was unsuccessful.', 'commerce');
            $response->data = 'ok';

            return $response;
        }

        $childTransaction = Commerce::getInstance()->getTransactions()->createTransaction(null, $transaction);
        $childTransaction->type = $transaction->type;

        $status = $res->getPaymentStatus();

        if ($status === 'completed') {
            $childTransaction->status = TransactionRecord::STATUS_SUCCESS;
        } elseif ($status === 'initialized' || $status === 'uncleared') {
            $childTransaction->status = TransactionRecord::STATUS_PROCESSING;
        } elseif ($res->isExpired() || $res->isDeclined() || $res->isCancelled()) {
            $childTransaction->status = TransactionRecord::STATUS_FAILED;
        } else {
            $response->data = 'ok';
            return $response;
        }

        $childTransaction->response = $res->getData();
        $childTransaction->code = $res->getTransactionId();
        $childTransaction->reference = $res->getTransactionReference();
        $childTransaction->message = $res->getMessage();
        Commerce::getInstance()->getTransactions()->saveTransaction($childTransaction);

        $response->data = 'ok';

        return $response;
    }

    /**
     * @inheritdoc
     */
    public function completePurchase(Transaction $transaction): RequestResponseInterface
    {
        if (!$this->supportsCompletePurchase()) {
            throw new NotSupportedException(Craft::t('commerce', 'Completing purchase is not supported by this gateway'));
        }

        $request = $this->createRequest($transaction);
        $completeRequest = $this->prepareCompletePurchaseRequest($request);

        return $this->performRequestTest($completeRequest, $transaction);
    }

    /**
     * Perform a request and return the response.
     *
     * @param RequestInterface $request
     * @param Transaction $transaction
     *
     * @return RequestResponseInterface
     */
    protected function performRequestTest(RequestInterface $request, Transaction $transaction): RequestResponseInterface
    {
        //raising event
        $event = new GatewayRequestEvent([
            'type' => $transaction->type,
            'request' => $request,
            'transaction' => $transaction,
        ]);

        // Raise 'beforeGatewayRequestSend' event
        $this->trigger(self::EVENT_BEFORE_GATEWAY_REQUEST_SEND, $event);

        $response = $this->sendRequest($request);
        file_put_contents('test.log', print_r($response, true));

        return new GatewaysRequestResponse($response, $transaction);
    }

    /**
     * @inheritdoc
     */
    public function getTransactionHashFromWebhook(): ?string
    {
        return Craft::$app->getRequest()->getQueryParam('commerceTransactionHash');
    }

    /**
     * @inheritdoc
     */
    public function getSettingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('commerce-multisafepay/gatewaySettings', ['gateway' => $this]);
    }

    /**
     * @inheritdoc
     */
    public function populateRequest(array &$request, BasePaymentForm $form = null): void
    {
        parent::populateRequest($request, $form);
        $request['type'] = 'redirect';
    }

    /**
     * @inheritdoc
     */
    public function rules(): array
    {
        $rules = parent::rules();
        $rules[] = ['paymentType', 'compare', 'compareValue' => 'purchase'];

        return $rules;
    }

    /**
     * @inheritdoc
     */
    protected function createGateway(): AbstractGateway
    {
        /** @var OmnipayGateway $gateway */
        $gateway = static::createOmnipayGateway($this->getGatewayClassName());

        $gateway->setApiKey($this->getApiKey());
        $gateway->setLocale($this->getLocale());
        $gateway->setTestMode($this->getTestMode());

        return $gateway;
    }

    /**
     * @inheritdoc
     */
    protected function getGatewayClassName(): ?string
    {
        return '\\' . OmnipayGateway::class;
    }

    /**
     * @inheritdoc
     */
    public function refund(Transaction $transaction): RequestResponseInterface
    {
        $request = $this->createRequest($transaction);
        $refundRequest = $this->prepareRefundRequest($request, $transaction->reference);

        // Get the order ID for the successful transaction and use that.
        $responseData = Json::decodeIfJson($transaction->getParent()->response);

        if ($responseData && isset($responseData['data']['order_id'])) {
            $reference = $responseData['data']['order_id'];
        } else {
            throw new NotSupportedException('Cannot refund this transaction as the parent Order cannot be found!');
        }

        /** @var RestRefundRequest $refundRequest */
        $refundRequest->setTransactionId($reference);

        return $this->performRequest($refundRequest, $transaction);
    }
}
