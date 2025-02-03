<?php

namespace AddonFlare\PaidAds\XF\Admin\Controller;

class Index extends XFCP_Index
{
    public function actionIndex()
    {
        $reply = parent::actionIndex();

        if ($reply instanceof \XF\Mvc\Reply\View)
        {
            $pendingAds = $this->repository('AddonFlare\PaidAds:Ad')->findAdsForList()
                ->where('status', 'pending')
                ->where('url', '!=', '')
                ->fetch();

            $reply->setParam('pendingAdsCount', $pendingAds->count());
        }

        return $reply;
    }
}