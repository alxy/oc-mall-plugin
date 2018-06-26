<?php

namespace OFFLINE\Mall\Classes\Payments;

use Event;
use OFFLINE\Mall\Models\Order;

class PaymentService
{
    public $gateway;
    public $order;
    public $pageFilename;
    protected $redirector;

    public function __construct(PaymentGateway $gateway, Order $order, $pageFilename)
    {
        $this->gateway      = $gateway;
        $this->order        = $order;
        $this->pageFilename = $pageFilename;
        $this->redirector   = new PaymentRedirector($pageFilename);
    }

    public function process()
    {
        session()->put('mall.processing_order.id', $this->order->hashId);

        try {
            $result = $this->gateway->process($this->order);

            Event::fire('mall.payment.completed', [$result]);
        } catch (\Throwable $e) {
            $result             = new PaymentResult();
            $result->successful = false;
            $result->message    = $e->getMessage();

            Event::fire('mall.payment.failed', [$result]);
        }

        session()->forget('mall.payment_method.data');

        return $this->redirector->handlePaymentResult($result);
    }
}
