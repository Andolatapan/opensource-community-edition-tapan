<?php

/**
 * Orangescrum Community Edition
 *
 * Copyright (c) 2026 Andolasoft Inc.
 *
 * This program is free software: you can redistribute it and/or modify it
 * under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or (at your
 * option) any later version.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
 * FITNESS FOR A PARTICULAR PURPOSE. See the GNU Affero General Public License
 * for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Model\Table;

use Cake\Log\Log;
use Cake\ORM\Table;

/**
 * SavedViews Model
 *
 * Named filter/sort/layout snapshots for the task pages, private to the user
 * who saved them.
 *
 * Every lookup takes the owner (company + user) alongside the id. Nothing here
 * finds a row by id alone, so a caller cannot read, change or delete another
 * user's view by guessing its id.
 *
 * Reads fail soft, like UserPreferencesTable: an instance that pulled the code
 * without running the migration has no `saved_views` table, and that must cost
 * the user their saved views, not the task page.
 *
 * @method \App\Model\Entity\SavedView newEmptyEntity()
 * @method \App\Model\Entity\SavedView patchEntity(\Cake\Datasource\EntityInterface $entity, array $data, array $options = [])
 * @method \App\Model\Entity\SavedView|false save(\Cake\Datasource\EntityInterface $entity, $options = [])
 *
 * @mixin \Cake\ORM\Behavior\TimestampBehavior
 */
class SavedViewsTable extends Table
{
    /** The task pages that have a filter toolbar, and so can save a view. */
    public const PAGES = ['views', 'myworks', 'subtasks', 'kanban', 'calendar'];

    public const MAX_NAME_LENGTH = 100;

    /** Longest encoded state accepted, so a client cannot fill the column. */
    public const MAX_STATE_BYTES = 8192;

    /** Views per user per page. A menu longer than this stops being usable. */
    public const MAX_PER_PAGE = 50;

    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('saved_views');
        $this->setDisplayField('name');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');
    }

    /**
     * One page's views for one user, in the shape the task-views bundle reads.
     *
     * @return array<int, array{id: int, name: string, state: array, isDefault: bool}>
     */
    public function listFor(int $companyId, int $userId, string $page): array
    {
        try {
            $rows = $this->find()
                ->select(['id', 'name', 'state', 'is_default'])
                ->where([
                    'company_id' => $companyId,
                    'user_id' => $userId,
                    'page' => $page,
                ])
                ->order(['name' => 'ASC', 'id' => 'ASC'])
                ->all();
        } catch (\Exception $e) {
            Log::warning('SavedViews list failed: ' . $e->getMessage());

            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $state = json_decode($row->state, true);
            $out[] = [
                'id' => (int)$row->id,
                'name' => (string)$row->name,
                'state' => is_array($state) ? $state : [],
                'isDefault' => (bool)$row->is_default,
            ];
        }

        return $out;
    }

    /**
     * A view, only if it belongs to this user and page.
     */
    public function findOwned(int $companyId, int $userId, string $page, int $id): ?\App\Model\Entity\SavedView
    {
        return $this->find()
            ->where([
                'id' => $id,
                'company_id' => $companyId,
                'user_id' => $userId,
                'page' => $page,
            ])
            ->first();
    }

    public function countFor(int $companyId, int $userId, string $page): int
    {
        return $this->find()
            ->where([
                'company_id' => $companyId,
                'user_id' => $userId,
                'page' => $page,
            ])
            ->count();
    }

    /**
     * Whether the user already has a view with this name on this page.
     * Case-insensitive, because "Overdue" and "overdue" side by side in one
     * menu read as a duplicate.
     */
    public function nameTaken(int $companyId, int $userId, string $page, string $name, ?int $exceptId = null): bool
    {
        $query = $this->find()
            ->where([
                'company_id' => $companyId,
                'user_id' => $userId,
                'page' => $page,
            ])
            ->where(function ($exp, $q) use ($name) {
                return $exp->eq($q->func()->lower(['name' => 'identifier']), mb_strtolower($name));
            });

        if ($exceptId !== null) {
            $query->where(['id !=' => $exceptId]);
        }

        return $query->count() > 0;
    }

    /**
     * Make one view the page's default, or clear the default when $id is null.
     * Clearing the others first keeps "at most one default per page" true
     * without a partial unique index, which not every database supports.
     */
    public function setDefault(int $companyId, int $userId, string $page, ?int $id): void
    {
        $owner = [
            'company_id' => $companyId,
            'user_id' => $userId,
            'page' => $page,
        ];

        $this->getConnection()->transactional(function () use ($owner, $id) {
            $this->updateAll(['is_default' => false], $owner);
            if ($id !== null) {
                $this->updateAll(['is_default' => true], $owner + ['id' => $id]);
            }
        });
    }
}
