<?php
namespace CoderBeams\MemberWatch\XF\Entity;

class User extends XFCP_User
{
    public function isWatching($user)
    {
        $exists = $this->em()->findOne('CoderBeams\MemberWatch:MemberWatch', [
            'user_id' => \XF::visitor()->user_id,
            'watch_user_id' => $user->user_id
        ]);

        return ($exists) ? true:false;
    }
    public function getInterestType()
    {
        $interestType=$this->finder('CoderBeams\MemberWatch:MemberWatch')->where(['watch_user_id'=>$this->user_id,'user_id'=>\XF::visitor()->user_id])->fetchOne();
        if(!is_null($interestType))
        {
            return $interestType->interest_type;
        }
    
    }
    public function getFollower()
    {
        return $this->finder('CoderBeams\MemberWatch:MemberWatch')->where('watch_user_id', $this->user_id)->total();
    }
    public function getFollowing()
    {
        return  $this->finder('CoderBeams\MemberWatch:MemberWatch')->where('user_id', $this->user_id)->total();    
    }
    public function getFollowerLimit()
    {
        $followerLimit=\XF::app()->options->cb_min_follower_to_show;
        if(!empty($followerLimit))
        {
            $totalFollowers=$this->finder('CoderBeams\MemberWatch:MemberWatch')->where('watch_user_id', $this->user_id)->total();
            if($followerLimit && $totalFollowers >=$followerLimit)
            {
                return true;
            }
            else{
                return false;
            }
        }
        else{
       
            return true;
        }
       
    }
}

