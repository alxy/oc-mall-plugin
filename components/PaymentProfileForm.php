<?php namespace OFFLINE\Mall\Components;

use Auth;
use Cms\Classes\ComponentBase;

class PaymentProfileForm extends MallComponent
{
    public function componentDetails()
    {
        return [
            'name'        => 'PaymentProfileForm Component',
            'description' => 'No description provided yet...'
        ];
    }

    public function defineProperties()
    {
        return [
            'redirect' => [
                'type'  => 'dropdown',
                'title' => 'offline.mall::lang.components.addressForm.properties.redirect.title',
            ],
            'set'      => [
                'type'  => 'dropdown',
                'title' => 'offline.mall::lang.components.addressForm.properties.set.title',
            ],
        ];
    }

    public function getRedirectOptions()
    {
        return [
            'checkout' => trans('offline.mall::lang.components.addressForm.redirects.checkout'),
            'account'  => trans('offline.mall::lang.components.addressForm.redirects.account'),
        ];
    }

    public function init()
    {
        $this->addComponent(PaymentMethodSelector::class, 'paymentMethodSelector', ['saveProfile' => true, 'redirect' => 'account']);
    }

    public function onRun()
    {
//        if ( ! $this->setData()) {
//            return $this->controller->run('404');
//        }
    }
}
