<?php


namespace CoderBeams\MemberWatch\XF\Service\Thread;


class Replier extends XFCP_Replier
{
    public function sendNotifications()
    {
        $parent=parent::sendNotifications();
        if ($this->post->isVisible() && \XF::options()->cb_reply_alert_enable)
        {
            $notifier = $this->service('XF:Post\Notifier', $this->post, 'reply');
            $userIds = \XF::app()->repository('CoderBeams\MemberWatch:MemberWatch')->getNotifyUserIds($this->thread->user_id, 'reply',$this->thread->node_id);
            if (!empty($userIds)) {
                $notifier->addNotifications('threadWatch', $userIds);
                $notifier->notifyAndEnqueue(3);
                // $notifier->sendNotifications();
            }
        }

        return $parent;
    }
}