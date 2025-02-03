<?php

namespace CoderBeams\MemberWatch\XF\Pub\Controller;

use XF\Mvc\Entity\Finder;
use XF\Mvc\FormAction;
use XF\Mvc\ParameterBag;
class Member extends XFCP_Member
{
    /**
     * @param ParameterBag $params
     * @return mixed
     */
    
     public function actionWatchedTo(ParameterBag $params)
    {
        $page = $this->filterPage($params->page);
        $user=$this->assertViewableUser($params->user_id);
	    $perPage = $this->options()->membersPerPage;
		//$perPage = 1;

        // $finder=\XF::db()->query('SELECT *
        // FROM cb_user_watch
        // LEFT JOIN xf_user
        // ON  cb_user_watch.watch_user_id=xf_user.user_id
        // where cb_user_watch.user_id=1;')->fetchAll();
        $finder= $this->finder('CoderBeams\MemberWatch:MemberWatch')->where('user_id', $params->user_id)->with('MemberWatch')
        ->limitByPage($page, $perPage);

        $total=$finder->total();
        $viewParams = [
			'followers' => $finder->fetch(),
			'total' => $total,
			'page' => $page,
            'user'=>$user,
			'perPage' => $perPage
		];
		return $this->view('XF:Member\Listing', 'cb_following_list', $viewParams);

    }
    
    public function actionWatched(ParameterBag $params)
    {
        $page = $this->filterPage($params->page);
        $user=$this->assertViewableUser($params->user_id);
	    $perPage = $this->options()->membersPerPage;
        $finder= $this->finder('CoderBeams\MemberWatch:MemberWatch')->where('watch_user_id', $params->user_id)->with('User')
        ->limitByPage($page, $perPage);
        $total=$finder->total();
        $viewParams = [
			'followers' => $finder->fetch(),
			'total' => $total,
			'page' => $page,
            'user'=>$user,
			'perPage' => $perPage
		];
		return $this->view('XF:Member\Listing', 'cb_followers_list', $viewParams);

    }
    
     public function actionWatch(ParameterBag $params)
    {

        $user = $this->assertViewableUser($params->user_id, [], true);
        $visitor = \XF::visitor();
        $wasWatching = $visitor->isWatching($user);
        
       
        $redirect = $this->getDynamicRedirect(null, false);
        if($this->isPost()) {
            $intrestType=$this->filter('interest_type','str');

            $watchService = $this->setupWatchService($user,$intrestType);

            if ($wasWatching) {
                $userWatch = $watchService->unWatch();
            } else {
                $userWatch = $watchService->watch();
            }
            if ($userWatch && $userWatch->hasErrors()) {
                return $this->error($userWatch->getErrors());
            }
            $reply = $this->redirect($redirect);
            return $this->redirect($redirect);
            //$reply->setJsonParam('switchKey', $wasWatching ? 'watch' : 'unwatch');
       
        }
        $viewParams = [
            'user' => $user,
            'redirect' => $redirect,
            'isWatching' => $wasWatching
        ];
        return $this->view('XF:Member\Watch', 'cb_member_watch', $viewParams);
        return $reply;
    }
    /**
     * @param User $watchUser
     *
     * @return \XF\Service\User\Follow
     */
    protected function setupWatchService(\XF\Entity\User $followUser)
    {
        return $this->service('CoderBeams\MemberWatch:Member\Watch', $followUser);
    }
}