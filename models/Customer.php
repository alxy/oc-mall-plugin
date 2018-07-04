<?php namespace OFFLINE\Mall\Models;

use Model;
use October\Rain\Database\Traits\SoftDelete;
use October\Rain\Database\Traits\Validation;
use RainLab\User\Models\User;

class Customer extends Model
{
    use Validation;
    use SoftDelete;

    protected $dates = ['deleted_at'];
    protected $casts = [
        'is_guest' => 'boolean',
    ];
    public $rules = [
        'name'     => 'required',
        'is_guest' => 'boolean',
        'user_id'  => 'required|exists:users,id',
    ];
    public $table = 'offline_mall_customers';
    public $belongsTo = [
        'user' => User::class,
        'default_shipping_address' => [Address::class],
        'default_billing_address' => [Address::class],
    ];
    public $hasMany = [
        'addresses' => Address::class,
        'payment_profiles' => PaymentProfile::class
    ];
}
