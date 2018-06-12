<?php namespace OFFLINE\Mall\Models;

use Model;
use October\Rain\Database\Traits\Encryptable;
use OFFLINE\Mall\Classes\Traits\HashIds;

/**
 * PaymentProfile Model
 */
class PaymentProfile extends Model
{
    use Encryptable;
    use HashIds;

    /**
     * @var string The database table used by the model.
     */
    public $table = 'offline_mall_payment_profiles';

    /**
     * @var array Guarded fields
     */
    protected $guarded = ['*'];

    /**
     * @var array Fillable fields
     */
    protected $fillable = [];

    /**
     * @var array List of attribute names which should be encrypted
     */
    protected $encryptable = [
        'profile_data'
    ];

    /**
     * @var array Relations
     */
    public $hasOne = [];
    public $hasMany = [];
    public $belongsTo = [
        'customer' => [Customer::class],
        'payment_method' => [PaymentMethod::class]
    ];
    public $belongsToMany = [];
    public $morphTo = [];
    public $morphOne = [];
    public $morphMany = [];
    public $attachOne = [];
    public $attachMany = [];

    public function beforeSave()
    {
        $this->card_last_four = substr($this->card_last_four, -4);
    }

    public function afterCreate()
    {
        if ($this->is_primary) {
            $this->makePrimary();
        }
    }

    /**
     * Sets the gateway specific profile information and 4 last digits of the credit card number (PAN)
     * and saves the profile to the database
     * @param array $profileData Profile data
     * @param string $cardDigits Last four digits of the CC number
     */
    public function setProfileData($profileData, $cardDigits)
    {
        $this->profile_data = $profileData;
        $this->card_last_four = $cardDigits;
    }

    /**
     * Sets the 4 last digits of the credit card number (PAN)
     * and saves the profile to the database
     * @param string $cardDigits Last four digits of the CC number
     */
    public function setCardNumber($cardDigits)
    {
        $this->card_last_four = $cardDigits;
    }

    /**
     * Makes this model the default
     * @return void
     */
    public function makePrimary()
    {
        $this
            ->newQuery()
            ->applyCustomer($this->customer_id)
            ->where('id', $this->id)
            ->update(['is_primary' => true])
        ;
        $this
            ->newQuery()
            ->applyCustomer($this->customer_id)
            ->where('id', '<>', $this->id)
            ->update(['is_primary' => false])
        ;
    }
    /**
     * Returns the default profile defined.
     * @return self
     */
    public static function getPrimary($customer)
    {
        $profiles = self::applyCustomer($customer)->get();
        foreach ($profiles as $profile) {
            if ($profile->is_primary) {
                return $profile;
            }
        }
        return $profiles->first();
    }

    /**
     * Checks weather a given customer has any payment profile defined.
     *
     * @param $customer Customer
     * @return bool
     */
    public static function customerHasProfile($customer)
    {
        return self::applyCustomer($customer)->count() > 0;
    }

    //
    // Scopes
    //
    public function scopeApplyCustomer($query, $customer)
    {
        if ($customer instanceof Customer) {
            $customer = $customer->getKey();
        }
        return $query->where('customer_id', $customer);
    }
}
