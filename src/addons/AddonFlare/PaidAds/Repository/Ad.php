<?php

namespace AddonFlare\PaidAds\Repository;

use XF\Mvc\Entity\Finder;
use XF\Mvc\Entity\Repository;
use XF\Mvc\Entity\AbstractCollection;

class Ad extends Repository
{
    protected static $loadedAds = [];

    public function findAdsForList($activeOrPendingOnly = false, $activeOnly = false)
    {
        $finder = $this->finder('AddonFlare\PaidAds:Ad')
            ->with('Location', true)
            ->with('Forum');

        if ($activeOrPendingOnly)
        {
            $finder->where('status', ['active', 'pending', 'rejected']);
        }
        else if ($activeOnly)
        {
            $finder->where('status', 'active');
        }

        return $finder;
    }

    public function findUserAd($adId, $activeOrPendingOnly = true, $activeOnly = false)
    {
        $finder = $this->findAdsForList($activeOrPendingOnly, $activeOnly)
            ->where('ad_id', $adId)
            ->where('user_id', \XF::visitor()->user_id);

        return $finder;
    }

    public function findAd($adId)
    {
        $finder = $this->findAdsForList()
            ->where('ad_id', $adId);

        return $finder;
    }

    public function rebuildAd(\AddonFlare\PaidAds\Entity\Ad $ad)
    {
        $adDays = $this->finder('AddonFlare\PaidAds:AdDay')
            ->where('ad_id', $ad->ad_id)
            ->order('date', 'ASC')
            ->fetch();

        $daysData = ['forum' => [], 'non_forum' => []];
        $totalDays = 0;

        foreach ($adDays as $adDay)
        {
            $daysData[$adDay->type][$adDay->date] = true;
            $totalDays++;
        }

        $ad->bulkSet([
            'total_days' => $totalDays,
            'days_data'  => $daysData,
        ]);

        $ad->save();

        $this->rebuildDailyCache();

        $this->em->clearEntityCache('AddonFlare\PaidAds:Ad');
    }

    public function addLoadedAd($adId)
    {
        // support for multiple
        if (!is_array($adId))
        {
            $adId = [$adId];
        }

        foreach ($adId as $value)
        {
            $value = intval($value);
            self::$loadedAds[$value] = $value;
        }
    }

    public function getLoadedAds()
    {
        return self::$loadedAds;
    }

    public function trackLoadedAds()
    {
        if (!empty(self::$loadedAds))
        {
            $cache = $this->getLocationRepo()->getDailyCache();

            $cookieName = 'afpaTrackView';
            $cookieData = $this->getJsonCookieData($cookieName);

            $adIdsToLogView = [];

            foreach (self::$loadedAds as $adId)
            {
                $ad = $cache['ads'][$adId];
                $locationId = $ad['location_id'];
                $location = $cache['locations'][$locationId];
                $miscOptions = $location['misc_options'];
                $threshold = $miscOptions['view_track_threshold'];

                if ($miscOptions['view_track_method'] == 'basic' && $this->checkCookieConds($cookieData, $adId, $threshold))
                {
                    $adIdsToLogView[] = $adId;
                }
            }

            if ($adIdsToLogView)
            {
                $this->logView($adIdsToLogView);
                $this->setJsonCookieData($cookieName, $cookieData, $cache);
            }
        }

        return self::$loadedAds; // irrelevant, but just returning something
    }

    public function checkCookieConds(&$cookieData, $adId, $threshold, $setCookie = true)
    {
        if (!$threshold)
        {
            // no threshold, so always process and skip checking/setting cookies
            return true;
        }

        if (isset($cookieData[$adId]))
        {
            $secondsBetween = $threshold * 60;
            $difference = \XF::$time - intval($cookieData[$adId]);

            if ($difference < $secondsBetween)
            {
                return false;
            }
        }

        if ($setCookie)
        {
            $cookieData[$adId] = \XF::$time;
        }

        return true;
    }

    public function getJsonCookieData($cookieName)
    {
        $app = $this->app();

        $cookieData = $app->request()->getCookie($cookieName);
        $cookieData = $app->inputFilterer()->filter($cookieData, 'json-array');

        return $cookieData;
    }

    public function setJsonCookieData($cookieName, $cookieData, $cache, $onlyActive = true)
    {
        if ($onlyActive)
        {
            // only keep current active ads
            $cookieData = array_intersect_key($cookieData, $cache['ads']);
        }

        asort($cookieData); // first expiring at beginning

        $this->app()->response()->setCookie($cookieName, json_encode($cookieData), 0, null, false);
    }

    public function logClick($adId)
    {
        $adClick = $this->em->create('AddonFlare\PaidAds:AdClick');
        $adClick->bulkSet([
            'ad_id'   => $adId,
            'date'    => \XF::$time,
            'user_id' => \XF::visitor()->user_id,
            'ip'      => \XF\Util\Ip::convertIpStringToBinary($this->app()->request()->getIp()),
        ]);
        $adClick->save();

        $this->db()->query("
            UPDATE xf_af_paidads_ad SET
                total_clicks = total_clicks + 1
            WHERE ad_id = ?
        ", [$adId]);
    }

    public function logView($adId)
    {
        if (empty($adId))
        {
            return false;
        }

        $db = $this->db();

        $db->query("
            UPDATE xf_af_paidads_ad SET
                total_views = total_views + 1
            WHERE ad_id IN (".$db->quote($adId).")
        ");
    }

    // expires ads and sends notifications
    public function expireAds(array $adIds)
    {
        $ads = $this->findAdsForList()
            ->with('User', true)
            ->where('ad_id', $adIds)
            ->fetch();

        $options = $this->options();

        $alertRepo = $this->repository('XF:UserAlert');

        foreach ($ads as $adId => $ad)
        {
            $ad->status = 'expired';
            $ad->save();

            $params = [
                'ad' => $ad,
            ];

            if (!empty($options->af_paidads_ad_expiry_notifications['emails']))
            {
                $this->app()->mailer()->newMail()
                    ->setToUser($ad->User)
                    ->setTemplate('af_paidads_ad_expired_notification', $params)
                    ->send();
            }
            if (!empty($options->af_paidads_ad_expiry_notifications['alerts']))
            {
                $alertRepo->alertFromUser(
                    $ad->User,
                    $ad->User,
                    'af_paidads_ad',
                    $ad->ad_id,
                    'expired'
                );
            }
        }

        // rebuilding daily cache isn't necessary since that will refresh on its own each day
    }

    public function sendDaysRemainingEmailNotifications(array $adIds)
    {
        $ads = $this->findAdsForList()
            ->with('User', true)
            ->where('ad_id', $adIds)
            ->fetch();

        $options = $this->options();
        $params = [
            'days' => $options->af_paidads_ad_daysremaining_email,
        ];

        foreach ($ads as $adId => $ad)
        {
            $params['ad'] = $ad;
            $this->app()->mailer()->newMail()
                ->setToUser($ad->User)
                ->setTemplate('af_paidads_ad_daysremaining_notification', $params)
                ->send();
        }
    }

    public function sendDaysRemainingAlertNotifications(array $adIds)
    {
        $ads = $this->findAdsForList()
            ->with('User', true)
            ->where('ad_id', $adIds)
            ->fetch();

        $options = $this->options();
        $params = [
            'days' => $options->af_paidads_ad_daysremaining_alert,
        ];

        $alertRepo = $this->repository('XF:UserAlert');

        foreach ($ads as $adId => $ad)
        {
            $alertRepo->alertFromUser(
                $ad->User,
                $ad->User,
                'af_paidads_ad',
                $ad->ad_id,
                'daysRemaining',
                $params
            );
        }
    }

    protected function rebuildDailyCache()
    {
        $this->getLocationRepo()->rebuildDailyCacheOnce();
    }

    protected function getLocationRepo()
    {
        return $this->repository('AddonFlare\PaidAds:Location');
    }
}