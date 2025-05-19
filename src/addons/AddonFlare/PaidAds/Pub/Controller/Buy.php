<?php

namespace AddonFlare\PaidAds\Pub\Controller;

use AddonFlare\PaidAds\Calendar;
use XF\Mvc\ParameterBag;
use XF\Pub\Controller\AbstractController;

class Buy extends AbstractController
{
    public function actionIndex()
    {
        $locationRepo = $this->getLocationRepo();

        $locationsFinder = $locationRepo->findLocationsForList(true)
            ->where('can_purchase', 1);

        $locations = $locationRepo->filterPurchasable($locationsFinder->fetch());

        $locations = \XF\Util\Arr::columnSort($locations->toArray(), 'title');
        $locations = $this->em()->getBasicCollection($locations);

        $cart = $this->getCartRepo()->findUserCart();

        $viewParams = [
            'locations' => $locations,
            'cart' => $cart,
        ];

        return $this->view('', 'af_paidads_buy_ads', $viewParams);
    }

    protected function getLocationRepo()
    {
        return $this->repository('AddonFlare\PaidAds:Location');
    }

    protected function getCartRepo()
    {
        return $this->repository('AddonFlare\PaidAds:Cart');
    }

    public function actionGetLocationTypes()
    {
        $locationId = $this->filter('location_id', 'uint');

        $location = $this->assertPurchasableLocationExists($locationId);

        $showForumOptions = $showNonForumOptions = false;

        if ($location->canPurchaseForum()) {
            $showForumOptions = true;
        }
        if ($location->can_purchase_non_forum) {
            $showNonForumOptions = true;
        }

        $viewParams = [
            'location' => $location,
            'showForumOptions' => $showForumOptions,
            'showNonForumOptions' => $showNonForumOptions,
        ];

        return $this->view('', 'af_paidads_buy_ads_location_types', $viewParams);
    }

    protected function assertPurchasableLocationExists($id, $with = null, $phraseKey = null)
    {
        $location = $this->em()->find('AddonFlare\PaidAds:Location', $id, $with);

        if (!$location || !$location->canPurchase()) {
            if (!$phraseKey) {
                $phraseKey = 'requested_page_not_found';
            }

            throw $this->exception(
                $this->notFound(\XF::phrase($phraseKey))
            );
        }

        return $location;
    }

    public function actionGetForums()
    {
        $locationId = $this->filter('location_id', 'uint');
        $adType = $this->filter('ad_type', 'str');

        $location = $this->assertPurchasableLocationExists($locationId);

        $purchaseOptions = $location->purchase_options;

        if (!$location->canPurchaseForum(0, $availableNodeIds)) {
            return $this->notFound();
        }

        $nodeRepo = $this->repository('XF:Node');
        $nodeTree = $nodeRepo->createNodeTree($nodeRepo->getFullNodeList());

        $viewParams = [
            'location' => $location,
            'nodeTree' => $nodeTree,
            'locationId' => $locationId,
            'adType' => $adType,
            'availableNodeIds' => $availableNodeIds,
        ];

        return $this->view('', 'af_paidads_buy_ads_forums', $viewParams);
    }

    public function actionCalendar(ParameterBag $params)
    {
        $cartAdId = $params->cartAdId ?: $this->filter('cart_ad_id', 'uint');
        
        $locationId = $this->filter('location_id', 'uint');
        $nodeId = $this->filter('node_id', 'uint');

        $viewMonth = $params->viewMonth ?: $this->filter('view_month', 'uint');
        $viewYear = $params->viewYear ?: $this->filter('view_year', 'uint');

        $visitor = \XF::visitor();
        $locationRepo = $this->getLocationRepo();

        if ($cartAd = $locationRepo->findUserCartAd($cartAdId)) {
            $location = $cartAd->Location;
            $locationId = $cartAd->location_id;
            $nodeId = $cartAd->node_id;
            $adType = $cartAd->type;
        } else {
            $location = $this->assertPurchasableLocationExists($locationId);
            $adType = $this->filter('ad_type', 'str');
        }

        $pricesPerDay = $this->getLocationRepo()->getPricesPerDay($location, $nodeId);

        $forumPricePerDay = $nonForumPricePerDay = 0;
        $showForumOptions = $showNonForumOptions = false;

        if (in_array($adType, ['forum', 'both'])) {
            if (!$forumPricePerDay = $pricesPerDay['forum']) {
                return $this->notFound();
            } else {
                $showForumOptions = true;
            }
        }

        if (in_array($adType, ['non_forum', 'both'])) {
            if (!$nonForumPricePerDay = $pricesPerDay['non_forum']) {
                return $this->notFound();
            } else {
                $showNonForumOptions = true;
            }
        }

        if (!$showForumOptions && !$showNonForumOptions) {
            return $this->notFound();
        }

        if (!$showForumOptions) {
            // non-forums should always have this set as 0
            $nodeId = 0;
        }

        if (!$cartAd) {
            $existingAdId = null;

            if ($adId = $this->filter('ad_id', 'uint')) {
                if ($ad = $this->getAdRepo()->findUserAd($adId)->where('status', '=', 'active')->fetchOne()) {
                    // make sure cart and ad are the same type, nodeid, etc
                    // we explicitly pass these in the manage ads "add days" link, this makes sure they're not modified
                    if (
                        ($ad->location_id == $locationId) &&
                        ($ad->type == $adType) &&
                        ($ad->node_id == $nodeId)
                    ) {
                        $existingAdId = $adId;
                    } else {
                        return $this->notFound();
                    }
                }
            }

            $cartAd = $this->em()->create('AddonFlare\PaidAds:CartAd');
            $cartAd->bulkSet([
                'location_id' => $locationId,
                'user_id' => $visitor->user_id,
                'node_id' => $nodeId,
                'status' => 'session',
                'type' => $adType,
                'existing_ad_id' => $existingAdId,
            ]);
            $cartAd->save();
        }

        $calendar = new Calendar($location, $adType, $viewMonth, $viewYear, $nodeId, $cartAd);

        $router = $this->app->router();

        $addDaysUrl = $router->buildLink('paid-ads/calendar-add-days', null, [
            'cart_ad_id' => $cartAd->cart_ad_id,
            'view_month' => $viewMonth,
            'view_year' => $viewYear,
        ]);
        $removeDaysUrl = $router->buildLink('paid-ads/calendar-remove-days', null, [
            'cart_ad_id' => $cartAd->cart_ad_id,
            'view_month' => $viewMonth,
            'view_year' => $viewYear,
        ]);
        $calendarUrl = $router->buildLink('paid-ads/calendar', null, [
            'cart_ad_id' => $cartAd->cart_ad_id,
            'view_year' => $viewYear,
            'view_year' => $viewYear,
        ]);

        $purchaseOptions = $location->purchase_options;

        $withingDayLimits = ($cartAd->total_days >= $purchaseOptions['min_days'] && $cartAd->total_days <= $purchaseOptions['max_days']);

        $cart = $this->getCartRepo()->findUserCart();

        $viewParams = [
            'location' => $location,
            'calendar' => $calendar->build(),
            'showForumOptions' => $showForumOptions,
            'showNonForumOptions' => $showNonForumOptions,
            'forumPricePerDay' => $forumPricePerDay,
            'nonForumPricePerDay' => $nonForumPricePerDay,
            'withingDayLimits' => $withingDayLimits,
            'cartAd' => $cartAd,
            'addDaysUrl' => $addDaysUrl,
            'removeDaysUrl' => $removeDaysUrl,
            'calendarUrl' => $calendarUrl,
            'cart' => $cart,
        ];

        $language = $this->app()->language();

        $reply = $this->view('', 'af_paidads_buy_ads_calendar', $viewParams);
        $reply->setJsonParams([
            'cart_ad_id' => $cartAd->cart_ad_id,
            'cart_ad_total_days_forum' => $language->numberFormat($cartAd->total_days_forum),
            'cart_ad_total_days_non_forum' => $language->numberFormat($cartAd->total_days_non_forum),
            'cart_ad_total_days' => $language->numberFormat($cartAd->total_days),
            'cart_ad_total_amount' => $location->getCostPhrase($cartAd->total_amount),
            'cart_total_amount' => \XF::phrase('af_paidads_buy_now_x', [
                'price' => $location->getCostPhrase($cart ? $cart->total_amount : 0),
            ]),
        ]);

        return $reply;
    }

    protected function getAdRepo()
    {
        return $this->repository('AddonFlare\PaidAds:Ad');
    }

    public function actionCalendarAddDays()
    {
        $cartAdId = $this->filter('cart_ad_id', 'uint');
        $dates = $this->filter('dates', 'str');
        $dayType = $this->filter('day_type', 'str');

        $viewMonth = $this->filter('view_month', 'uint');
        $viewYear = $this->filter('view_year', 'uint');

        $locationRepo = $this->getLocationRepo();

        if ((!$cartAd = $locationRepo->findUserCartAd($cartAdId)) || !in_array($dayType, ['forum', 'non_forum'])) {
            return $this->notFound();
        }

        $nodeId = $cartAd->node_id;

        $pricesPerDay = $cartAd->getPricesPerDay();

        if (($dayType == 'forum' && !$pricesPerDay['forum']) || ($dayType == 'non_forum' && !$pricesPerDay['non_forum'])) {
            return $this->notFound();
        }

        $dates = explode('|', $dates);

        $multipleAdd = (count($dates) > 1);

        $insertDays = [];

        foreach ($dates as $date) {
            if (!$dateTime = $locationRepo->checkValidDate($date)) {
                if ($multipleAdd) {
                    continue;
                } else {
                    return $this->error(\XF::phrase('af_paidads_invalid_date'));
                }
            }

            $year = $dateTime->format('Y');
            $month = $dateTime->format('n');
            $day = $dateTime->format('j');

            // only retrieve on the first loop
            if (!isset($adDays)) {
                // getAdDaysForCalendar() takes the local user month/day/year, so pass it as it is
                $adDays = $locationRepo->getAdDaysForCalendar(
                    $cartAd->Location,
                    $cartAdId,
                    $cartAd->existing_ad_id,
                    $year,
                    $month,
                    $multipleAdd ? 'all' : $day,
                    $nodeId
                );
            }

            $fullDate = $dateTime->format('Y-m-d');
            $dateTime->setTimezone(new \DateTimeZone('UTC'));
            $fullDateUTC = $dateTime->format('Y-m-d'); // this is how we save it

            $forumRotationsOpen = $adDays['openDays']['forum'][$fullDate];
            $nonForumRotationsOpen = $adDays['openDays']['non_forum'][$fullDate];

            $forumDayIsAdded = isset($adDays['addedCartDays']['forum'][$fullDate]);
            $nonForumDayIsAdded = isset($adDays['addedCartDays']['non_forum'][$fullDate]);

            $forumDayIsPurchased = isset($adDays['purchasedAdDays']['forum'][$fullDate]);
            $nonForumDayIsPurchased = isset($adDays['purchasedAdDays']['non_forum'][$fullDate]);

            if (
                $dayType == 'forum' &&
                (
                    !in_array($cartAd->type, ['forum', 'both']) ||
                    !$forumRotationsOpen ||
                    $forumDayIsAdded ||
                    $forumDayIsPurchased
                )
            ) {
                if ($multipleAdd) {
                    continue;
                } else {
                    return $this->error(\XF::phrase('af_paidads_day_not_available'));
                }
            } else {
                if ($dayType == 'non_forum' &&
                    (
                        !in_array($cartAd->type, ['non_forum', 'both']) ||
                        !$nonForumRotationsOpen ||
                        $nonForumDayIsAdded ||
                        $nonForumDayIsPurchased
                    )
                ) {
                    if ($multipleAdd) {
                        continue;
                    } else {
                        return $this->error(\XF::phrase('af_paidads_day_not_available'));
                    }
                }
            }

            $insertDays[] = [
                'cart_ad_id' => $cartAdId,
                'date' => $fullDateUTC,
                'type' => $dayType,
            ];
        }

        if ($insertDays) {
            $maxDays = $cartAd->Location->purchase_options['max_days'];
            if (($cartAd->total_days + count($insertDays)) > $maxDays) {
                return $this->error(\XF::phrase('af_paidads_max_amount_days_is_x', ['days' => $maxDays]));
            }
            $this->app->db()->insertBulk('xf_af_paidads_cart_ad_day', $insertDays, false, false, 'IGNORE');
            $this->getCartAdRepo()->rebuildCartAd($cartAd);
            if ($cartAd->Cart) {
                $this->getCartRepo()->rebuildCart($cartAd->Cart);
            }
        } else {
            // should never happen but incase
            return $this->error(\XF::phrase('af_paidads_unable_to_add_days'));
        }

        return $this->rerouteController(__CLASS__, 'Calendar', [
            'cartAdId' => $cartAdId,
            'viewMonth' => $viewMonth,
            'viewYear' => $viewYear,
        ]);
    }

    protected function getCartAdRepo()
    {
        return $this->repository('AddonFlare\PaidAds:CartAd');
    }

    public function actionCalendarRemoveDays()
    {
        $cartAdId = $this->filter('cart_ad_id', 'uint');
        $dates = $this->filter('dates', 'str');
        $dayType = $this->filter('day_type', 'str');

        $viewMonth = $this->filter('view_month', 'uint');
        $viewYear = $this->filter('view_year', 'uint');

        $locationRepo = $this->getLocationRepo();

        if ((!$cartAd = $locationRepo->findUserCartAd($cartAdId)) || !in_array($dayType, ['forum', 'non_forum'])) {
            return $this->notFound();
        }

        $dates = explode('|', $dates);

        $multipleRemove = (count($dates) > 1);

        $removeDays = [];

        foreach ($dates as $date) {
            if (!$dateTime = $locationRepo->checkValidDate($date)) {
                continue;
            }

            // convert it to UTC
            $dateTime->setTimezone(new \DateTimeZone('UTC'));
            $removeDays[] = $dateTime->format('Y-m-d');
        }

        $cartExpire = $this->options()->af_paidads_cartExpireMinutes * 60;

        if ($removeDays) {
            if ($cartAd->status == 'cart') {
                $minDays = $cartAd->Location->purchase_options['min_days'];
                if (($cartAd->total_days - count($removeDays)) < $minDays) {
                    return $this->error(\XF::phrase('af_paidads_min_amount_days_is_x', ['days' => $minDays]));
                }
            }

            $db = $this->app->db();
            $db->query(
                "
                DELETE cart_ad_day
                FROM xf_af_paidads_cart_ad_day AS cart_ad_day
                INNER JOIN xf_af_paidads_cart_ad AS cart_ad ON (cart_ad.cart_ad_id = cart_ad_day.cart_ad_id)
                WHERE
                    cart_ad_day.cart_ad_id = ?
                    AND cart_ad_day.type = ?
                    AND cart_ad.user_id = ?
                    AND cart_ad.create_date > ?
                    AND cart_ad.status IN ('session', 'cart')
                    AND cart_ad_day.date IN (".$db->quote($removeDays).")",
                [
                    $cartAdId,
                    $dayType,
                    \XF::visitor()->user_id,
                    \XF::$time - $cartExpire,
                ]
            );
            $this->getCartAdRepo()->rebuildCartAd($cartAd);
            if ($cartAd->Cart) {
                $this->getCartRepo()->rebuildCart($cartAd->Cart);
            }
        }

        return $this->rerouteController(__CLASS__, 'Calendar', [
            'cartAdId' => $cartAdId,
            'viewMonth' => $viewMonth,
            'viewYear' => $viewYear,
        ]);
    }

    public function actionAddToCart()
    {
        $cartAdId = $this->filter('cart_ad_id', 'uint');

        $locationRepo = $this->getLocationRepo();

        if (!$cartAd = $locationRepo->findUserCartAd($cartAdId)) {
            $reply = $this->error(\XF::phrase('af_paidads_invalid_cart_ad'));
            $reply->setJsonParams([
                'empty_all' => true,
            ]);

            return $reply;
        }

        $location = $cartAd->Location;

        $purchaseOptions = $location->purchase_options;

        $minDays = $purchaseOptions['min_days'];
        $maxDays = $purchaseOptions['max_days'];
        if (!($cartAd->total_days >= $minDays && $cartAd->total_days <= $maxDays)) {
            return $this->error(
                \XF::phrase('af_paidads_days_must_be_within_min_and_max', ['min' => $minDays, 'max' => $maxDays])
            );
        }

        $visitor = \XF::visitor();

        $cart = $this->getCartRepo()->findUserCart();

        if (!$cart) {
            $cart = $this->em()->create('AddonFlare\PaidAds:Cart');
            $cart->bulkSet([
                'user_id' => $visitor->user_id,
            ]);
            $cart->save();
        }

        $cartAd->cart_id = $cart->cart_id;
        $cartAd->status = 'cart';
        $cartAd->save();

        $this->getCartRepo()->rebuildCart($cart);

        $reply = $this->message(\XF::phrase('af_paidads_addedToCart_viewCart', [
            'cartUrl' => $this->app->router()->buildLink('paid-ads/cart'),
        ]));
        $reply->setJsonParams([
            'empty_all' => true,
            'cart_total_items' => $this->app()->language()->numberFormat($cart->total_items),
        ]);

        return $reply;
    }

    public function actionRemoveFromCart()
    {
        $cartAdId = $this->filter('cart_ad_id', 'uint');

        $locationRepo = $this->getLocationRepo();

        if ((!$cartAd = $locationRepo->findUserCartAd($cartAdId, false)) || (!$cart = clone $cartAd->Cart)) {
            return $this->notFound();
        }

        $location = $cartAd->Location;

        $cartAd->delete();

        $this->getCartRepo()->rebuildCart($cart);

        $reply = $this->message(\XF::phrase('af_paidads_removed'));
        $reply->setJsonParams([
            'redirect' => $cart->total_items ? null : $this->app->router()->buildLink('paid-ads/cart'),
            'cart_total_amount' => \XF::phrase('af_paidads_buy_now_x', [
                'price' => $location->getCostPhrase($cart->total_amount),
            ]),
        ]);

        return $reply;
    }

    public function actionCart()
    {
        $cartRepo = $this->getCartRepo();
        $cart = $cartRepo->findUserCart();

        $cartAds = $purchasable = $profiles = null;

        $nodeRepo = $this->repository('XF:Node');
        $nodeTree = $nodeRepo->createNodeTree($nodeRepo->getNodeList());

        $forums = [];

        foreach ($nodeTree->getFlattened() as $entry) {
            /** @var \XF\Entity\Node $node */
            $node = $entry['record'];

            $forums[$node->node_id] = $node->title;
        }

        if ($cart) {
            // quick rebuild incase any ads expired and get ads
            $cartAds = $cartRepo->rebuildCart($cart);

            // just checks if the addon is active
            $purchasable = $this->em()->find('XF:Purchasable', 'af_paidads_cart', 'AddOn');
            if (!$purchasable->isActive()) {
                return $this->message(\XF::phrase('af_paidads_no_ads_can_be_purchased_at_this_time'));
            }

            $paymentRepo = $this->repository('XF:Payment');
            $profiles = $paymentRepo->findPaymentProfilesForList()->fetch();
        }

        $viewParams = [
            'cartAds' => $cartAds,
            'totalCartAds' => $cartAds ? $cartAds->count() : 0,
            'forums' => $forums,
            'cart' => $cart,
            'profiles' => $profiles,
        ];

        return $this->view('', 'af_paidads_cart', $viewParams);
    }

    public function actionCartPurchase()
    {
        return $this->view('', 'af_paidads_cart_purchase');
    }

    public function actionGetLocationDescription()
    {
        $plugin = $this->plugin('XF:DescLoader');

        return $plugin->actionLoadDescription('AddonFlare\PaidAds:Location');
    }

    protected function preDispatchController($action, ParameterBag $params)
    {
        if (!\XF::visitor()->canPurchasePaidAds()) {
            throw $this->exception($this->noPermission());
        }

        // alias to bypass adblock parameter names check
        $this->request->set('ad_type', $this->request->get('a_type'));
    }

    protected function assertLocationExists($id, $with = null, $phraseKey = null)
    {
        return $this->assertRecordExists('AddonFlare\PaidAds:Location', $id, $with, $phraseKey);
    }
}