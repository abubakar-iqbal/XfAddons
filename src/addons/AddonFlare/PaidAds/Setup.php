<?php

namespace AddonFlare\PaidAds;

use XF\AddOn\AbstractSetup;
use XF\AddOn\StepRunnerInstallTrait;
use XF\AddOn\StepRunnerUninstallTrait;
use XF\AddOn\StepRunnerUpgradeTrait;

use XF\Db\Schema\Alter;
use XF\Db\Schema\Create;

class Setup extends AbstractSetup
{
	use StepRunnerInstallTrait;
	use StepRunnerUpgradeTrait;
	use StepRunnerUninstallTrait;

    ### INSTALL ###

    public function installStep1()
    {
        $sm = $this->schemaManager();

        foreach ($this->getTables() AS $tableName => $closure)
        {
            $sm->createTable($tableName, $closure);
        }

        if (!\XF::em()->find('XF:Purchasable', 'af_paidads_cart'))
        {
            $purchasable = \XF::em()->create('XF:Purchasable');
            $purchasable->bulkSet([
                'purchasable_type_id' => 'af_paidads_cart',
                'purchasable_class'   => 'AddonFlare\PaidAds:Cart',
                'addon_id'            => 'AddonFlare/PaidAds'
            ]);
            $purchasable->save();
        }
    }

    ### UNINSTALL ###

    public function uninstallStep1()
    {
        $sm = $this->schemaManager();

        foreach (array_keys($this->getTables()) AS $tableName)
        {
            $sm->dropTable($tableName);
        }

        \XF\Util\File::deleteAbstractedDirectory('data://addonflare/pa');
        \XF::registry()->delete(['afpaidadsDaily']);

        if ($purchasable = \XF::em()->find('XF:Purchasable', 'af_paidads_cart'))
        {
            $purchasable->delete();
        }
    }

    protected function getTables()
    {
        $tables = [];

        $tables['xf_af_paidads_ad'] = function(Create $table)
        {
            $table->addColumn('ad_id', 'int')->autoIncrement();
            $table->addColumn('location_id', 'int');
            $table->addColumn('user_id', 'int');
            $table->addColumn('node_id', 'int');
            $table->addColumn('status', 'varchar', 25)->setDefault('');
            $table->addColumn('create_date', 'int');
            $table->addColumn('total_days', 'int');
            $table->addColumn('days_data', 'mediumblob');
            $table->addColumn('type', 'varchar', 25)->setDefault('');
            $table->addColumn('url', 'varchar', 2083)->setDefault('');
            $table->addColumn('upload_date', 'int');
            $table->addColumn('upload_extension', 'varchar', 5)->setDefault('');
            $table->addColumn('total_clicks', 'int');
            $table->addColumn('total_views', 'int');

            $table->addKey('location_id');
            $table->addKey('user_id');
            $table->addKey('node_id');
            $table->addKey('status');
        };

        $tables['xf_af_paidads_ad_click'] = function(Create $table)
        {
            $table->addColumn('ad_id', 'int');
            $table->addColumn('date', 'int');
            $table->addColumn('user_id', 'int');
            $table->addColumn('ip', 'varbinary', 16)->setDefault('');

            $table->addKey('ad_id');
            $table->addKey('date');
        };

        $tables['xf_af_paidads_ad_day'] = function(Create $table)
        {
            $table->addColumn('ad_id', 'int');
            $table->addColumn('date', 'date');
            $table->addColumn('type', 'varchar', 25)->setDefault('');

            $table->addPrimaryKey(['ad_id', 'date', 'type']);
        };

        $tables['xf_af_paidads_cart'] = function(Create $table)
        {
            $table->addColumn('cart_id', 'int')->autoIncrement();
            $table->addColumn('user_id', 'int');
            $table->addColumn('total_amount', 'decimal', '10,2');
            $table->addColumn('total_items', 'int');
            $table->addColumn('create_date', 'int');
            $table->addColumn('last_update', 'int');
            $table->addColumn('in_transaction', 'tinyint', 3)->setDefault(0);
            $table->addColumn('is_paid', 'tinyint', 3)->setDefault(0);
            $table->addColumn('paid_data', 'mediumblob');

            $table->addKey('user_id');
            $table->addKey('in_transaction');
            $table->addKey('is_paid');
        };

        $tables['xf_af_paidads_cart_ad'] = function(Create $table)
        {
            $table->addColumn('cart_ad_id', 'int')->autoIncrement();
            $table->addColumn('location_id', 'int');
            $table->addColumn('user_id', 'int');
            $table->addColumn('node_id', 'int');
            $table->addColumn('cart_id', 'int');
            $table->addColumn('status', 'varchar', 25)->setDefault('');
            $table->addColumn('create_date', 'int');
            $table->addColumn('total_days_forum', 'int');
            $table->addColumn('total_days_non_forum', 'int');
            $table->addColumn('total_amount_forum', 'decimal', '10,2');
            $table->addColumn('total_amount_non_forum', 'decimal', '10,2');
            $table->addColumn('type', 'varchar', 25)->setDefault('');
            $table->addColumn('existing_ad_id', 'int')->nullable();

            $table->addKey('location_id');
            $table->addKey('user_id');
            $table->addKey('node_id');
            $table->addKey('cart_id');
            $table->addKey('status');
            $table->addKey('create_date');
        };

        $tables['xf_af_paidads_cart_ad_day'] = function(Create $table)
        {
            $table->addColumn('cart_ad_id', 'int');
            $table->addColumn('date', 'date');
            $table->addColumn('type', 'varchar', 25)->setDefault('');

            $table->addPrimaryKey(['cart_ad_id', 'date', 'type']);
        };

        $tables['xf_af_paidads_location'] = function(Create $table)
        {
            $table->addColumn('location_id', 'int')->autoIncrement();
            $table->addColumn('position_id', 'varbinary', 50)->setDefault('');
            $table->addColumn('active', 'tinyint', 3);
            $table->addColumn('display_order', 'int');
            $table->addColumn('ad_type', 'varchar', 100)->setDefault('');
            $table->addColumn('ad_options', 'mediumblob');
            $table->addColumn('display_criteria', 'mediumblob');
            $table->addColumn('can_purchase', 'tinyint', 3);
            $table->addColumn('can_purchase_forum', 'tinyint', 3);
            $table->addColumn('can_purchase_non_forum', 'tinyint', 3);
            $table->addColumn('max_rotations_forum', 'int');
            $table->addColumn('max_rotations_non_forum', 'int');
            $table->addColumn('purchase_user_group_ids', 'blob');
            $table->addColumn('purchase_options', 'mediumblob');
            $table->addColumn('misc_options', 'mediumblob');

            $table->addKey('position_id');
            $table->addKey('active');
        };

        return $tables;
    }
}