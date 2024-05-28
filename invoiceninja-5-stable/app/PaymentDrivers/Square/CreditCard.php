<?php

/**
 * Invoice Ninja (https://invoiceninja.com).
 *
 * @link https://github.com/invoiceninja/invoiceninja source repository
 *
 * @copyright Copyright (c) 2023. Invoice Ninja LLC (https://invoiceninja.com)
 *
 * @license https://www.elastic.co/licensing/elastic-license
 */

namespace App\PaymentDrivers\Square;

use App\Exceptions\PaymentFailed;
use App\Http\Requests\ClientPortal\Payments\PaymentResponseRequest;
use App\Jobs\Util\SystemLogger;
use App\Models\ClientGatewayToken;
use App\Models\GatewayType;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentType;
use App\Models\SystemLog;
use App\PaymentDrivers\Common\MethodInterface;
use App\PaymentDrivers\SquarePaymentDriver;
use App\Utils\Traits\MakesHash;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Square\Http\ApiResponse;

class CreditCard implements MethodInterface
{
    use MakesHash;

    public function __construct(public SquarePaymentDriver $square_driver)
    {
        $this->square_driver->init();
    }

    /**
     * Authorization page for credit card.
     *
     * @param array $data
     * @return \Illuminate\View\View
     */
    public function authorizeView($data): View
    {
        $data['gateway'] = $this->square_driver;

        return render('gateways.square.credit_card.authorize', $data);
    }

    /**
     * Handle authorization for credit card.
     *
     * @param Request $request
     * @return RedirectResponse
     */
    public function authorizeResponse($request): RedirectResponse
    {
        return redirect()->route('client.payment_methods.index');
    }

    public function paymentView($data)
    {
        $data['gateway'] = $this->square_driver;
        $data['amount'] = $this->square_driver->payment_hash->data->amount_with_fee;
        $data['currencyCode'] = $this->square_driver->client->getCurrencyCode();
        $data['square_contact'] = $this->buildClientObject();

        return render('gateways.square.credit_card.pay', $data);
    }

    private function buildClientObject()
    {
        $client = new \stdClass();

        $country = $this->square_driver->client->country ? $this->square_driver->client->country->iso_3166_2 : $this->square_driver->client->company->country()->iso_3166_2;

        $client->addressLines = [$this->square_driver->client->address1 ?: '', $this->square_driver->client->address2 ?: ''];
        $client->givenName = $this->square_driver->client->present()->first_name();
        $client->familyName = $this->square_driver->client->present()->last_name();
        $client->email = $this->square_driver->client->present()->email();
        $client->phone = $this->square_driver->client->phone;
        $client->city = $this->square_driver->client->city;
        $client->region = $this->square_driver->client->state;
        $client->country = $country;

        return (array) $client;
    }

    public function paymentResponse(PaymentResponseRequest $request)
    {
        $token = $request->sourceId;

        $amount = $this->square_driver->convertAmount(
            $this->square_driver->payment_hash->data->amount_with_fee
        );

        if ($request->shouldUseToken()) {
            $cgt = ClientGatewayToken::query()->where('token', $request->token)->first();
            $token = $cgt->token;
        }

        $invoice = Invoice::query()->whereIn('id', $this->transformKeys(array_column($this->square_driver->payment_hash->invoices(), 'invoice_id')))->withTrashed()->first();

        if ($invoice) {
            $description = "Invoice {$invoice->number} for {$amount} for client {$this->square_driver->client->present()->name()}";
        } else {
            $description = "Payment with no invoice for amount {$amount} for client {$this->square_driver->client->present()->name()}";
        }

        $amount_money = new \Square\Models\Money();
        $amount_money->setAmount($amount);
        $amount_money->setCurrency($this->square_driver->client->currency()->code);

        $body = new \Square\Models\CreatePaymentRequest($token, $request->idempotencyKey);
        $body->setAmountMoney($amount_money);
        $body->setAutocomplete(true);
        $body->setLocationId($this->square_driver->company_gateway->getConfigField('locationId'));
        $body->setReferenceId($this->square_driver->payment_hash->hash);
        $body->setNote($description);

        if ($request->shouldUseToken()) {
            $body->setCustomerId($cgt->gateway_customer_reference);
        } elseif ($request->has('verificationToken') && $request->input('verificationToken')) {
            $body->setVerificationToken($request->input('verificationToken'));
        }

        $response = $this->square_driver->square->getPaymentsApi()->createPayment($body);

        if ($response->isSuccess()) {

            $body = json_decode($response->getBody());

            if($request->store_card) {
                $this->createCard($body->payment->id);
            }

            return $this->processSuccessfulPayment($response);
        }

        if(is_array($response)) {
            nlog("square");
            nlog($response);
        }

        return $this->processUnsuccessfulPayment($response);
    }

    private function processSuccessfulPayment(ApiResponse $response)
    {
        $body = json_decode($response->getBody());

        $amount = array_sum(array_column($this->square_driver->payment_hash->invoices(), 'amount')) + $this->square_driver->payment_hash->fee_total;

        $payment_record = [];
        $payment_record['amount'] = $amount;
        $payment_record['payment_type'] = PaymentType::CREDIT_CARD_OTHER;
        $payment_record['gateway_type_id'] = GatewayType::CREDIT_CARD;
        $payment_record['transaction_reference'] = $body->payment->id;

        $payment = $this->square_driver->createPayment($payment_record, Payment::STATUS_COMPLETED);

        $message = [
            'server_response' => $body,
            'data' => $this->square_driver->payment_hash->data,
        ];

        SystemLogger::dispatch(
            $message,
            SystemLog::CATEGORY_GATEWAY_RESPONSE,
            SystemLog::EVENT_GATEWAY_SUCCESS,
            SystemLog::TYPE_SQUARE,
            $this->square_driver->client,
            $this->square_driver->client->company,
        );

        return redirect()->route('client.payments.show', ['payment' => $this->encodePrimaryKey($payment->id)]);
    }

    private function processUnsuccessfulPayment(ApiResponse $response)
    {
        $body = \json_decode($response->getBody());

        $data = [
            'response' => $response,
            'error' => $body->errors[0]->detail,
            'error_code' => '',
        ];

        return $this->square_driver->processUnsuccessfulTransaction($data);
    }

    private function createCard($source_id)
    {

        $square_card = new \Square\Models\Card();
        $square_card->setCustomerId($this->findOrCreateClient());

        $body = new \Square\Models\CreateCardRequest(uniqid("st", true), $source_id, $square_card);

        $api_response = $this->square_driver
                             ->init()
                             ->square
                             ->getCardsApi()
                             ->createCard($body);

        $body = json_decode($api_response->getBody());

        if ($api_response->isSuccess()) {

            try {
                $payment_meta = new \stdClass();
                $payment_meta->exp_month = (string) $body->card->exp_month;
                $payment_meta->exp_year = (string) $body->card->exp_year;
                $payment_meta->brand = (string) $body->card->card_brand;
                $payment_meta->last4 = (string) $body->card->last_4;
                $payment_meta->type = GatewayType::CREDIT_CARD;

                $data = [
                    'payment_meta' => $payment_meta,
                    'token' => $body->card->id,
                    'payment_method_id' => GatewayType::CREDIT_CARD,
                ];

                $this->square_driver->storeGatewayToken($data, ['gateway_customer_reference' => $body->card->customer_id]);

            } catch (\Exception $e) {
                return $this->square_driver->processInternallyFailedPayment($this->square_driver, $e);
            }

        } else {
            throw new PaymentFailed($body->errors[0]->detail, 500);
        }

        return false;
    }

    private function findOrCreateClient()
    {
        $email_address = new \Square\Models\CustomerTextFilter();
        $email_address->setExact($this->square_driver->client->present()->email());

        $filter = new \Square\Models\CustomerFilter();
        $filter->setEmailAddress($email_address);

        $query = new \Square\Models\CustomerQuery();
        $query->setFilter($filter);

        $body = new \Square\Models\SearchCustomersRequest();
        $body->setQuery($query);

        $api_response = $this->square_driver
                             ->init()
                             ->square
                             ->getCustomersApi()
                             ->searchCustomers($body);

        $customers = false;

        if ($api_response->isSuccess()) {
            $customers = $api_response->getBody();
            $customers = json_decode($customers);

            if (count([$api_response->getBody(), 1]) == 0) {
                $customers = false;
            }
        } else {
            $errors = $api_response->getErrors();
        }

        if (property_exists($customers, 'customers')) {
            return $customers->customers[0]->id;
        }

        return $this->createClient();
    }

    private function createClient()
    {
        $country = $this->square_driver->client->country ? $this->square_driver->client->country->iso_3166_2 : $this->square_driver->client->company->country()->iso_3166_2;

        /* Step two - create the customer */
        $billing_address = new \Square\Models\Address();
        $billing_address->setAddressLine1($this->square_driver->client->address1);
        $billing_address->setAddressLine2($this->square_driver->client->address2);
        $billing_address->setLocality($this->square_driver->client->city);
        $billing_address->setAdministrativeDistrictLevel1($this->square_driver->client->state);
        $billing_address->setPostalCode($this->square_driver->client->postal_code);
        $billing_address->setCountry($country);

        $body = new \Square\Models\CreateCustomerRequest();
        $body->setGivenName($this->square_driver->client->present()->name());
        $body->setFamilyName('');
        $body->setEmailAddress($this->square_driver->client->present()->email());
        $body->setAddress($billing_address);
        // $body->setPhoneNumber($this->square_driver->client->phone);
        $body->setReferenceId($this->square_driver->client->number);
        $body->setNote('Created by Invoice Ninja.');

        $api_response = $this->square_driver
                             ->init()
                             ->square
                             ->getCustomersApi()
                             ->createCustomer($body);

        if ($api_response->isSuccess()) {
            $result = $api_response->getResult();

            return $result->getCustomer()->getId();
        } else {
            $errors = $api_response->getErrors();
            nlog($errors);
            return $this->processUnsuccessfulPayment($api_response);
        }
    }
}
