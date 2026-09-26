<?php

use Migrations\AbstractMigration;

/**
 * Saved views on the task pages.
 *
 * A saved view is a named snapshot of one task page's filters, sort, grouping
 * and layout. Views belong to one page (`page` is the TASK_VIEWS_CONFIG.page
 * key), because a Kanban board and a grouped list want different settings.
 * Each view is private to the user who saved it; at most one per page is the
 * user's default, which the page applies when it opens.
 *
 * Not stored in user_preferences: that table caps a value at 8 KB, and a list
 * of views would share one value and one limit.
 */
class CreateSavedViews extends AbstractMigration
{
    public function change(): void
    {
        $this->table('saved_views')
            ->addColumn('company_id', 'integer', ['null' => false])
            ->addColumn('user_id', 'integer', ['null' => false])
            ->addColumn('page', 'string', ['limit' => 32, 'null' => false])
            ->addColumn('name', 'string', ['limit' => 100, 'null' => false])
            ->addColumn('state', 'text', ['null' => false])
            ->addColumn('is_default', 'boolean', ['default' => false, 'null' => false])
            ->addColumn('created', 'timestamp', ['null' => false])
            ->addColumn('modified', 'timestamp', ['null' => false])
            ->addIndex(
                ['company_id', 'user_id', 'page'],
                ['name' => 'saved_views_owner_page']
            )
            ->create();
    }
}
