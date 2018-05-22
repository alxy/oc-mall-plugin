<?php

namespace OFFLINE\Mall\Classes\Payments;

use ApplicationException;
use October\Rain\Exception\ValidationException;
use OFFLINE\Mall\Classes\PaymentState\FailedState;
use OFFLINE\Mall\Classes\PaymentState\PaidState;
use OFFLINE\Mall\Models\Customer;
use OFFLINE\Mall\Models\PaymentGatewaySettings;
use OFFLINE\Mall\Models\PaymentProfile;
use Omnipay\Common\CreditCard;
use Omnipay\Omnipay;
use Throwable;
use Validator;

class Stripe extends PaymentProvider
{
    public function name(): string
    {
        return 'Stripe';
    }

    public function identifier(): string
    {
        return 'stripe';
    }

    public function validate(): bool
    {
        $rules = [
            'number'      => 'required|digits:16',
            'expiryMonth' => 'required|integer|min:1|max:12',
            'expiryYear'  => 'required|integer|min:' . date('Y'),
            'cvv'         => 'required|digits:3',
        ];

        $validation = Validator::make($this->data, $rules);
        if ($validation->fails()) {
            throw new ValidationException($validation);
        }

        return true;
    }

    public function process(): PaymentResult
    {
        $gateway = $this->initializeGateway();

        $result = new PaymentResult();

        $response = null;
        try {
            $response = $gateway->purchase([
                'amount'    => $this->order->total_in_currency,
                'currency'  => $this->order->currency['code'],
                'card'      => $this->data,
                'returnUrl' => $this->returnUrl(),
                'cancelUrl' => $this->cancelUrl(),
            ])->send();
        } catch (Throwable $e) {
            $result->successful    = false;
            $result->failedPayment = $this->logFailedPayment([], $e);

            return $result;
        }

        $data               = (array)$response->getData();
        $result->successful = $response->isSuccessful();

        if ($result->successful) {
            $payment                               = $this->logSuccessfulPayment($data, $response);
            $this->order->payment_id               = $payment->id;
            $this->order->payment_data             = $data;
            $this->order->card_type                = $data['source']['brand'];
            $this->order->card_holder_name         = $data['source']['name'];
            $this->order->credit_card_last4_digits = $data['source']['last4'];
            $this->order->payment_state            = PaidState::class;
            $this->order->save();
        } else {
            $result->failedPayment      = $this->logFailedPayment($data, $response);
            $this->order->payment_state = FailedState::class;
            $this->order->save();
        }

        return $result;
    }

    //
    // Payment Profiles
    //
    /**
     * {@inheritDoc}
     */
    public function supportsPaymentProfiles()
    {
        return true;
    }

    /**
     * Creates a user profile on the payment gateway. If the profile already exists the method should update it.
     *
     * @param Customer $customer
     * @param array $data This should contain the following fields: 'first_name', 'last_name', 'expiry_date_month', 'expiry_date_year', 'card_number', 'CVV'
     * @return PaymentProfile
     * @throws ApplicationException
     * @throws ValidationException
     */
    public function updatePaymentProfile(Customer $customer, $data)
    {
        $gateway = $this->initializeGateway();

        $validation = $this->makeValidationObject($data);

        if ($validation->fails()) {
            throw new ValidationException($validation);
        }

        $formData = $this->makeCardData($data);
        $profile = $customer->payment_profile;
        $profileData = (array) $profile ? $profile->profile_data : [];

        //
        // Customer
        //
        $newCustomerRequired = !$profile || !isset($profile->profile_data['customer_id']);

        if (!$newCustomerRequired) {
            $customerId = $profile->profile_data['customer_id'];
            $response = $gateway->fetchCustomer(['customerReference' => $customerId])->send();
            $responseData = $response->getData();

            if ($response->isSuccessful()) {
                if (isset($responseData['deleted'])) {
                    $newCustomerRequired = true;
                }
            }
            else {
                $newCustomerRequired = true;
            }
        }

        if ($newCustomerRequired) {
            $response = $gateway->createCustomer([
                'description'  => $customer->name,
                'email'        => $customer->user->email,
            ])->send();

            if ($response->isSuccessful()) {
                $customerId = $response->getCustomerReference();
                $profileData['customer_id'] = $customerId;
            }
            else {
                throw new ApplicationException('Gateway createCustomer failed');
            }
        }

        //
        // Card
        //
        $newCardRequired = !$profile || !isset($profile->profile_data['card_id']);
        $newCard = new CreditCard($formData);

        if (!$newCardRequired) {
            $cardId = $profile->profile_data['card_id'];

            $response = $gateway->updateCard([
                'card'              => $newCard,
                'cardReference'     => $cardId,
                'customerReference' => $customerId,
            ])->send();
            $responseData = $response->getData();

            if (!$response->isSuccessful()) {
                $newCardRequired = true;
            }
        }

        if ($newCardRequired) {
            $response = $gateway->createCard([
                'card'              => $newCard,
                'customerReference' => $customerId,
            ])->send();

            if ($response->isSuccessful()) {
                $cardId = $response->getCardReference();
                $profileData['card_id'] = $customerId;
            }
            else {
                throw new ApplicationException('Gateway createCard failed');
            }
        }

        if (!$profile) {
            $profile = new PaymentProfile();
            $profile->customer = $customer;
            $profile->vendor_id = $this->identifier();
            $profile->is_primary = true;
            $profile->card_brand = $newCard->getBrand();

        }
        $profile->setProfileData([
            'card_id'     => $cardId,
            'customer_id' => $customerId,
        ], array_get($formData, 'number'));

        $profile->save();

        return $profile;
    }

    /**
     * Deletes a user profile from the payment gateway.
     *
     * @param PaymentProfile $profile
     * @throws ApplicationException
     * @return void
     */
    public function deletePaymentProfile(PaymentProfile $profile)
    {
        if (!isset($profile->profile_data['customer_id'])) {
            return;
        }

        $gateway = $this->initializeGateway();

        $customerId = $profile->profile_data['customer_id'];

        $response = $gateway->deleteCustomer([
            'customerReference' => $customerId
        ])->send();

        if (!$response->isSuccessful()) {
            throw new ApplicationException('Gateway deleteCustomer failed');
        }
    }

    /**
     * Pays/Processes an order from an existing payment profile.
     *
     * @param PaymentProfile $profile
     * @return PaymentResult
     * @throws ApplicationException
     */
    public function payFromProfile(PaymentProfile $profile)
    {
        $gateway = $this->initializeGateway();

        if (
            !$profile ||
            !isset($profile->profile_data['card_id']) ||
            !isset($profile->profile_data['customer_id'])
        ) {
            throw new ApplicationException('Payment profile not found');
        }

        $cardId = $profile->profile_data['card_id'];
        $customerId = $profile->profile_data['customer_id'];
        $profileData = [
            'cardReference'     => $cardId,
            'customerReference' => $customerId,
        ];

        $result = new PaymentResult();

        $response = null;
        try {
            $response = $gateway->purchase($profileData + [
                'amount'    => $this->order->total_in_currency,
                'currency'  => $this->order->currency['code'],
                'card'      => $this->data,
                'returnUrl' => $this->returnUrl(),
                'cancelUrl' => $this->cancelUrl(),
            ])->send();
        } catch (Throwable $e) {
            $result->successful    = false;
            $result->failedPayment = $this->logFailedPayment([], $e);

            return $result;
        }

        $data               = (array)$response->getData();
        $result->successful = $response->isSuccessful();

        if ($result->successful) {
            $payment                               = $this->logSuccessfulPayment($data, $response);
            $this->order->payment_id               = $payment->id;
            $this->order->payment_data             = $data;
            $this->order->card_type                = $data['source']['brand'];
            $this->order->card_holder_name         = $data['source']['name'];
            $this->order->credit_card_last4_digits = $data['source']['last4'];
            $this->order->payment_state            = PaidState::class;
            $this->order->save();
        } else {
            $result->failedPayment      = $this->logFailedPayment($data, $response);
            $this->order->payment_state = FailedState::class;
            $this->order->save();
        }

        return $result;
    }

    /**
     * Validates the card data
     *
     * @param $data array Card data
     * @return \Illuminate\Validation\Validator
     */
    protected function makeValidationObject($data)
    {
        $rules = [
            'first_name'              => 'required',
            'last_name'               => 'required',
            'expiry_date_month'       => ['required', 'regex:/^[0-9]*$/'],
            'expiry_date_year'        => ['required', 'regex:/^[0-9]*$/'],
            'card_number'             => ['required', 'regex:/^[0-9]*$/'],
            'CVV'                     => ['required', 'regex:/^[0-9]*$/'],
        ];

        return Validator::make($data, $rules);
    }

    /**
     * Extracts the relevant information from passed array
     *
     * @param $data array Form data
     * @return array
     */
    protected function makeCardData($data)
    {
        return [
            'firstName'   => array_get($data, 'first_name'),
            'lastName'    => array_get($data, 'last_name'),
            'number'      => array_get($data, 'card_number'),
            'expiryMonth' => array_get($data, 'expiry_date_month'),
            'expiryYear'  => array_get($data, 'expiry_date_year'),
            'cvv'         => array_get($data, 'CVV'),
        ];
    }

    /**
     * Initializes the gateway
     *
     * @return \Omnipay\Common\GatewayInterface
     */
    protected function initializeGateway()
    {
        $gateway = Omnipay::create('Stripe');
        $gateway->setApiKey(decrypt(PaymentGatewaySettings::get('stripe_api_key')));

        return $gateway;
    }
}
