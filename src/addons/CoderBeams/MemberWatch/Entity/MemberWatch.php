<?php

namespace CoderBeams\MemberWatch\Entity;

use XF\Mvc\Entity\Entity;
use XF\Mvc\Entity\Structure;

/**
 * COLUMNS
 * @property int user_id
 * @property int watch_user_id
 * @property int watch_date
 *
 * RELATIONS
 * @property \XF\Entity\User User
 * @property \XF\Entity\User WatchUser
 */
class MemberWatch extends Entity
{
    protected function _preSave()
    {
        if ($this->isInsert())
        {
            if ($this->user_id == $this->watch_user_id)
            {
                $this->error(\XF::phrase('you_may_not_watch_yourself'));
            }

            $exists = $this->em()->findOne('CoderBeams\MemberWatch:MemberWatch', [
                'user_id' => $this->user_id,
                'watch_user_id' => $this->watch_user_id
            ]);
            if ($exists)
            {
                $this->error(\XF::phrase('you_already_watching_this_member'));
            }

        }
    }



    public static function getStructure(Structure $structure)
    {
        $structure->table = 'cb_user_watch';
        $structure->shortName = 'CoderBeams\MemberWatch:MemberWatch';
        $structure->primaryKey = ['user_id', 'watch_user_id'];
        $structure->columns = [
            'user_id' => ['type' => self::UINT, 'required' => true],
            'watch_user_id' => ['type' => self::UINT, 'required' => true],
            'interest_type' => ['type' => self::STR,'default'=>'all'],
            'watch_date' => ['type' => self::UINT, 'default' => \XF::$time]
        ];
        $structure->getters = [];
        $structure->relations = [
            'User' => [
                'entity' => 'XF:User',
                'type' => self::TO_ONE,
                'conditions' => 'user_id',
                'primary' => true
            ],
            'MemberWatch' => [
                'entity' => 'XF:User',
                'type' => self::TO_ONE,
                'conditions' => [['user_id', '=', '$watch_user_id']],
                'primary' => true
            ],
            'Option' => [
        'entity' => 'XF:UserOption',
        'type' => self::TO_ONE,
        'conditions' => 'user_id',
        'primary' => true
    ],
        ];

        return $structure;
    }

}