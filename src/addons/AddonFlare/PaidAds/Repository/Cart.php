<?php

namespace AddonFlare\PaidAds\Repository;

use XF\Mvc\Entity\Finder;
use XF\Mvc\Entity\Repository;
use XF\Mvc\Entity\AbstractCollection;

class Cart extends Repository
{
    public function findUserCart()
    {
        $visitor = \XF::visitor();

        if (!$visitor->user_id) return false;

        $cart = $this->em->findOne('AddonFlare\PaidAds:Cart', [
            'user_id'        => $visitor->user_id,
            'in_transaction' => 0,
            'is_paid'        => 0,
        ]);

        return $cart;
    }

    public function rebuildCart(\AddonFlare\PaidAds\Entity\Cart $cart)
    {
        $cartExpire = $this->options()->af_paidads_cartExpireMinutes * 60;

        $cartAds = $this->finder('AddonFlare\PaidAds:CartAd')
            ->with('Location', true)
            ->where('cart_id', $cart->cart_id)
            ->where('status', 'cart') // not necessary, but still adding it
            ->where('create_date', '>', \XF::$time - $cartExpire)
            ->order('cart_ad_id', 'ASC')
            ->fetch();

        $totalAmount = $totalItems = 0;

        foreach ($cartAds as $cart_ad_id => $cartAd)
        {
            $totalAmount += $cartAd->total_amount;
            $totalItems++;
        }

        $cart->bulkSet([
            'total_amount' => $totalAmount,
            'total_items'  => $totalItems,
        ]);

        $cart->save();

        $this->em->clearEntityCache('AddonFlare\PaidAds:Cart');

        return $cartAds;
    }
}