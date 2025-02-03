<?php

namespace AddonFlare\PaidAds\Entity;

use XF\Mvc\Entity\Entity;
use XF\Mvc\Entity\Structure;

class CartAdDay extends Entity
{
    // converts the date from UTC to the user's timezone
    public function getDateLocal()
    {
        $dateTime = new \DateTime($this->date, new \DateTimeZone('UTC'));
        $dateTime->setTime(0, 0, 0);
        $dateTime->setTimezone($this->app()->language()->getTimeZone());

        return $dateTime->format('Y-m-d');
    }

    public static function getStructure(Structure $structure)
    {
        $structure->table = 'xf_af_paidads_cart_ad_day';
        $structure->shortName = 'AddonFlare\PaidAds:CartAdDay';
        $structure->primaryKey = ['cart_ad_id', 'date', 'type'];
        $structure->columns = [
            'cart_ad_id'     => ['type' => self::UINT, 'required' => true],
            'date'           => ['type' => self::STR, 'required' => true],
            'type'           => ['type' => self::STR,  'required' => true, 'maxLength' => 25],
        ];

        $structure->getters = [
            'date_local' => true
        ];

        $structure->relations = [
            'CartAd' => [
                'entity' => 'AddonFlare\PaidAds:CartAd',
                'type' => self::TO_ONE,
                'conditions' => 'cart_ad_id',
                'primary' => true
            ],
        ];

        return $structure;
    }
}