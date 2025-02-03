<?php

namespace AddonFlare\PaidAds\Admin\Controller;

use XF\Admin\Controller\AbstractController;
use XF\Mvc\FormAction;
use XF\Mvc\ParameterBag;

use AddonFlare\PaidAds\Calendar;

class Ad extends AbstractController
{
    protected function preDispatchController($action, ParameterBag $params)
    {
        $this->assertAdminPermission('advertising');
    }

    public function actionIndex()
    {
        $status = $this->filter('status', 'str');
        $showLocationId = $this->filter('location_id', 'uint');

        $actions = [];
        switch ($status)
        {
            case 'inactive':
            {
                $actions[] = 'activate';
                break;
            }
            case 'pending':
            {
                $actions[] = 'approve';
                $actions[] = 'reject';
                $actions[] = 'deactivate';
                break;
            }
            case 'expired':
            {
                $actions[] = 'activate';
                break;
            }
            case 'rejected':
            {
                $actions[] = 'approve';
                break;
            }

            default: // show active ads by default
            {
                $status = 'active';
                $actions[] = 'deactivate';
                break;
            }
        }

        $page = $this->filterPage();
        $perPage = 25;

        $this->setSectionContext('af_paidads_ads' . ucfirst($status));

        $adsFinder = $this->getAdRepo()->findAdsForList()
            ->with('User')
            ->where('status', $status)
            ->limitByPage($page, $perPage);

        if ($status == 'pending')
        {
            $adsFinder
                ->where('url', '!=', '')
                ->order('create_date', 'ASC');
        }
        else
        {
            $adsFinder->order('create_date', 'DESC');
        }

        if ($showLocationId)
        {
            $adsFinder->where('location_id', $showLocationId);
        }

        $ads = $adsFinder->fetch();

        $showLocations = $this->app()->db()->fetchPairs('
            SELECT ad.location_id, ad.location_id
            FROM xf_af_paidads_ad ad
            INNER JOIN xf_af_paidads_location location ON (location.location_id = ad.location_id)
            WHERE status = ?
            GROUP BY ad.location_id
        ', [$status]);

        foreach ($showLocations as $locationId => $locationTitle)
        {
            $showLocations[$locationId] = (string) \XF::phrase("af_paidads_loc.{$locationId}");
        }

        asort($showLocations);

        $showLocations = [0 => \XF::phrase('af_paidads_all_locations')] + $showLocations;

        $viewParams = [
            'ads'       => $ads,
            'actions'   => $actions,
            'showLocations'  => $showLocations,
            'showLocationId' => $showLocationId,
            'pageTitle' => \XF::phrase('admin_navigation.' . $this->sectionContext()),
            'status'    => $status,
            'page'      => $page,
            'perPage'   => $perPage,
            'total'     => $adsFinder->total(),
        ];

        return $this->view('', 'af_paidads_ad_list', $viewParams);
    }

    public function actionStats(ParameterBag $params)
    {
        $ad = $this->assertAdExists($params->ad_id);

        $last24HrsClickCount = $this->finder('AddonFlare\PaidAds:AdClick')
            ->where('ad_id', $ad->ad_id)
            ->where('date', '>=', \XF::$time - 86400)
            ->total();

        $viewParams = [
            'ad' => $ad,
            'last24HrsClickCount' => $last24HrsClickCount,
        ];

        return $this->view('', 'af_paidads_ad_stats', $viewParams);
    }

    public function actionUpdateStatus()
    {
        $adIds = $this->filter('ad_ids', 'array-uint');
        $status = $this->filter('status', 'str');

        if ($status == 'submit_multiple')
        {
            return $this->actionEditMultiple();
        }

        $actions = [
            'active',
            'inactive',
            'pending',
            'expired',
            'rejected',
        ];

        if (!in_array($status, $actions))
        {
            return $this->error(\XF::phrase('af_paidads_please_select_action'));
        }

        $alertRepo = $this->repository('XF:UserAlert');

        $ads = $this->finder('AddonFlare\PaidAds:Ad')
            ->with('Location', true)
            ->with('User')
            ->where('ad_id', $adIds);

        // status => action
        $alertActionMap = [
            'active'   => 'approved',
            'rejected' => 'rejected',
        ];

        $locationRepo = $this->getLocationRepo();
        $adRepo = $this->getAdRepo();

        $dateTime = new \DateTime('@' . \XF::$time, new \DateTimeZone('UTC'));
        $dateToday = $dateTime->format('Y-m-d');

        foreach ($ads as $adId => $ad)
        {
            if ($status == 'rejected')
            {
                $ad->set('url', '', ['forceConstraint' => true]);
                $bannerService = $this->service('AddonFlare\PaidAds:Ad\Banner', $ad->Location, $ad);
                $bannerService->deleteBanner();
            }
            else if ($status == 'active' && in_array($ad->status, ['inactive', 'expired']))
            {
                // going from inactive/expired to approved, rejected isn't included here because it's counted in the active days method
                $dateRange = $this->app()->db()->fetchRow('
                    SELECT
                        MIN(`date`) AS start_date,
                        MAX(`date`) AS end_date
                    FROM xf_af_paidads_ad_day
                    WHERE
                        ad_id = ?
                        AND `date` >= ?
                ', [$ad->ad_id, $dateToday]);

                if ($dateRange['start_date'] && $dateRange['end_date'])
                {
                    // check day by day if we have space...
                    $calendarAdDays = $locationRepo->getAdDaysForCalendar($ad->Location, null, null, null, null, null, $ad->node_id, ['start' => $dateRange['start_date'], 'end' => $dateRange['end_date']]);

                    $adDays = $this->finder('AddonFlare\PaidAds:AdDay')
                    ->where('ad_id', $ad->ad_id)
                    ->where('date', '>=', $dateToday)
                    ->order('date', 'ASC')
                    ->fetch();

                    $rebuildAd = false;

                    foreach ($adDays as $adDay)
                    {
                        $openDayRotations = $calendarAdDays['openDays'][$adDay->type][$adDay->date];
                        if (($openDayRotations - 1) < 0)
                        {
                            // not enough rotations available for this day, delete it to continue
                            $adDay->delete();
                            $rebuildAd = true;
                        }
                    }

                    if ($rebuildAd)
                    {
                        $adRepo->rebuildAd($ad);
                    }
                }
            }

            $ad->status = $status;
            $ad->save();

            if (array_key_exists($status, $alertActionMap))
            {
                $alertRepo->alertFromUser(
                    $ad->User,
                    $ad->User,
                    'af_paidads_ad',
                    $ad->ad_id,
                    $alertActionMap[$status]
                );
            }
        }

        $this->rebuildDailyCache();

        return $this->redirect($this->getDynamicRedirect());
    }

    public function actionCalendar(ParameterBag $params)
    {
        $ad = $this->assertAdExists($params->ad_id);

        $viewMonth = $params->viewMonth ?: $this->filter('view_month', 'uint');
        $viewYear = $params->viewYear ?: $this->filter('view_year', 'uint');

        $locationRepo = $this->getLocationRepo();

        $location = $ad->Location;
        $locationId = $ad->location_id;
        $nodeId = $ad->node_id;
        $adType = $ad->type;

        $pricesPerDay = $this->getLocationRepo()->getPricesPerDay($location, $nodeId);

        $forumPricePerDay = $nonForumPricePerDay = 0;
        $showForumOptions = $showNonForumOptions = false;

        if (in_array($adType, ['forum', 'both']))
        {
            $showForumOptions = true;
        }

        if (in_array($adType, ['non_forum', 'both']))
        {
            $showNonForumOptions = true;
        }

        // should never happen but incase
        if (!$showForumOptions && !$showNonForumOptions)
        {
            return $this->notFound();
        }

        $calendar = new Calendar($location, $adType, $viewMonth, $viewYear, $nodeId, null, $ad);

        $router = $this->app->router();

        $addDaysUrl = $router->buildLink('paid-ads/ads/calendar-add-days', $ad, [
            'view_month'  => $viewMonth,
            'view_year'   => $viewYear,
        ]);
        $removeDaysUrl = $router->buildLink('paid-ads/ads/calendar-remove-days', $ad, [
            'view_month'  => $viewMonth,
            'view_year'   => $viewYear,
        ]);
        $calendarUrl = $router->buildLink('paid-ads/ads/calendar', $ad, [
            'view_year'   => $viewYear,
            'view_year'   => $viewYear,
        ]);

        $purchaseOptions = $location->purchase_options;

        $withingDayLimits = true;

        $viewParams = [
            'location'            => $location,
            'calendar'            => $calendar->build(),
            'showForumOptions'    => $showForumOptions,
            'showNonForumOptions' => $showNonForumOptions,
            'forumPricePerDay'    => $forumPricePerDay,
            'nonForumPricePerDay' => $nonForumPricePerDay,
            'withingDayLimits'    => $withingDayLimits,
            'ad'                  => $ad,
            'addDaysUrl'          => $addDaysUrl,
            'removeDaysUrl'       => $removeDaysUrl,
            'calendarUrl'         => $calendarUrl,
            'isAdmin'             => true,
            'pageTitle'           => "Edit days: {$location->title} ({$ad->User->username})",
        ];

        $language = $this->app()->language();

        $reply = $this->view('', 'public:af_paidads_buy_ads_calendar', $viewParams);
        $reply->setJsonParams([
            'ad_id'                       => $ad->ad_id,
            'ad_days_remaining_forum'     => $language->numberFormat($ad->days_remaining_forum),
            'ad_days_remaining_non_forum' => $language->numberFormat($ad->days_remaining_non_forum),
        ]);
        return $reply;
    }

    public function actionCalendarAddDays(ParameterBag $params)
    {
        $dates = $this->filter('dates', 'str');
        $dayType = $this->filter('day_type', 'str');

        $viewMonth = $this->filter('view_month', 'uint');
        $viewYear = $this->filter('view_year', 'uint');

        if (!in_array($dayType, ['forum', 'non_forum']))
        {
            return $this->notFound();
        }

        $ad = $this->assertAdExists($params->ad_id);
        $nodeId = $ad->node_id;

        $locationRepo = $this->getLocationRepo();

        $dates = explode('|', $dates);

        $multipleAdd = (count($dates) > 1);

        $insertDays = [];

        foreach ($dates as $date)
        {
            if (!$dateTime = $locationRepo->checkValidDate($date))
            {
                if ($multipleAdd)
                {
                    continue;
                }
                else
                {
                    return $this->error(\XF::phrase('af_paidads_invalid_date'));
                }
            }

            $year = $dateTime->format('Y');
            $month = $dateTime->format('n');
            $day = $dateTime->format('j');

            // only retrieve on the first loop
            if (!isset($adDays))
            {
                // getAdDaysForCalendar() takes the local user month/day/year, so pass it as it is
                $adDays = $locationRepo->getAdDaysForCalendar($ad->Location, null, $ad->ad_id, $year, $month, $multipleAdd ? 'all' : $day, $nodeId);
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
                    !in_array($ad->type, ['forum', 'both']) ||
                    !$forumRotationsOpen ||
                    $forumDayIsAdded ||
                    $forumDayIsPurchased
                )
            )
            {
                if ($multipleAdd)
                {
                    continue;
                }
                else
                {
                    return $this->error(\XF::phrase('af_paidads_day_not_available'));
                }
            }
            else if ($dayType == 'non_forum' &&
                (
                    !in_array($ad->type, ['non_forum', 'both']) ||
                    !$nonForumRotationsOpen ||
                    $nonForumDayIsAdded ||
                    $nonForumDayIsPurchased
                )
            )
            {
                if ($multipleAdd)
                {
                    continue;
                }
                else
                {
                    return $this->error(\XF::phrase('af_paidads_day_not_available'));
                }
            }

            $insertDays[] = [
                'ad_id' => $ad->ad_id,
                'date'  => $fullDateUTC,
                'type'  => $dayType,
            ];
        }

        if ($insertDays)
        {
            $this->app->db()->insertBulk('xf_af_paidads_ad_day', $insertDays, false, false, 'IGNORE');
            $this->getAdRepo()->rebuildAd($ad);
        }
        else
        {
            // should never happen but incase
            return $this->error(\XF::phrase('af_paidads_unable_to_add_days'));
        }

        return $this->rerouteController(__CLASS__, 'Calendar', [
            'ad_id'      => $ad->ad_id,
            'viewMonth'  => $viewMonth,
            'viewYear'   => $viewYear,
        ]);
    }

    public function actionCalendarRemoveDays(ParameterBag $params)
    {
        $dates = $this->filter('dates', 'str');
        $dayType = $this->filter('day_type', 'str');

        $viewMonth = $this->filter('view_month', 'uint');
        $viewYear = $this->filter('view_year', 'uint');

        if (!in_array($dayType, ['forum', 'non_forum']))
        {
            return $this->notFound();
        }

        $ad = $this->assertAdExists($params->ad_id);
        $locationRepo = $this->getLocationRepo();

        $dates = explode('|', $dates);

        $multipleRemove = (count($dates) > 1);

        $removeDays = [];

        foreach ($dates as $date)
        {
            if (!$dateTime = $locationRepo->checkValidDate($date))
            {
                continue;
            }

            // convert it to UTC
            $dateTime->setTimezone(new \DateTimeZone('UTC'));
            $removeDays[] = $dateTime->format('Y-m-d');
        }

        if ($removeDays)
        {
            $db = $this->app->db();
            $db->query("
                DELETE ad_day
                FROM xf_af_paidads_ad_day AS ad_day
                INNER JOIN xf_af_paidads_ad AS ad ON (ad.ad_id = ad_day.ad_id)
                WHERE
                    ad_day.ad_id = ?
                    AND ad_day.type = ?
                    AND ad_day.date IN (".$db->quote($removeDays).")",
                [
                    $ad->ad_id,
                    $dayType,
                ]
            );
            $this->getAdRepo()->rebuildAd($ad);
        }

        return $this->rerouteController(__CLASS__, 'Calendar', [
            'ad_id'      => $ad->ad_id,
            'viewMonth'  => $viewMonth,
            'viewYear'   => $viewYear,
        ]);
    }

    public function actionEdit(ParameterBag $params)
    {
        $ad = $this->assertAdExists($params->ad_id);

        return $this->adAddEdit($ad);
    }

    protected function adAddEdit(\AddonFlare\PaidAds\Entity\Ad $ad)
    {
        $location = $ad->Location;
        $viewParams = [
            'ad'         => $ad,
            'title'      => $location->title,
            'adOptions'  => $location->ad_options,
            'extensions' => $location->ad_options['file_extensions'],
            'multiple'   => false,
        ];

        return $this->view('', 'af_paidads_ad_edit', $viewParams);
    }

    public function actionSave(ParameterBag $params)
    {
        $this->assertPostOnly();

        // always force this until we support manually adding ads
        if ($params->ad_id || true)
        {
            $ad = $this->assertAdExists($params->ad_id);
        }
        else
        {
            $ad = $this->em()->create('AddonFlare\PaidAds:ad');
        }

        $this->adsSaveProcess($ad)->run();
        $this->rebuildDailyCache();

        return $this->redirect($this->getDynamicRedirect() . $this->buildLinkHash($ad->ad_id));
    }

    protected function adsSaveProcess($ads)
    {
        $form = $this->formAction();

        $input = $this->filter([
            'url' => 'str',
        ]);

        if (!($ads instanceof \XF\Mvc\Entity\ArrayCollection))
        {
            $ads = $this->em()->getBasicCollection([$ads]);
        }

        foreach ($ads as $ad)
        {
            $location = $ad->Location;

            $bannerService = $this->service('AddonFlare\PaidAds:Ad\Banner', $location, $ad);

            $upload = $this->request->getFile('upload', false, false);
            if ($upload)
            {
                if (!$bannerService->setImageFromUpload($upload))
                {
                    $form->logError($bannerService->getError(), 'upload');
                }
                else
                {
                    $form->apply(function() use ($bannerService)
                    {
                        $bannerService->updateBanner();
                    });
                }
            }
            else if (!$ad->banner_url)
            {
                $form->logError(\XF::phrase('uploaded_file_failed_not_found'), 'upload');
            }

            $form->basicEntitySave($ad, $input);
        }

        return $form;
    }

    public function actionEditMultiple()
    {
        $adIds = $this->filter('ad_ids', 'array-uint');

        $ads = $this->finder('AddonFlare\PaidAds:Ad')
            ->with('Location', true)
            ->where('ad_id', $adIds)
            ->fetch();

        $titles = $maxFileSize = $dimensions = $extensions = [];

        $adWidth = $adHeight = 0;

        $isFirst = true;

        foreach ($ads as $ad_id => $ad)
        {
            $location = $ad->Location;
            $adOptions = $location->ad_options;

            $title = (string) $location->title;

            if (!isset($titles[$title]))
            {
                $titles[$title] = 0;
            }
            $titles[$title]++;

            $dimensions["{$adOptions['ad_width']}x{$adOptions['ad_height']}"] = true;

            $adWidth  = $adOptions['ad_width'];
            $adHeight = $adOptions['ad_height'];

            if ($isFirst)
            {
                $extensions = $adOptions['file_extensions'];
                $maxFileSize = $adOptions['max_file_size'];
            }
            else
            {
                $extensions = array_intersect($extensions, $adOptions['file_extensions']);
                $maxFileSize = min($maxFileSize, $adOptions['max_file_size']);
            }

            $isFirst = false;
        }

        $dimensionsCount = count($dimensions);

        if (!$dimensionsCount)
        {
            return $this->error(\XF::phrase('af_paidads_please_select_at_least_one_ad_submit'));
        }
        else if ($dimensionsCount > 1)
        {
            return $this->error(\XF::phrase('af_paidads_different_dimensions_x', ['dimensions' => implode(' , ', array_keys($dimensions))]));
        }

        if ($this->filter('submit', 'bool'))
        {
            $this->adsSaveProcess($ads)->run();
            $this->rebuildDailyCache();
            return $this->redirect($this->getDynamicRedirect());
        }
        else
        {
            $titlesWithCount = [];
            foreach ($titles as $title => $titleCount)
            {
                $titlesWithCount[] = $titleCount > 1 ? "$title ({$titleCount})" : $title;
            }
            $viewParams = [
                'ad'         => [],
                'title'      => implode(', ', $titlesWithCount),
                'adOptions'  => [
                    'max_file_size' => $maxFileSize,
                    'ad_width'      => $adWidth,
                    'ad_height'     => $adHeight,
                ],
                'extensions' => $extensions,
                'multiple'   => true,
                'adIds'      => $adIds,
            ];

            return $this->view('', 'af_paidads_ad_edit', $viewParams);
        }
    }

    protected function assertAdExists($id, $with = null, $phraseKey = null)
    {
        $finder = $this->getAdRepo()->findAd($id);

        if ($with)
        {
            $finder->with($with);
        }

        $ad = $finder->fetchOne();

        if (!$ad)
        {
            if (!$phraseKey)
            {
                $phraseKey = 'requested_page_not_found';
            }

            throw $this->exception(
                $this->notFound(\XF::phrase($phraseKey))
            );
        }

        return $ad;
    }

    protected function getLocationRepo()
    {
        return $this->repository('AddonFlare\PaidAds:Location');
    }

    protected function getAdRepo()
    {
        return $this->repository('AddonFlare\PaidAds:Ad');
    }

    protected function getCartRepo()
    {
        return $this->repository('AddonFlare\PaidAds:Cart');
    }

    protected function rebuildDailyCache()
    {
        $this->getLocationRepo()->rebuildDailyCacheOnce();
    }
}