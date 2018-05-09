<?php

namespace OFFLINE\Mall\NotifyRules\Events;

class CustomerSignedUpEvent extends \RainLab\Notify\Classes\EventBase
{
    public function eventDetails()
    {
        return [
            'name'        => 'Signed up',
            'description' => 'A customer has signed up',
            'group'       => 'mall',
        ];
    }

    public function defineParams()
    {
        return [
            'user' => [
                'title' => 'User',
                'label' => 'The newly registered user account',
            ],
        ];
    }

    public static function makeParamsFromEvent(array $args, $eventName = null)
    {
        return [
            'user' => array_get($args, 1),
        ];
    }
}