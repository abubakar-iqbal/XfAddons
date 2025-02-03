<?php

namespace AddonFlare\PaidAds\Alert;

use XF\Mvc\Entity\Entity;

class Ad extends \XF\Alert\AbstractHandler
{
    public function canViewContent(Entity $entity, &$error = null)
    {
        return true;
    }

    public function getEntityWith()
    {
        return ['Location'];
    }
}
