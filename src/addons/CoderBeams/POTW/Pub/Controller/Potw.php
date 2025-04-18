<?php

namespace CoderBeams\POTW\Pub\Controller;

use XF\Mvc\ParameterBag;
use XF\Pub\Controller\AbstractController;

class Potw extends AbstractController
{
    public function actionIndex(ParameterBag $params)
    {
        $visitor = \XF::visitor();
        $options = $this->options();

        $config = $this->getConfigFromOptions($options, $params);
        $weekService = new \CoderBeams\POTW\Service\Week($this);

        list($allPosts, $weekendArray) = $weekService->processWeeklyPosts(
            $visitor,
            $config
        );

        return $this->buildViewResponse(
            $allPosts,
            $weekendArray,
            $config
        );
    }

    protected function getConfigFromOptions(\XF\Options $options, ParameterBag $params): array
    {
        return [
            'page' => $this->filterPage($params->page),
            'perPage' => $options->cb_potw_per_page,
            'timeLapse' => $options->cb_time_lapse,
            'nodeIds' => $options->cb_potw_applicable_forum,
            'minimumReaction' => $options->cb_potw_reaction_limit,
            'postsInWeeks' => $options->cb_limit_post_per_week,
            'lastWeeks' => $options->cb_posts_weeks,
        ];
    }

    protected function buildViewResponse(
        array $allPosts,
        array $weekendArray,
        array $config
    ) {
        $paginatedPosts = array_slice(
            $allPosts,
            ($config['page'] - 1) * $config['perPage'],
            $config['perPage']
        );

        $viewParams = [
            'allPosts' => $paginatedPosts,
            'perPage' => $config['perPage'],
            'page' => $config['page'],
            'total' => count($allPosts),
            'weekendArray' => $weekendArray,
            'showExpandedTitle' => '',
        ];

        return $this->view('', 'cb_potw_index', $viewParams);
    }
}