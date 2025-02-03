<?php

namespace AddonFlare\PaidAds\Repository;

use XF\Mvc\Entity\Finder;
use XF\Mvc\Entity\Repository;
use XF\Mvc\Entity\AbstractCollection;

use AddonFlare\PaidAds\Calendar;

class Location extends Repository
{
    public function findLocationsForList($activeOnly = false)
    {
        $finder = $this->finder('AddonFlare\PaidAds:Location')->order('display_order');

        if ($activeOnly)
        {
            $finder->where('active', 1);
        }

        return $finder;
    }

    public function filterPurchasable(AbstractCollection $locations)
    {
        if (!$locations->count())
        {
            return $locations;
        }

        return $locations->filter(function($entity)
        {
            return $entity->canPurchase();
        });
    }

    public function findUserCartAd($cartAdId, $checkExpiration = true)
    {
        if (!$cartAdId) return false;

        $visitor = \XF::visitor();

        $cartExpire = $this->options()->af_paidads_cartExpireMinutes * 60;

        $cartAd = $this->finder('AddonFlare\PaidAds:CartAd')
            ->where([
                'cart_ad_id'  => $cartAdId,
                'user_id'     => $visitor->user_id,
                ['create_date', '>', ($checkExpiration ? (\XF::$time - $cartExpire) : 0)],
                ['status', '!=', 'transaction']
            ])
            ->with('Location', true)
            ->with('Cart')
            ->with('Forum')
            ->fetchOne();

        return $cartAd;
    }

    public function fetchDailyCache()
    {
        $dateTime = new \DateTime('@' . \XF::$time, new \DateTimeZone('UTC'));
        $dateToday = $dateTime->format('Y-m-d');

        $this->em->clearEntityCache('AddonFlare\PaidAds:AdDay');
        $adDays = $this->finder('AddonFlare\PaidAds:AdDay')
            ->with('Ad', true)
            ->with('Ad.Location', true)
            ->where('date', $dateToday)
            ->where('Ad.status', 'active')
            ->where('Ad.Location.active', 1)
            ->order('Ad.Location.display_order', 'ASC')
            ->order('Ad.ad_id', 'ASC')
            ->fetch();

        $locationFinder = $this->findLocationsForList(true);
        $locations = [];

        foreach ($locationFinder->fetch() as $locationId => $location)
        {
            $adOptions = $location->ad_options;
            $adOptions['placeholder_banner_url'] = $location->placeholder_banner_url;

            $locations[$locationId] = [
                'location_id'       => $locationId,
                'position_id'       => $location->position_id,
                'ad_options'        => $adOptions,
                'display_criteria'  => $location->display_criteria,
                'misc_options'      => $location->misc_options,
            ];
        }

        $ads = $positions = [];

        foreach ($adDays as $adDay)
        {
            $ad = $adDay->Ad;
            $location = $ad->Location;
            $positionId = $location->position_id;
            $locationId = $location->location_id;
            $adId = $ad->ad_id;
            $dayType = $adDay->type;
            $nodeId = $ad->node_id;

            $ads[$adId] = [
                'ad_id'       => $adId,
                'location_id' => $ad->location_id,
                'position_id' => $positionId,
                'url'         => $ad->url,
                'banner_url'  => $ad->banner_url,
            ];

            if ($dayType == 'forum')
            {
                $positions[$positionId]['forum'][$locationId][$nodeId][$adId] = $adId;
            }
            else if ($dayType == 'non_forum')
            {
                $positions[$positionId]['non_forum'][$locationId][$adId] = $adId;
            }
        }

        $cache = [
            'date'      => $dateToday,
            'locations' => $locations,
            'ads'       => $ads,
            'positions' => $positions,
        ];

        return $cache;
    }

    public function rebuildDailyCache()
    {
        $cache = $this->fetchDailyCache();

        \XF::registry()->set('afpaidadsDaily', $cache);

        return $cache;
    }

    public function rebuildDailyCacheOnce()
    {
        \XF::runOnce('af_paidads_dailycache', function()
        {
            $this->rebuildDailyCache();
        });
    }

    // retrieves or rebuilds Daily cache
    public function getDailyCache($useCache = true)
    {
        if (!$useCache)
        {
            return $this->rebuildDailyCache();
        }
        $ads = $this->em->create('AddonFlare\PaidAds:Location')->location_id;

        return $this->app()->container('afpaidads.daily');
    }

    public function getAdDaysForCalendar(\AddonFlare\PaidAds\Entity\Location $location, $cartAdId, $existingAdId, $year, $month, $day = 'all', $nodeId = 0, $dateRange = ['start' => null, 'end' => null])
    {
        $localTimeZone = \XF::language()->getTimeZone();

        if (!empty($dateRange['start']) && !empty($dateRange['end']))
        {
            // used by admin procedures (updating status to approved)
            $startDate = $dateRange['start'];
            $endDate = $dateRange['end'];
        }
        else
        {
            // used by calendar (show/add days)
            $dateTime = new \DateTime('@' . \XF::$time, $localTimeZone);

            // get the first day of the calendar month using the user's timezone
            $dateTime->setDate($year, $month, $day == 'all' ? 1 : $day);
            $dateTime->setTime(0, 0, 0);

            $startDate = $dateTime->format('Y-m-d');

            if ($day == 'all')
            {
                $dateTime->modify('last day of this month');
                $endDate = $dateTime->format('Y-m-d');
            }
            else
            {
                // don't need to create all the month days if a single day was passed
                $endDate = $startDate;
            }
        }

        $startDateTime = new \DateTime($startDate, $localTimeZone);
        $endDateTime = new \DateTime($endDate, $localTimeZone);

        $openDays = $datesMap = [];

        $startDateUTC = $endDateUTC = null;

        for ($i = $startDateTime; $i <= $endDateTime; $i->modify('+1 day'))
        {
            $iDate = $i->format('Y-m-d');
            $openDays['forum'][$iDate] = $location->max_rotations_forum;
            $openDays['non_forum'][$iDate] = $location->max_rotations_non_forum;

            // // convert it to UTC
            $tempDateTime = clone $i; // avoid changing the original
            $tempDateTime->setTimezone(new \DateTimeZone('UTC'));
            $utcDate = $tempDateTime->format('Y-m-d');
            $datesMap[$utcDate] = $iDate;

            if (!isset($startDateUTC))
            {
                $startDateUTC = $utcDate;
            }
            $endDateUTC = $utcDate;
        }

        ### Ad Days
        $adDayFinder = $this->finder('AddonFlare\PaidAds:AdDay')
            ->with('Ad', true)
            ->where('date', '>=', $startDateUTC)
            ->where('date', '<=', $endDateUTC)
            ->whereOr(
                [['type', '=', 'non_forum']],
                ($nodeId ? [['type', '=', 'forum'], ['Ad.node_id', '=', $nodeId]] : null)
            )
            ->where('Ad.location_id', $location->location_id)
            ->where('Ad.status', ['active', 'pending', 'rejected'])
            ->order('date', 'ASC');

        $purchasedDaysGrouped = $adDayFinder->fetch()->groupBy('type');

        $purchasedDays = $purchasedAdDays = $allDaysPurchased = [];

        foreach ($purchasedDaysGrouped as $groupType => $days)
        {
            $collection = $this->em->getBasicCollection($days);
            $purchasedDays[$groupType] = $collection->groupBy('date');
            // decrease open days count
            foreach ($days as $_day)
            {
                $localDate = $datesMap[$_day->date];
                $openDays[$groupType][$localDate] = max(0, $openDays[$groupType][$localDate] - 1);
            }

            // add days already purchased for this ad_id (if any)
            $purchasedAdDays[$groupType] = $collection->pluck(function($adDay) use ($existingAdId, $datesMap)
            {
                // filter only for purchased days
                if ($existingAdId && $adDay->ad_id == $existingAdId)
                {
                    $localDate = $datesMap[$adDay->date];
                    return [$localDate, $adDay];
                }
            });

            ### only used for admin calendar...
            $allDaysPurchased[$groupType] = false;

            // we must have at least one day added
            if ($purchasedAdDays[$groupType]->count())
            {
                foreach ($openDays[$groupType] as $date => $dateOpenDays)
                {
                    // if we have it added or there's no open slots or it's unavailable (date has passed)
                    if (
                        isset($purchasedAdDays[$groupType][$date]) ||
                        !$dateOpenDays ||
                        !$this->checkValidDate($date)
                    )
                    {
                        $allDaysPurchased[$groupType] = true;
                    }
                    else
                    {
                        $allDaysPurchased[$groupType] = false;
                        // it only takes one to not "select all", so break after the first false
                        break;
                    }
                }
            }
        }


        ### Cart Ad Days
        $cartExpire = $this->options()->af_paidads_cartExpireMinutes * 60;
        // hard code 25 mins, we don't want it being changed
        $transactionExpire = 25 * 60;

        $cartAdDayFinder = $this->finder('AddonFlare\PaidAds:CartAdDay')
            ->with('CartAd', true)
            ->where('date', '>=', $startDateUTC)
            ->where('date', '<=', $endDateUTC)
            ->whereOr(
                [['type', '=', 'non_forum']],
                ($nodeId ? [['type', '=', 'forum'], ['CartAd.node_id', '=', $nodeId]] : null)
            )
            ->where('CartAd.location_id', $location->location_id)
            ->whereOr(
                [['CartAd.status', '=', ['session', 'cart']], ['CartAd.create_date', '>', \XF::$time - $cartExpire]],
                [['CartAd.status', '=', 'transaction'], ['CartAd.create_date', '>', \XF::$time - $transactionExpire]]
            )
            ->order('date', 'ASC');

        $cartDaysGrouped = $cartAdDayFinder->fetch()->groupBy('type');

        $cartDays = $addedCartDays = $allDaysAdded = [];

        foreach ($cartDaysGrouped as $groupType => $days)
        {
            $collection = $this->em->getBasicCollection($days);
            $cartDays[$groupType] = $collection->groupBy('date');
            // decrease open days count
            foreach ($days as $_day)
            {
                $localDate = $datesMap[$_day->date];
                $openDays[$groupType][$localDate] = max(0, $openDays[$groupType][$localDate] - 1);
            }

            // add cart days already selected/active for this cart_ad_id
            $addedCartDays[$groupType] = $collection->pluck(function($cartAdDay) use ($cartAdId, $datesMap)
            {
                // filter only for selected days
                if ($cartAdId && $cartAdDay->cart_ad_id == $cartAdId)
                {
                    $localDate = $datesMap[$cartAdDay->date];
                    return [$localDate, $cartAdDay];
                }
            });

            $allDaysAdded[$groupType] = false;

            // we must have at least one day added
            if ($addedCartDays[$groupType]->count())
            {
                foreach ($openDays[$groupType] as $date => $dateOpenDays)
                {
                    // if we have it added or there's no open slots or it's unavailable (date has passed)
                    if (
                        isset($purchasedAdDays[$groupType][$date]) ||
                        isset($addedCartDays[$groupType][$date]) ||
                        !$dateOpenDays ||
                        !$this->checkValidDate($date)
                    )
                    {
                        $allDaysAdded[$groupType] = true;
                    }
                    else
                    {
                        $allDaysAdded[$groupType] = false;
                        // it only takes one to not "select all", so break after the first false
                        break;
                    }
                }
            }
        }

        $output = [
            'openDays'         => $openDays,
            'startDate'        => $startDate,
            'endDate'          => $endDate,
            // Ad days
            'purchasedDays'    => $purchasedDays,
            'purchasedAdDays'  => $purchasedAdDays,
            'allDaysPurchased' => $allDaysPurchased,
            // Cart Ad Days
            'cartDays'         => $cartDays,
            'addedCartDays'    => $addedCartDays,
            'allDaysAdded'     => $allDaysAdded,
        ];

        return $output;
    }

    public function getPricesPerDay(\AddonFlare\PaidAds\Entity\Location $location, $nodeId)
    {
        $pricesPerDay = ['forum' => 0, 'non_forum' => 0];

        $purchaseOptions = $location->purchase_options;

        $purchaseOptionsForum = $purchaseOptions['forum'];
        $purchaseOptionsNonForum = $purchaseOptions['non_forum'];

        $forumPricePerDay = $nonForumPricePerDay = 0;

        ### forum price
        if ($location->canPurchaseForum($nodeId))
        {
            if ($purchaseOptionsForum['price_type'] == 'fixed')
            {
                $forumPricePerDay = $purchaseOptionsForum['fixed_price'];
            }
            else if ($purchaseOptionsForum['price_type'] == 'dynamic') // dynamic
            {
                if ($forum = $this->em->find('XF:Forum', $nodeId))
                {
                    $dynamicPriceFunc = function($conditions) use ($forum)
                    {
                        $postCount = $forum->message_count;

                        return eval($conditions);
                    };
                    // check for custom tier prices first
                    if (!empty($purchaseOptionsForum['dynamic_custom_forum_price_tiers_conditions'][$nodeId]))
                    {
                        $forumPricePerDay = $dynamicPriceFunc($purchaseOptionsForum['dynamic_custom_forum_price_tiers_conditions'][$nodeId]);
                    }
                    else
                    {
                        $forumPricePerDay = $dynamicPriceFunc($purchaseOptionsForum['dynamic_price_tiers_conditions']);
                    }
                }
            }
        }

        ### non-forum price
        if ($location->can_purchase_non_forum)
        {
            $nonForumPricePerDay = $purchaseOptionsNonForum['fixed_price'];
        }

        $pricesPerDay['forum'] = floatval($forumPricePerDay);
        $pricesPerDay['non_forum'] = floatval($nonForumPricePerDay);

        return $pricesPerDay;
    }

    // check if date is valid and hasn't passed yet
    public function checkValidDate($date)
    {
        static $dates = [];

        if (!isset($dates[$date]))
        {
            $language = \XF::language();

            $todayTimeStamp = $language->getDayStartTimestamps()['today'];

            try
            {
                $dateTime = new \DateTime($date, $language->getTimeZone());
                $dateTime->setTime(0, 0, 0);
            }
            catch (\Exception $e)
            {
                return false;
            }

            $date = $dateTime->format('Y-m-d');

            // make sure it gets saved in the same format every time (since this is user input and can be anything)
            $dates[$date] = ($dateTime->getTimeStamp() >= $todayTimeStamp) ? $dateTime : false;
        }

        return $dates[$date];
    }

    public function getTotalGroupedLocations(array $groupedLocations)
    {
        $total = 0;

        foreach ($groupedLocations AS $locations)
        {
            $total += count($locations);
        }

        return $total;
    }
}