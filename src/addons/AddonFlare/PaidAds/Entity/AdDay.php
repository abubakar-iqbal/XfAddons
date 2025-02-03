<?php

namespace AddonFlare\PaidAds\Entity;

use XF\Mvc\Entity\Entity;
use XF\Mvc\Entity\Structure;

class AdDay extends Entity
{
    // converts the date from UTC to the user's timezone, not used but can in the future
    public function getDateLocal()
    {
        $dateTime = new \DateTime($this->date, new \DateTimeZone('UTC'));
        $dateTime->setTime(0, 0, 0);
        $dateTime->setTimezone($this->app()->language()->getTimeZone());

        return $dateTime->format('Y-m-d');
    }

    public static function getStructure(Structure $structure)
    {
        $structure->table = 'xf_af_paidads_ad_day';
        $structure->shortName = 'AddonFlare\PaidAds:AdDay';
        $structure->primaryKey = ['ad_id', 'date', 'type'];
        $structure->columns = [
            'ad_id'     => ['type' => self::UINT, 'required' => true],
            'date'      => ['type' => self::STR, 'required' => true],
            'type'      => ['type' => self::STR,  'required' => true, 'maxLength' => 25],
        ];

        $structure->getters = [
            'date_local' => true
        ];

        $structure->relations = [
            'Ad' => [
                'entity' => 'AddonFlare\PaidAds:Ad',
                'type' => self::TO_ONE,
                'conditions' => 'ad_id',
                'primary' => true
            ],
        ];

        return $structure;
    }
}