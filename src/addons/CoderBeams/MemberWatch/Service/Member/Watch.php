<?php

namespace CoderBeams\MemberWatch\Service\Member;
use AllowDynamicProperties;

use XF\Service\AbstractService;

class Watch extends AbstractService
{
    /**
     * @var \XF\Entity\User
     */
    protected $watchedBy;

    /**
     * @var \XF\Entity\User
     */
    protected $watchUser;

    protected $silent = false;
    protected $interestType;

    /**
     * @param \XF\App $app
     * @param \XF\Entity\User $watchUser
     * @param \XF\Entity\User|null $watchedBy
     * @param $interestType
     */
    public function __construct(\XF\App $app, \XF\Entity\User $watchUser, \XF\Entity\User $watchedBy = null,$interestType=null)
    {
        parent::__construct($app);

        $this->watchUser = $watchUser;
        $this->watchedBy = $watchedBy ?: \XF::visitor();
        $this->interestType=$interestType;
    }

    /**
     * @param $silent
     * @return void
     */
    public function setSilent($silent)
    {
        $this->silent = (bool)$silent;
    }
    

    public function watch()
    {
        $interestType= $this->app->request->filter('interest_type', 'str');
        $memberWatch = $this->em()->create('CoderBeams\MemberWatch:MemberWatch');
        $memberWatch->user_id = $this->watchedBy->user_id;
        $memberWatch->watch_user_id = $this->watchUser->user_id;
        $memberWatch->interest_type=$interestType;
        try
        {
            $saved = $memberWatch->save(false);
        }
        catch (\XF\Db\DuplicateKeyException $e)
        {
            $saved = false;

            $dupe = $this->em()->findOne('CoderBeams\MemberWatch:MemberWatch', [
                'user_id' => $this->watchedBy->user_id,
                'watch_user_id' => $this->watchUser->user_id
            ]);
            if ($dupe)
            {
                $memberWatch = $dupe;
            }
        }

        if ($saved)
        {
            $this->sendFollowingAlert();
        }

        return $memberWatch;
    }

    protected function sendFollowingAlert()
    {
        if ($this->silent)
        {
            return;
        }

        $watchedBy = $this->watchedBy;
        $watchUser = $this->watchUser;

        if (!$watchUser->isIgnoring($watchedBy->user_id)
            && $watchUser->Option->doesReceiveAlert('user', 'watch')
        )
        {
            /** @var \XF\Repository\UserAlert $alertRepo */
            $alertRepo = $this->repository('XF:UserAlert');
            $alertRepo->alert(
                $watchUser, $watchedBy->user_id, $watchedBy->username, 'user', $watchUser->user_id, 'watching'
            );
        }
    }

    public function unwatch()
    {
        $memberWatch = $this->em()->findOne('CoderBeams\MemberWatch:MemberWatch', [
            'user_id' => $this->watchedBy->user_id,
            'watch_user_id' => $this->watchUser->user_id
        ]);

        if ($memberWatch && $memberWatch->delete())
        {
            $this->deleteFollowingAlert();
        }

        return $memberWatch;
    }

    protected function deleteFollowingAlert()
    {
        $alertRepo = $this->repository('XF:UserAlert');
        $alertRepo->fastDeleteAlertsFromUser(
            $this->watchedBy->user_id, 'user', $this->watchUser->user_id, 'watching'
        );
    }
}