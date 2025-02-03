<?php

namespace AddonFlare\PaidAds\Pub\Controller;

use XF\Mvc\ParameterBag;
use XF\Mvc\FormAction;
use XF\Mvc\Reply\View;
use XF\Pub\Controller\AbstractController;

class Tracker extends AbstractController
{
    protected $cache;
    protected $ad = null; // for single ad_id actions
    protected $ads = []; // for multiple ad_ids actions

    protected function preDispatchController($action, ParameterBag $params)
    {
        $this->cache = $cache = $this->getLocationRepo()->getDailyCache();

        if (strpos(strtolower($action), 'multiple') !== false)
        {
            $adIds = $this->filter('ad_ids', 'array-uint');

            foreach ($adIds as $adId)
            {
                if (isset($cache['ads'][$adId]))
                {
                    $this->ads[$adId] = $cache['ads'][$adId];
                }
            }
        }
        else
        {
            $adId = $params->ad_id;

            if (isset($cache['ads'][$adId]))
            {
                $this->ad = $cache['ads'][$adId];
            }
        }

        if ((!$this->ad && !$this->ads) || !$this->request->isPost() || !$this->request->isXhr() || !$this->isValidReferrer())
        {
            throw $this->exception($this->view());
        }
    }

    public function actionClick(ParameterBag $params)
    {
        $adRepo = $this->getAdRepo();

        $cookieName = 'afpaTrackClick';
        $cookieData = $adRepo->getJsonCookieData($cookieName);

        $adId = $this->ad['ad_id'];
        $locationId = $this->ad['location_id'];
        $location = $this->cache['locations'][$locationId];
        $miscOptions = $location['misc_options'];
        $threshold = $miscOptions['click_track_threshold'];

        if ($adRepo->checkCookieConds($cookieData, $adId, $threshold))
        {
            $adRepo->logClick($adId);
            $adRepo->setJsonCookieData($cookieName, $cookieData, $this->cache);
        }

        $view = $this->view();
        return $view;
    }

    public function actionView(ParameterBag $params)
    {
        $adRepo = $this->getAdRepo();

        $cookieName = 'afpaTrackView';
        $cookieData = $adRepo->getJsonCookieData($cookieName);

        $adId = $this->ad['ad_id'];
        $locationId = $this->ad['location_id'];
        $location = $this->cache['locations'][$locationId];
        $miscOptions = $location['misc_options'];
        $threshold = $miscOptions['view_track_threshold'];

        if ($miscOptions['view_track_method'] == 'scroll' && $adRepo->checkCookieConds($cookieData, $adId, $threshold))
        {
            $adRepo->logView($adId);
            $adRepo->setJsonCookieData($cookieName, $cookieData, $this->cache);
        }

        $view = $this->view();
        return $view;
    }

    public function actionViewMultiple()
    {
        $adRepo = $this->getAdRepo();

        $cookieName = 'afpaTrackView';
        $cookieData = $adRepo->getJsonCookieData($cookieName);

        $adIdsToLogView = [];

        foreach ($this->ads as $adId => $ad)
        {
            $locationId = $ad['location_id'];
            $location = $this->cache['locations'][$locationId];
            $miscOptions = $location['misc_options'];
            $threshold = $miscOptions['view_track_threshold'];

            if ($miscOptions['view_track_method'] == 'real' && $adRepo->checkCookieConds($cookieData, $adId, $threshold))
            {
                $adIdsToLogView[] = $adId;
            }
        }

        if ($adIdsToLogView)
        {
            $adRepo->logView($adIdsToLogView);
            $adRepo->setJsonCookieData($cookieName, $cookieData, $this->cache);
        }

        $view = $this->view();
        return $view;
    }

    protected function isValidReferrer()
    {
        $referrer = $this->filter('referrer', 'str');

        $referrerParts = @parse_url($referrer);
        if (!$referrerParts || empty($referrerParts['host']))
        {
            return false;
        }

        $requestParts = @parse_url($this->request->getFullRequestUri());
        if (!$requestParts || empty($requestParts['host']))
        {
            return false;
        }

        return ($requestParts['host'] === $referrerParts['host']);
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
}