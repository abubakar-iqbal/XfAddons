<?php

namespace AddonFlare\PaidAds;

use XF\Mvc\Entity\Entity;

class Listener
{
    public static function appSetup(\XF\App $app)
    {
        $container = $app->container();

        $container['afpaidads.daily'] = $app->fromRegistry('afpaidadsDaily',
            function(\XF\Container $c)
            {
                return $c['em']->getRepository('AddonFlare\PaidAds:Location')->rebuildDailyCache();
            },
            function($cache, \XF\Container $c)
            {
                $dateTime = new \DateTime('@' . \XF::$time, new \DateTimeZone('UTC'));
                $dateToday = $dateTime->format('Y-m-d');

                if (!isset($cache['date']) || $cache['date'] != $dateToday)
                {
                    $cache = $c['em']->getRepository('AddonFlare\PaidAds:Location')->rebuildDailyCache();
                }

                return $cache;
            }
        );
    }

    public static function appPubComplete(\XF\Pub\App $app, \XF\Http\Response &$response)
    {
        $app->repository('AddonFlare\PaidAds:Ad')->trackLoadedAds();
    }

    public static function adminOptionControllerPostDispatch(\XF\Mvc\Controller $controller, $action, \XF\Mvc\ParameterBag $params, \XF\Mvc\Reply\AbstractReply &$reply)
    {
        if ($params['group_id'] == 'af_paidads_general')
        {
            $reply->setSectionContext('af_paidads_options_gen');
        }
        else if ($params['group_id'] == 'af_paidads_purchase')
        {
            $reply->setSectionContext('af_paidads_options_pur');
        }
        else if ($params['group_id'] == 'af_paidads_management')
        {
            $reply->setSectionContext('af_paidads_options_manage');
        }
    }

    const ID = 'AddonFlare/PaidAds';
    const TITLE = 'Paid Ads';
    const ID_NUM = '087408522c31eeb1f982bc0eaf81d35f';
    public static $IDS1 = [
        97, 102, 95, 112, 97, 105, 100, 97, 100, 115, 95, 122,
    ];
}