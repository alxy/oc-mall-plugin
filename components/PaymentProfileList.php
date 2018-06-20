<?php namespace OFFLINE\Mall\Components;

use Auth;
use Flash;
use OFFLINE\Mall\Models\GeneralSettings;
use OFFLINE\Mall\Models\PaymentProfile;
use ValidationException;

class PaymentProfileList extends MallComponent
{
    public $paymentProfiles;
    public $paymentProfilePage;

    public function componentDetails()
    {
        return [
            'name'        => 'offline.mall::lang.components.paymentProfileList.details.name',
            'description' => 'offline.mall::lang.components.paymentProfileList.details.description'
        ];
    }

    public function defineProperties()
    {
        return [];
    }

    public function init()
    {
        if ($user = Auth::getUser()) {
            $this->paymentProfiles   = $user->customer->payment_profiles;
            $this->paymentProfilePage = GeneralSettings::get('payment_profile_page');
        }
    }

    public function onDelete()
    {
        $id       = $this->decode(post('id'));
        $customer = Auth::getUser()->customer;
        $profile  = PaymentProfile::find($id);

        if ( ! $profile) {
            throw new ValidationException(['id' => trans('offline.mall::lang.components.paymentProfileList.errors.profile_not_found')]);
        }

        if (PaymentProfile::applyCustomer($customer)->count() <= 1) {
            throw new ValidationException(['id' => trans('offline.mall::lang.components.paymentProfileList.errors.cannot_delete_last_profile')]);
        }

        $profile->delete();
        $this->paymentProfiles = Auth::getUser()->load('customer')->customer->payment_profiles;

        Flash::success(trans('offline.mall::lang.components.paymentProfileList.messages.profile_deleted'));

        return [
            '.mall-payment-profile-list__list' => $this->renderPartial($this->alias . '::list'),
        ];
    }

    public function onMakePrimary()
    {
        $id       = $this->decode(post('id'));
        $customer = Auth::getUser()->customer;
        $profile  = PaymentProfile::find($id);

        if ( ! $profile) {
            throw new ValidationException(['id' => trans('offline.mall::lang.components.paymentProfileList.errors.profile_not_found')]);
        }

        $profile->makePrimary();
        $this->paymentProfiles = Auth::getUser()->load('customer')->customer->payment_profiles;

        Flash::success(trans('offline.mall::lang.components.paymentProfileList.messages.profile_deleted'));

        return [
            '.mall-payment-profile-list__list' => $this->renderPartial($this->alias . '::list'),
        ];
    }
}
