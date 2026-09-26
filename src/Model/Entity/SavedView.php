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

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * SavedView Entity
 *
 * @property int $id
 * @property int $company_id
 * @property int $user_id
 * @property string $page
 * @property string $name
 * @property string $state
 * @property bool $is_default
 * @property \Cake\I18n\FrozenTime $created
 * @property \Cake\I18n\FrozenTime $modified
 */
class SavedView extends Entity
{
    /**
     * The owner and page are set by TaskViewsController from the session and
     * the page whitelist, never from request data, so they stay out of mass
     * assignment. So does is_default, which only SavedViewsTable::setDefault()
     * writes.
     *
     * @var array<string, bool>
     */
    protected $_accessible = [
        'name' => true,
        'state' => true,
    ];
}
