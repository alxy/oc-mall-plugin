<?php namespace OFFLINE\Mall\Components;

class PaymentProfileForm extends MallComponent
{
    public function componentDetails()
    {
        return [
            'name'        => 'offline.mall::lang.components.paymentProfileForm.details.name',
            'description' => 'offline.mall::lang.components.paymentProfileForm.details.description'
        ];
    }

    public function defineProperties()
    {
        return [
            'redirect' => [
                'type'  => 'dropdown',
                'title' => 'offline.mall::lang.components.paymentProfileForm.properties.redirect.title',
            ]
        ];
    }

    public function getRedirectOptions()
    {
        return [
            'checkout' => trans('offline.mall::lang.components.paymentProfileForm.redirects.checkout'),
            'account'  => trans('offline.mall::lang.components.paymentProfileForm.redirects.account'),
        ];
    }

    public function init()
    {
        $this->addComponent(PaymentMethodSelector::class, 'paymentMethodSelector', ['saveProfile' => true, 'redirect' => 'account']);
    }
}
