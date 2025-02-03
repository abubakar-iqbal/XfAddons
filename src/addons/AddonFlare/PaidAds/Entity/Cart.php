<?php

namespace AddonFlare\PaidAds\Entity;

use XF\Mvc\Entity\Entity;
use XF\Mvc\Entity\Structure;

class Cart extends Entity
{
    // purchable getters
    public function getPurchasableTypeId()
    {
        return 'af_paidads_cart';
    }
    public function getDescription()
    {
        return '';
    }
    public function getCurrency()
    {
        return $this->app()->options()->af_paidads_currency;
    }

    protected function _preSave()
    {
        $this->last_update = \XF::$time;
    }

    public static function getStructure(Structure $structure)
    {
        $structure->table = 'xf_af_paidads_cart';
        $structure->shortName = 'AddonFlare\PaidAds:Cart';
        $structure->primaryKey = 'cart_id';
        $structure->columns = [
            'cart_id'        => ['type' => self::UINT, 'autoIncrement' => true],
            'user_id'        => ['type' => self::UINT, 'required' => true],
            'total_amount'   => ['type' => self::FLOAT, 'default' => 0],
            'total_items'    => ['type' => self::UINT, 'default' => 0],
            'create_date'    => ['type' => self::UINT, 'default' => \XF::$time],
            'last_update'    => ['type' => self::UINT, 'default' => \XF::$time],
            'in_transaction' => ['type' => self::UINT, 'default' => 0],
            'is_paid'        => ['type' => self::BOOL, 'default' => false],
            'paid_data'      => ['type' => self::JSON_ARRAY, 'default' => []],
        ];

        $structure->getters = [
            'purchasable_type_id' => true,
            'description'         => true,
            'currency'            => true,
        ];

        $structure->relations = [
            'CartAds' => [
                'entity' => 'AddonFlare\PaidAds:CartAd',
                'type' => self::TO_MANY,
                'conditions' => 'cart_id',
                'key' => 'cart_ad_id'
            ],
        ];

        return $structure;
    }
}