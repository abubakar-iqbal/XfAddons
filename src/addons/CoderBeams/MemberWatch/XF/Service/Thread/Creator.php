<?php
namespace CoderBeams\MemberWatch\XF\Service\Thread;

class Creator extends XFCP_Creator
{
    public function sendNotifications()
    {
        $parent=parent::sendNotifications();
        if ($this->thread->isVisible()) {
            $notifier = $this->service('XF:Post\Notifier', $this->post, 'thread');
            $userIds = \XF::app()->repository('CoderBeams\MemberWatch:MemberWatch')->getNotifyUserIds($this->thread->user_id, 'thread',$this->thread->node_id);
           // $userIds=$this->getFilterUserIds($userIds,$this->thread->node_id);
            // if (!empty($userIds) && !(self::canView())) {
                if (!empty($userIds)) {

                $notifier->addNotifications('forumWatch', $userIds,true);
                $notifier->notifyAndEnqueue(3);
            }
        }
        return $parent;
    }

    // public static function canView()
    // {
    //     $groups = \XF::options()['foroBetaContactsValidGroups'];
    //     $isEnabled = \XF::options()['foroBetaContactsIsEnabled'];
    //     $visitor = \XF::visitor();

    //     if (
    //         $visitor->user_id &&
    //         ($visitor->Profile->custom_fields->whatsapp || $visitor->Profile->custom_fields->skype || $visitor->Profile->custom_fields->telegram || $visitor->Profile->custom_fields->discord) &&
    //         $visitor->isMemberOf($groups) &&
    //         $isEnabled
    //     ) {
    //         return true;
    //     }

    //     return false;
    // }
}