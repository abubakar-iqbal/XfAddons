<?php

namespace AddonFlare\PaidAds\Entity;

use XF\Mvc\Entity\Entity;
use XF\Mvc\Entity\Structure;

class AdClick extends Entity
{
    public static function getStructure(Structure $structure)
    {
        $structure->table = 'xf_af_paidads_ad_click';
        $structure->shortName = 'AddonFlare\PaidAds:AdClick';
        $structure->primaryKey = ['ad_id', 'date', 'user_id', 'ip'];
        $structure->columns = [
            'ad_id'   => ['type' => self::UINT, 'required' => true],
            'date'    => ['type' => self::UINT,  'default' => \XF::$time],
            'user_id' => ['type' => self::UINT, 'required' => true],
            'ip'      => ['type' => self::BINARY, 'maxLength' => 16, 'required' => true],
        ];

        $structure->getters = [

        ];

        $structure->relations = [

        ];

        return $structure;
    }
}