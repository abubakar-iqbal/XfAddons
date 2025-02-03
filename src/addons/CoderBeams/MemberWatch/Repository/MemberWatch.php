<?php
namespace CoderBeams\MemberWatch\Repository;
use XF\Mvc\Entity\Repository;

class MemberWatch extends Repository
{
    public function getNotifyUserIds($watchedUserId,$type,$forumId=null)
    {

        $records=$this->finder('CoderBeams\MemberWatch:MemberWatch')->where('watch_user_id','=',$watchedUserId)->with('User')->with('Option')->fetch();
        $isModArea=\XF::options()->cb_mod_forum;
        if(in_array($forumId,$isModArea))
        {
            return [];
        }
        if(count($records)) {
            foreach ($records as $user) {
                if($user->interest_type=='business')
                {
                    $businessForumsIds=\xf::options()->cb_business_forum;
                    if(in_array($forumId,$businessForumsIds))
                    {
                        //if user enable to getting alerts of watch members.
                        if(!is_null($user->Option)) {
                            if ($user->Option->doesReceiveAlert('user', 'watched_member_post_' . $type)) {
                                $userIds[] = $user->user_id;
                            }
                        }
                    }
                }
                elseif($user->interest_type=='normal')
                {
                    $normalForumsIds=\xf::options()->cb_normal_forum;
                    if(in_array($forumId,$normalForumsIds))
                    {
                        //if user enable to getting alerts of watch members.
                        if(!is_null($user->Option)) {
                            if ($user->Option->doesReceiveAlert('user', 'watched_member_post_' . $type)) {
                                $userIds[] = $user->user_id;
                            }
                        }
                    }
                }
                else{
                     //if user enable to getting alerts of watch members.
                     if(!is_null($user->Option)) {
                        if ($user->Option->doesReceiveAlert('user', 'watched_member_post_' . $type)) {
                            $userIds[] = $user->user_id;
                        }
                    }
                }
                
            }
            return isset($userIds) ? $userIds:[];
        }
        return [];
    }
}