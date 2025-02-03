<?php

namespace AddonFlare\PaidAds\Cron;

class CleanUp
{
    public static function runHourlyCleanUp($entry)
    {
        $app = \XF::app();

        // should never happen but incase
        if (empty($entry['next_run'])) return;

        $dateTime = new \DateTime('@' . $entry['next_run'], new \DateTimeZone('UTC'));
        $runHour = strval($dateTime->format('G'));

        // only run at midnight or if manually called through admin panel
        if ($runHour !== '0' AND $app->container('app.classType') != 'Admin')
        {
            return;
        }

        self::runAdExpiry();
    }

    protected static function runAdExpiry()
    {
        $app = \XF::app();

        $dateTime = new \DateTime('@' . \XF::$time, new \DateTimeZone('UTC'));
        $dateToday = $dateTime->format('Y-m-d');

        $db = $app->db();

        $adsDaysRemaining = $db->fetchPairs("
            SELECT ad.ad_id, COUNT(ad_days.date) AS days_remaining
            FROM xf_af_paidads_ad ad
            LEFT JOIN
            (
                SELECT ad_day.ad_id, ad_day.date
                FROM xf_af_paidads_ad_day ad_day
                WHERE
                    ad_day.date >= ?
                GROUP BY ad_day.ad_id, ad_day.date
            ) AS ad_days ON (ad_days.ad_id = ad.ad_id)
            WHERE
                ad.status = ?
            GROUP BY ad.ad_id
        ", [$dateToday, 'active']);

        $expiredAdIds = $daysEmailNotifications = $daysAlertNotifications = [];

        foreach ($adsDaysRemaining as $adId => $daysRemaining)
        {
            if (!$daysRemaining)
            {
                $expiredAdIds[$adId] = $adId;
                continue;
            }

            if ($daysRemaining == $app->options()->af_paidads_ad_daysremaining_email)
            {
                $daysEmailNotifications[$adId] = $adId;
            }

            if ($daysRemaining == $app->options()->af_paidads_ad_daysremaining_alert)
            {
                $daysAlertNotifications[$adId] = $adId;
            }
        }

        $adRepo = $app->repository('AddonFlare\PaidAds:Ad');

        if ($expiredAdIds)
        {
            $adRepo->expireAds($expiredAdIds);
        }

        if ($daysEmailNotifications)
        {
            $adRepo->sendDaysRemainingEmailNotifications($daysEmailNotifications);
        }

        if ($daysAlertNotifications)
        {
            $adRepo->sendDaysRemainingAlertNotifications($daysAlertNotifications);
        }
    }
}