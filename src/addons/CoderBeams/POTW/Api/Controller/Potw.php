<?php

namespace CoderBeams\POTW\Api\Controller;
use XF\Api\Controller\AbstractController;
use XF\Mvc\ParameterBag;

class Potw extends AbstractController
{
    protected function preDispatchController($action, ParameterBag $params)
    {
        $this->assertApiScopeByRequestMethod('thread');
    }
    public function actionGet(ParameterBag $params)
    {
        $visitor = \XF::visitor();

        $page = $this->filterPage($params->page);
        $perPage=20;
        $options = $this->options();
        $timeLapse = $options->cb_time_lapse;
        $nodeIds = $options->cb_potw_applicable_forum;
        $minimumReaction = $options->cb_potw_reaction_limit;
        $postsInWeeks = $options->cb_limit_post_per_week;
        $lastWeeks = $options->cb_posts_weeks;
        /** @var \XF\Finder\Post $postFinder */

        $weekendArray = [];
        $allPosts = [];
        switch ($timeLapse) {
            case 'week':

                for ($i = 1; $i <= $lastWeeks; $i++) {
                    $postFinder = $this->finder('XF:Post');
                    $postFinder
                      ->with(['Thread.Forum.Node.Permissions|' . $visitor->permission_combination_id, 'User'])
                        ->where('Thread.discussion_state', 'visible')
                        ->where('message_state', 'visible')
                        ->where('reaction_score', '>=', $minimumReaction)
                        ->setDefaultOrder('reaction_score', 'DESC')
                        ->limitByPage(1, $postsInWeeks);

                    $weekend = $this->getWeekMonSun(-$i);
                    
                    $postFinder = $postFinder
                        ->where('post_date', '>=', $weekend['start'])
                        ->where('post_date', '<=', $weekend['end']);

                    if ($nodeIds && !(in_array(0, $nodeIds))) {
                        $postFinder->where('Thread.Forum.Node.node_id', $nodeIds);
                    }
                    $postFinder = $postFinder->forFullView();

                    if (count($postFinder->fetch())) {
                        $posts = $postFinder->fetch();
                        $j = 1;
                        foreach ($posts as $key => $post) {

                            if ($j == 1) {
                                $weekendArray[$weekend['end']] = $post->post_id;
                                // $post->message=$post->Thread->title;
                                $allPosts[] = $post;
                            } else {
                                // $allPosts[]=$post->toArray();
                                $allPosts[] = $post;
                            }
                            $j++;
                        }
                    }
                }
        }

        $start = $page * $perPage - $perPage;
        $allPosts = array_slice($allPosts, $start, $perPage);

        return $this->apiResult(['post' => $allPosts]);
    }

    public function getWeekMonSun($weekOffset)
	{
		$visitor = \xF::visitor();
		$dt = new \DateTime("now", new \DateTimeZone($visitor['timezone']));
		$dt->setIsoDate($dt->format('o'), $dt->format('W') + $weekOffset);
		$lastSunday=strtotime("- 1 Day",$dt->getTimestamp());
		return array(
			'start' => $lastSunday,
			'end' => $dt->modify('+5 day')->getTimestamp(),
		);
	}
}
