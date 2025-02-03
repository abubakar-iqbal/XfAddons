<?php

namespace AddonFlare\PaidAds\XF\Entity;

use XF\Mvc\Entity\Structure;

class User extends XFCP_User
{
    public function canHidePaidAds(&$error = null)
    {
        return $this->isMemberOf($this->app()->options()->af_paidads_ugs_excluded_view_ads);
    }
    public function canPurchasePaidAds(&$error = null)
    {
        return $this->isMemberOf($this->app()->options()->af_paidads_ugs_allowed_purchase);
    }
}