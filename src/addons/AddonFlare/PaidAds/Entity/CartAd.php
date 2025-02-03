<?php

namespace AddonFlare\PaidAds\Entity;

use XF\Mvc\Entity\Entity;
use XF\Mvc\Entity\Structure;

class CartAd extends Entity
{
    protected function getTotalAmount()
    {
        return $this->total_amount_forum + $this->total_amount_non_forum;
    }
    protected function getTotalDays()
    {
        return $this->total_days_forum + $this->total_days_non_forum;
    }

    public function getPricesPerDay()
    {
        $location = $this->Location;
        $nodeId = $this->node_id;

        return $this->repository('AddonFlare\PaidAds:Location')->getPricesPerDay($location, $nodeId);
    }

    public function getExpireTime()
    {
        return $this->create_date + ($this->app()->options()->af_paidads_cartExpireMinutes * 60);
    }

    public function getSecondsRemaining()
    {
        $secondsLeft = $this->expire_time - \XF::$time;
        return $secondsLeft > 0 ? $secondsLeft : 0;
    }

    protected function _postDelete()
    {
        $this->db()->delete('xf_af_paidads_cart_ad_day', 'cart_ad_id = ?', $this->cart_ad_id);
        $this->em()->clearEntityCache('AddonFlare\PaidAds:CartAdDay');
    }

    public static function getStructure(Structure $structure)
    {
        $structure->table = 'xf_af_paidads_cart_ad';
        $structure->shortName = 'AddonFlare\PaidAds:CartAd';
        $structure->primaryKey = 'cart_ad_id';
        $structure->columns = [
            'cart_ad_id'             => ['type' => self::UINT,  'autoIncrement' => true],
            'location_id'            => ['type' => self::UINT,  'required' => true],
            'user_id'                => ['type' => self::UINT,  'required' => true],
            'node_id'                => ['type' => self::UINT,  'default' => 0],
            'cart_id'                => ['type' => self::UINT,  'default' => 0],
            'status'                 => ['type' => self::STR,   'required' => true, 'maxLength' => 25],
            'create_date'            => ['type' => self::UINT,  'default' => \XF::$time],
            'total_days_forum'       => ['type' => self::UINT,  'default' => 0],
            'total_days_non_forum'   => ['type' => self::UINT,  'default' => 0],
            'total_amount_forum'     => ['type' => self::FLOAT, 'default' => 0],
            'total_amount_non_forum' => ['type' => self::FLOAT, 'default' => 0],
            'type'                   => ['type' => self::STR,   'required' => true, 'maxLength' => 25],
            'existing_ad_id'         => ['type' => self::UINT,  'nullable' => true, 'default' => null],
        ];

        $structure->getters = [
            'total_amount'      => true,
            'total_days'        => true,
            'prices_per_day'    => false,
            'expire_time'       => true,
            'seconds_remaining' => true,
        ];

        $structure->relations = [
            'Location' => [
                'entity' => 'AddonFlare\PaidAds:Location',
                'type' => self::TO_ONE,
                'conditions' => 'location_id',
                'primary' => true
            ],
            'ExistingAd' => [
                'entity' => 'AddonFlare\PaidAds:Ad',
                'type' => self::TO_ONE,
                'conditions' => [['ad_id', '=', '$existing_ad_id']],
                'primary' => true
            ],
            'Cart' => [
                'entity' => 'AddonFlare\PaidAds:Cart',
                'type' => self::TO_ONE,
                'conditions' => 'cart_id',
                'primary' => true
            ],
            'CartAdDays' => [
                'entity' => 'AddonFlare\PaidAds:CartAdDay',
                'type' => self::TO_MANY,
                'conditions' => 'cart_ad_id',
                'primary' => true
                // 'order' => ''
            ],
            'Forum' => [
                'entity' => 'XF:Forum',
                'type' => self::TO_ONE,
                'conditions' => 'node_id',
                'primary' => true,
                'with' => 'Node'
            ],
        ];

        return $structure;
    }
}