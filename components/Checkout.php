<?php namespace OFFLINE\Mall\Components;

use Auth;
use Cms\Classes\ComponentBase;
use DB;
use Illuminate\Contracts\Encryption\DecryptException;
use October\Rain\Exception\ValidationException;
use OFFLINE\Mall\Classes\Payments\PaymentGateway;
use OFFLINE\Mall\Classes\Payments\PaymentResult;
use OFFLINE\Mall\Classes\Traits\SetVars;
use OFFLINE\Mall\Components\Cart as CartComponent;
use OFFLINE\Mall\Models\Cart;
use OFFLINE\Mall\Models\Order;
use OFFLINE\Mall\Models\PaymentMethod;
use Redirect;
use Request;
use Session;

class Checkout extends ComponentBase
{
    use SetVars;

    public $cart;
    public $payment_method;
    public $step;

    public function componentDetails()
    {
        return [
            'name'        => 'offline.mall::lang.components.checkout.details.name',
            'description' => 'offline.mall::lang.components.checkout.details.description',
        ];
    }

    public function defineProperties()
    {
        return [
            'step' => [
                'type' => 'dropdown',
                'name' => 'offline.mall::lang.components.checkout.properties.step.name',
            ],
        ];
    }

    public function getStepOptions()
    {
        return [
            'payment'  => trans('offline.mall::lang.components.checkout.steps.payment'),
            'shipping' => trans('offline.mall::lang.components.checkout.steps.shipping'),
            'confirm'  => trans('offline.mall::lang.components.checkout.steps.confirm'),
        ];
    }

    public function init()
    {
        $this->addComponent(CartComponent::class, 'cart', ['showDiscountApplier' => false]);
        $this->addComponent(AddressSelector::class, 'billingAddressSelector', ['type' => 'billing']);
        $this->addComponent(AddressSelector::class, 'shippingAddressSelector', ['type' => 'shipping']);
        $this->addComponent(ShippingSelector::class, 'shippingSelector', []);
        $this->addComponent(PaymentMethodSelector::class, 'paymentMethodSelector', []);
        $this->setData();
    }

    public function onRun()
    {
        // An off-site payment has been completed
        if ($type = Request::input('return')) {
            return $this->handleOffSiteReturn($type);
        }

        // If no step is provided or the step is invalid redirect the user to
        // the payment method selection screen.
        $step = $this->property('step');
        if ( ! $step || ! array_key_exists($step, $this->getStepOptions())) {
            $url = $this->stepUrl('payment');

            return redirect()->to($url);
        }
    }

    public function onCheckout()
    {
        $this->setData();

        if ($this->cart->shipping_method_id === null || $this->cart->payment_method_id === null) {
            throw new ValidationException([trans('offline.mall::lang.components.checkout.errors.missing_settings')]);
        }

        try {
            $paymentData = json_decode(decrypt(session()->get('mall.payment_method.data')), true);
        } catch (DecryptException $e) {
            $paymentData = [];
        }

        $gateway = app(PaymentGateway::class);
        $gateway->init($this->cart, $paymentData);

        $order = DB::transaction(function () {
            return Order::fromCart($this->cart);
        });

        try {
            $result = $gateway->process($order);
        } catch (\Throwable $e) {
            $result             = new PaymentResult();
            $result->successful = false;
        }

        session()->forget('mall.payment_method.data');

        return $this->handlePaymentResult($result);
    }

    protected function setData()
    {
        $cart = Cart::byUser(Auth::getUser());
        if ( ! $cart->payment_method_id) {
            $cart->setPaymentMethod(PaymentMethod::getDefault());
        }
        $this->setVar('cart', $cart);
        $this->setVar('payment_method', PaymentMethod::findOrFail($cart->payment_method_id));
        $this->setVar('step', $this->property('step'));
    }

    public function stepUrl($step)
    {
        return $this->controller->pageUrl(
            $this->page->page->fileName,
            ['step' => $step]
        );
    }

    protected function handlePaymentResult($result)
    {
        if ($result->redirect) {
            return $result->redirectUrl ? Redirect::to($result->redirectUrl) : $result->redirectResponse;
        }

        if ($result->successful) {
            return Redirect::to($this->getSuccessfulUrl());
        }

        return Redirect::to($this->getFailedUrl());
    }

    protected function handleOffSiteReturn($type)
    {
        // Someone tampered with the url or the session has expired.
        $paymentId = Session::pull('oc-mall.payment.id');
        if ($paymentId !== Request::input('oc-mall_payment_id')) {
            Session::forget('oc-mall.payment.callback');

            return Redirect::to($this->getFailedUrl());
        }

        // The user has cancelled the payment
        if ($type === 'cancel') {
            Session::forget('oc-mall.payment.callback');

            return Redirect::to($this->getCancelledUrl());
        }

        // If a callback is set we need to do an additional step to
        // complete this payment.
        $callback = Session::pull('oc-mall.payment.callback');
        if ($callback) {
            $paymentMethod = new $callback;

            if ( ! method_exists($paymentMethod, 'complete')) {
                throw new \LogicException('Payment gateways that redirect off-site need to have a "complete" method!');
            }

            return $this->handlePaymentResult($paymentMethod->complete());
        }

        // The payment was successful
        return Redirect::to($this->getSuccessfulUrl());
    }

    private function getFailedUrl()
    {
        return '/failed';
    }

    private function getCancelledUrl()
    {
        return '/cancelled';
    }

    private function getSuccessfulUrl()
    {
        return '/done';
    }
}
