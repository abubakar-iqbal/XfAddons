<?php

namespace CoderBeams\POTW\Service;

use XF\Entity\User;
use XF\Finder\Post as PostFinder;
use XF\Mvc\Controller;

class Week
{
    /** @var Controller */
    protected $controller;

    public function __construct(Controller $controller)
    {
        $this->controller = $controller;
    }

    public function processWeeklyPosts(
        User $visitor,
        array $config
    ): array {
        $allPosts = [];
        $weekendArray = [];

        if ($config['timeLapse'] !== 'week') {
            return [[], []];
        }

        for ($i = 1; $i <= $config['lastWeeks']; $i++) {
            $weekPosts = $this->getPostsForWeek(
                $visitor,
                -$i,
                $config['minimumReaction'],
                $config['postsInWeeks'],
                $config['nodeIds']
            );
            if (!empty($weekPosts)) {
                $weekIdentifier = $this->getWeekIdentifier(-$i, $visitor);
                foreach ($weekPosts as $post) {
                    $weekendArray[$weekIdentifier] = $post->post_id ?? null;
                    break;
                }
            
                $allPosts = array_merge($allPosts, $weekPosts);
            }
        }

        return [$allPosts, $weekendArray];
    }

    protected function getPostsForWeek(
        User $visitor,
        int $weekOffset,
        int $minimumReaction,
        int $postsLimit,
        array $nodeIds
    ): array {
        $weekRange = $this->getWeekRange($weekOffset, $visitor);

        $postFinder = $this->controller->finder('XF:Post');
        $postFinder
            ->with($this->getPostWithClauses($visitor))
            ->where('Thread.discussion_state', 'visible')
            ->where('message_state', 'visible')
            ->where('reaction_score', '>=', $minimumReaction)
            ->where('post_date', '>=', $weekRange['start'])
            ->where('post_date', '<=', $weekRange['end'])
            ->setDefaultOrder('reaction_score', 'DESC')
            ->limit($postsLimit);

        if (!empty($nodeIds) && !in_array(0, $nodeIds)) {
            $postFinder->where('Thread.Forum.Node.node_id', $nodeIds);
        }

        $posts = $this->withFullViews($postFinder)->fetch();

        return $posts ? $posts->toArray() : [];
    }

    public function getWeekRange(int $weekOffset, User $user): array
    {
        $dt = new \DateTime("now", new \DateTimeZone($user['timezone']));
        $dt->setISODate($dt->format('o'), $dt->format('W') + $weekOffset);
        $lastSunday = strtotime("-1 day", $dt->getTimestamp());

        return [
            'start' => $lastSunday,
            'end' => $dt->modify('+5 days')->getTimestamp(),
        ];
    }

    protected function getPostWithClauses(User $visitor): array
    {
        return [
            'Thread.Forum.Node.Permissions|'.$visitor->permission_combination_id,
            'User',
        ];
    }

    protected function withFullViews(PostFinder $finder): PostFinder
    {
        return $finder->with('full');
    }

    public function getWeekIdentifier(int $weekOffset, User $user): string
    {
        $range = $this->getWeekRange($weekOffset, $user);

        return (string)$range['end'];
    }
}