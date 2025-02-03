<?php
namespace CoderBeams\MemberWatch\XF\Alert;
use XF\Entity\UserAlert;
use XF\Mvc\Entity\Entity;
class User extends XFCP_User
{
    public function getOptOutActions()
    {
        $parent=parent::getOptOutActions();
        $memberWatchedAlerts=['watch','watched_member_post_thread','watched_member_post_reply'];
        $parent=array_merge($parent,$memberWatchedAlerts);
        return $parent;
    }
}