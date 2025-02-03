<?php

namespace AddonFlare\PaidAds\Repository;

use XF\Mvc\Entity\Finder;
use XF\Mvc\Entity\Repository;
use XF\Mvc\Entity\AbstractCollection;

class CartAd extends Repository
{
    public function rebuildCartAd(\AddonFlare\PaidAds\Entity\CartAd $cartAd)
    {
        $dateTime = new \DateTime('@' . \XF::$time, new \DateTimeZone('UTC'));
        $dateToday = $dateTime->format('Y-m-d');

        $cartAdDays = $this->finder('AddonFlare\PaidAds:CartAdDay')
            ->where('cart_ad_id', $cartAd->cart_ad_id)
            ->where('date', '>=', $dateToday)
            ->fetch();

        $pricesPerDay = $cartAd->getPricesPerDay();

        $totalDaysForum = $totalDaysNonForum = $totalAmountForum = $totalAmountNonForum = 0;

        foreach ($cartAdDays as $cartAdDayId => $cartAdDay)
        {
            if ($cartAdDay->type == 'forum')
            {
                $totalAmountForum += $pricesPerDay['forum'];
                $totalDaysForum++;
            }
            else if ($cartAdDay->type == 'non_forum')
            {
                $totalAmountNonForum += $pricesPerDay['non_forum'];
                $totalDaysNonForum++;
            }
        }

        $cartAd->bulkSet([
            'total_days_forum'       => $totalDaysForum,
            'total_days_non_forum'   => $totalDaysNonForum,
            'total_amount_forum'     => $totalAmountForum,
            'total_amount_non_forum' => $totalAmountNonForum,
        ]);

        $cartAd->save();

        $this->em->clearEntityCache('AddonFlare\PaidAds:CartAd');
    }
}