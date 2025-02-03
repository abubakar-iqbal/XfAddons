<?php

namespace CoderBeams\MemberWatch;

use XF\AddOn\AbstractSetup;
use XF\AddOn\StepRunnerInstallTrait;
use XF\AddOn\StepRunnerUninstallTrait;
use XF\AddOn\StepRunnerUpgradeTrait;
use XF\Db\Schema\Create;
use XF\Db\Schema\Alter;
class Setup extends AbstractSetup
{
    use StepRunnerInstallTrait;
    use StepRunnerUpgradeTrait;
    use StepRunnerUninstallTrait;


    public function installStep1(array $stepParams = [])
    {
        $this->schemaManager()->createTable('cb_user_watch', function (Create $table) {
            $table->addColumn('user_id', 'int');
            $table->addColumn('watch_user_id', 'int')->comment('User being watched');
            $table->addColumn('watch_date', 'int')->setDefault(0);
            $table->addColumn('interest_type','text')->setDefault('all');
            $table->addPrimaryKey(['user_id', 'watch_user_id']);
            $table->addKey('watch_user_id');
        });
    }
    public function upgrade5Step1()
    {
        $this->schemaManager()->alterTable('cb_user_watch', function(Alter $table)
		{
            $table->addColumn('interest_type','text')->setDefault('all');
		}); 
    }
    public function uninstallStep1(array $stepParams = [])
    {
        $this->schemaManager()->dropTable('cb_user_watch');
    }
}
