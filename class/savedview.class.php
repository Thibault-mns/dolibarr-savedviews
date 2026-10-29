<?php
/* Copyright (C) 2024-2026	TH Investissements / Matelas No Stress
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file        class/savedview.class.php
 * \ingroup     savedviews
 * \brief       Class for SavedView object
 */

/**
 * Class SavedView
 */
class SavedView
{
    /** Only visible to its author */
    const SCOPE_PRIVATE = 0;
    /** Visible to its author and to the members of the selected user groups */
    const SCOPE_USERGROUP = 1;
    /** Visible to every user of the entity */
    const SCOPE_PUBLIC = 2;

    /** Max length of the raw view_data JSON accepted from the browser */
    const VIEW_DATA_MAX_LENGTH = 32768;

    /**
     * Query parameters never stored in a view nor replayed when applying it:
     * a shared view is applied by other users, it must only ever carry list filters.
     */
    const FORBIDDEN_PARAMS = ['action', 'massaction', 'confirm', 'token', 'cancel', 'formfilteraction', 'toselect', 'sendto', 'backtopage'];

    /** @var DoliDB */
    public $db;

    /** @var string */
    public $error = '';

    /** @var array */
    public $errors = [];

    /** @var int */
    public $id;

    /** @var int */
    public $entity;

    /** @var int Author */
    public $fk_user;

    /** @var string Page URL (relative path) */
    public $page_url;

    /** @var string */
    public $label;

    /** @var int One of the SCOPE_* constants */
    public $scope = self::SCOPE_PRIVATE;

    /** @var int[] Ids of the user groups the view is shared with (SCOPE_USERGROUP) */
    public $usergroups = [];

    /** @var array View data (url, filters, selectedFields, displayMode) */
    public $view_data;

    /** @var int Position for ordering */
    public $position;

    /** @var int */
    public $date_creation;

    /** @var string Author full name, filled by fetchAllVisible() */
    public $author_name = '';

    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Create object into database
     *
     * @param User $user User that creates
     * @return int New ID >0 if OK, <0 if KO
     */
    public function create($user)
    {
        global $conf;

        $this->entity = $conf->entity;
        $this->fk_user = $user->id;
        $this->date_creation = dol_now();
        $this->scope = self::normalizeScope($this->scope);

        // Auto-calculate position: max existing position + 1 for this user+page
        $sqlPos = "SELECT MAX(position) as maxpos FROM " . MAIN_DB_PREFIX . "savedviews";
        $sqlPos .= " WHERE entity = " . (int) $this->entity;
        $sqlPos .= " AND fk_user = " . (int) $this->fk_user;
        $sqlPos .= " AND page_url = '" . $this->db->escape($this->page_url) . "'";
        $resPos = $this->db->query($sqlPos);
        $this->position = 0;
        if ($resPos) {
            $objPos = $this->db->fetch_object($resPos);
            $this->position = (int) ($objPos->maxpos ?? -1) + 1;
        }

        $sql = "INSERT INTO " . MAIN_DB_PREFIX . "savedviews (";
        $sql .= "entity, fk_user, page_url, label, scope, view_data, position, date_creation";
        $sql .= ") VALUES (";
        $sql .= (int) $this->entity;
        $sql .= ", " . (int) $this->fk_user;
        $sql .= ", '" . $this->db->escape($this->page_url) . "'";
        $sql .= ", '" . $this->db->escape($this->label) . "'";
        $sql .= ", " . (int) $this->scope;
        $sql .= ", '" . $this->db->escape(json_encode($this->view_data)) . "'";
        $sql .= ", " . (int) $this->position;
        $sql .= ", '" . $this->db->idate($this->date_creation) . "'";
        $sql .= ")";

        $this->db->begin();

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->error = $this->db->lasterror();
            $this->db->rollback();
            return -1;
        }

        $this->id = $this->db->last_insert_id(MAIN_DB_PREFIX . "savedviews");

        if ($this->saveUserGroups() < 0) {
            $this->db->rollback();
            return -1;
        }

        $this->db->commit();
        return $this->id;
    }

    /**
     * Load object in memory from database
     *
     * @param int $id ID
     * @return int >0 if OK, 0 if not found, <0 if KO
     */
    public function fetch($id)
    {
        global $conf;

        $sql = "SELECT rowid, entity, fk_user, page_url, label, scope, view_data, position, date_creation";
        $sql .= " FROM " . MAIN_DB_PREFIX . "savedviews";
        $sql .= " WHERE rowid = " . (int) $id;
        $sql .= " AND entity = " . (int) $conf->entity;

        $resql = $this->db->query($sql);
        if ($resql) {
            if ($this->db->num_rows($resql)) {
                $obj = $this->db->fetch_object($resql);
                $this->setFromRow($obj);
                return $this->fetchUserGroups() < 0 ? -1 : 1;
            }
            return 0;
        }

        $this->error = $this->db->lasterror();
        return -1;
    }

    /**
     * Update object into database
     * Permission checks are up to the caller (see canEdit()).
     *
     * @param User $user User that modifies
     * @return int >0 if OK, <0 if KO
     */
    public function update($user)
    {
        global $conf;

        $this->scope = self::normalizeScope($this->scope);

        $this->db->begin();

        $sql = "UPDATE " . MAIN_DB_PREFIX . "savedviews SET";
        $sql .= " label = '" . $this->db->escape($this->label) . "'";
        $sql .= ", scope = " . (int) $this->scope;
        $sql .= ", view_data = '" . $this->db->escape(json_encode($this->view_data)) . "'";
        $sql .= ", position = " . (int) $this->position;
        $sql .= " WHERE rowid = " . (int) $this->id;
        $sql .= " AND entity = " . (int) $conf->entity;

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->error = $this->db->lasterror();
            $this->db->rollback();
            return -1;
        }

        if ($this->saveUserGroups() < 0) {
            $this->db->rollback();
            return -1;
        }

        $this->db->commit();
        return 1;
    }

    /**
     * Delete object in database
     * Permission checks are up to the caller (see canEdit()).
     *
     * @param User $user User that deletes
     * @return int >0 if OK, <0 if KO
     */
    public function delete($user)
    {
        global $conf;

        $this->db->begin();

        $sql = "DELETE FROM " . MAIN_DB_PREFIX . "savedviews_usergroup";
        $sql .= " WHERE fk_savedview = " . (int) $this->id;
        if (!$this->db->query($sql)) {
            $this->error = $this->db->lasterror();
            $this->db->rollback();
            return -1;
        }

        $sql = "DELETE FROM " . MAIN_DB_PREFIX . "savedviews";
        $sql .= " WHERE rowid = " . (int) $this->id;
        $sql .= " AND entity = " . (int) $conf->entity;

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->error = $this->db->lasterror();
            $this->db->rollback();
            return -1;
        }

        $this->db->commit();
        return 1;
    }

    /**
     * Fetch the views of a page that a user can see: his own ones, plus the ones
     * shared with everybody or with one of his groups.
     * Single query (no N+1). Own views come first.
     *
     * @param User   $user    User
     * @param string $pageUrl Page URL filter (optional)
     * @return SavedView[]|int Array of SavedView objects, or -1 on DB error
     */
    public function fetchAllVisible($user, $pageUrl = '')
    {
        global $conf;

        $views = [];

        $sql = "SELECT sv.rowid, sv.entity, sv.fk_user, sv.page_url, sv.label, sv.scope, sv.view_data, sv.position, sv.date_creation,";
        $sql .= " u.firstname, u.lastname, u.login";
        $sql .= " FROM " . MAIN_DB_PREFIX . "savedviews as sv";
        $sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "user as u ON u.rowid = sv.fk_user";
        $sql .= " WHERE sv.entity = " . (int) $conf->entity;
        if (!empty($pageUrl)) {
            $sql .= " AND sv.page_url = '" . $this->db->escape($pageUrl) . "'";
        }
        $sql .= " AND " . self::sqlVisibleBy($user, 'sv');
        $sql .= " ORDER BY CASE WHEN sv.fk_user = " . (int) $user->id . " THEN 0 ELSE 1 END, sv.position ASC, sv.date_creation ASC";

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->error = $this->db->lasterror();
            return -1;
        }

        $groupIdsByView = [];
        while ($obj = $this->db->fetch_object($resql)) {
            $view = new SavedView($this->db);
            $view->setFromRow($obj);
            $view->author_name = trim($obj->firstname . ' ' . $obj->lastname) ?: (string) $obj->login;
            $views[$view->id] = $view;
            if ($view->scope == self::SCOPE_USERGROUP) {
                $groupIdsByView[$view->id] = [];
            }
        }

        // Groups of the shared-with-groups views, in one query
        if (!empty($groupIdsByView)) {
            $sql = "SELECT fk_savedview, fk_usergroup FROM " . MAIN_DB_PREFIX . "savedviews_usergroup";
            $sql .= " WHERE fk_savedview IN (" . implode(',', array_map('intval', array_keys($groupIdsByView))) . ")";
            $resql = $this->db->query($sql);
            if (!$resql) {
                $this->error = $this->db->lasterror();
                return -1;
            }
            while ($obj = $this->db->fetch_object($resql)) {
                $views[(int) $obj->fk_savedview]->usergroups[] = (int) $obj->fk_usergroup;
            }
        }

        return array_values($views);
    }

    /**
     * Whether a user may see (and apply) this view
     *
     * @param User $user User
     * @return bool
     */
    public function isVisibleBy($user)
    {
        global $conf;

        if ($this->fk_user == $user->id || $this->scope == self::SCOPE_PUBLIC) {
            return true;
        }
        if ($this->scope != self::SCOPE_USERGROUP) {
            return false;
        }

        $sql = "SELECT sv.rowid FROM " . MAIN_DB_PREFIX . "savedviews as sv";
        $sql .= " WHERE sv.rowid = " . (int) $this->id;
        $sql .= " AND sv.entity = " . (int) $conf->entity;
        $sql .= " AND " . self::sqlVisibleBy($user, 'sv');
        $resql = $this->db->query($sql);

        return $resql && $this->db->num_rows($resql) > 0;
    }

    /**
     * Whether a user may modify or delete this view: its author (with the 'create' right),
     * or a user holding the 'manage' right when the view is shared.
     * Private views stay private, 'manage' included.
     *
     * @param User $user User
     * @return bool
     */
    public function canEdit($user)
    {
        if ($this->fk_user == $user->id) {
            return (bool) $user->hasRight('savedviews', 'create');
        }

        return $this->scope != self::SCOPE_PRIVATE && $this->isVisibleBy($user) && $user->hasRight('savedviews', 'manage');
    }

    /**
     * Get view data as array for JSON response
     *
     * @param User|null $user Current user, adds the per-user flags (mine, can_edit, is_default)
     * @return array
     */
    public function toArray($user = null)
    {
        $data = [
            'id' => (int) $this->id,
            'label' => $this->label,
            'page_url' => $this->page_url,
            'scope' => (int) $this->scope,
            'usergroups' => array_map('intval', $this->usergroups),
            'view_data' => $this->view_data,
            'position' => (int) $this->position,
        ];

        if ($user !== null) {
            $data['mine'] = ($this->fk_user == $user->id);
            $data['author'] = $data['mine'] ? '' : $this->author_name;
            $data['can_edit'] = $this->canEdit($user);
            $data['is_default'] = (self::getDefaultId($user, $this->page_url) == $this->id);
        }

        return $data;
    }

    /**
     * Load the user groups of the view
     *
     * @return int >0 if OK, <0 if KO
     */
    private function fetchUserGroups()
    {
        $this->usergroups = [];

        $sql = "SELECT fk_usergroup FROM " . MAIN_DB_PREFIX . "savedviews_usergroup";
        $sql .= " WHERE fk_savedview = " . (int) $this->id;
        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->error = $this->db->lasterror();
            return -1;
        }
        while ($obj = $this->db->fetch_object($resql)) {
            $this->usergroups[] = (int) $obj->fk_usergroup;
        }

        return 1;
    }

    /**
     * Replace the user group links of the view. Links are only kept for SCOPE_USERGROUP.
     * Must be called inside a transaction.
     *
     * @return int >0 if OK, <0 if KO
     */
    private function saveUserGroups()
    {
        $sql = "DELETE FROM " . MAIN_DB_PREFIX . "savedviews_usergroup";
        $sql .= " WHERE fk_savedview = " . (int) $this->id;
        if (!$this->db->query($sql)) {
            $this->error = $this->db->lasterror();
            return -1;
        }

        if ($this->scope != self::SCOPE_USERGROUP) {
            $this->usergroups = [];
            return 1;
        }

        $this->usergroups = array_values(array_unique(array_filter(array_map('intval', (array) $this->usergroups))));
        foreach ($this->usergroups as $groupId) {
            $sql = "INSERT INTO " . MAIN_DB_PREFIX . "savedviews_usergroup (fk_savedview, fk_usergroup)";
            $sql .= " VALUES (" . (int) $this->id . ", " . (int) $groupId . ")";
            if (!$this->db->query($sql)) {
                $this->error = $this->db->lasterror();
                return -1;
            }
        }

        return 1;
    }

    /**
     * Fill properties from a database row
     *
     * @param object $obj Row
     * @return void
     */
    private function setFromRow($obj)
    {
        $this->id = (int) $obj->rowid;
        $this->entity = (int) $obj->entity;
        $this->fk_user = (int) $obj->fk_user;
        $this->page_url = $obj->page_url;
        $this->label = $obj->label;
        $this->scope = (int) $obj->scope;
        $this->view_data = json_decode($obj->view_data, true);
        $this->position = (int) $obj->position;
        $this->date_creation = $this->db->jdate($obj->date_creation);
        $this->usergroups = [];
    }

    /**
     * SQL condition selecting the views a user can see
     *
     * @param User   $user  User
     * @param string $alias Alias of the savedviews table
     * @return string
     */
    private static function sqlVisibleBy($user, $alias)
    {
        global $conf;

        $sql = "(" . $alias . ".fk_user = " . (int) $user->id;
        $sql .= " OR " . $alias . ".scope = " . self::SCOPE_PUBLIC;
        $sql .= " OR (" . $alias . ".scope = " . self::SCOPE_USERGROUP . " AND EXISTS (";
        $sql .= "SELECT 1 FROM " . MAIN_DB_PREFIX . "savedviews_usergroup as svg";
        $sql .= " INNER JOIN " . MAIN_DB_PREFIX . "usergroup_user as ugu ON ugu.fk_usergroup = svg.fk_usergroup";
        $sql .= " WHERE svg.fk_savedview = " . $alias . ".rowid";
        $sql .= " AND ugu.fk_user = " . (int) $user->id;
        // Same filter as User::getrights() on group membership
        $sql .= " AND ugu.entity IN (0, " . (int) $conf->entity . ")";
        $sql .= ")))";

        return $sql;
    }

    /**
     * @param mixed $scope Raw scope
     * @return int A valid SCOPE_* value, private when unknown
     */
    public static function normalizeScope($scope)
    {
        $scope = (int) $scope;
        return in_array($scope, [self::SCOPE_PRIVATE, self::SCOPE_USERGROUP, self::SCOPE_PUBLIC], true) ? $scope : self::SCOPE_PRIVATE;
    }

    /**
     * User groups that can be picked to share a view
     *
     * @param DoliDB $db Database handler
     * @return array List of ['id' => int, 'label' => string]
     */
    public static function getUserGroupOptions($db)
    {
        $groups = [];

        $sql = "SELECT rowid, nom as name FROM " . MAIN_DB_PREFIX . "usergroup";
        $sql .= " WHERE entity IN (" . getEntity('usergroup') . ")";
        $sql .= " ORDER BY nom ASC";
        $resql = $db->query($sql);
        if ($resql) {
            while ($obj = $db->fetch_object($resql)) {
                $groups[] = ['id' => (int) $obj->rowid, 'label' => $obj->name];
            }
        }

        return $groups;
    }

    /**
     * Name of the user parameter holding the default view of a page
     *
     * @param string $pageUrl Page key
     * @return string
     */
    public static function getDefaultParamName($pageUrl)
    {
        return 'SAVEDVIEWS_DEFAULT_' . md5($pageUrl);
    }

    /**
     * Id of the default view chosen by a user for a page (not checked for visibility)
     *
     * @param User   $user    User
     * @param string $pageUrl Page key
     * @return int 0 when none
     */
    public static function getDefaultId($user, $pageUrl)
    {
        $name = self::getDefaultParamName($pageUrl);
        return empty($user->conf->$name) ? 0 : (int) $user->conf->$name;
    }

    /**
     * Set or clear the default view of a page for a user
     *
     * @param DoliDB $db      Database handler
     * @param User   $user    User
     * @param string $pageUrl Page key
     * @param int    $viewId  View id, 0 to clear
     * @return int >0 if OK, <0 if KO
     */
    public static function setDefaultId($db, $user, $pageUrl, $viewId)
    {
        global $conf;

        require_once DOL_DOCUMENT_ROOT . '/core/lib/functions2.lib.php';

        $name = self::getDefaultParamName($pageUrl);
        return dol_set_user_param($db, $conf, $user, [$name => ($viewId > 0 ? (string) (int) $viewId : '')]);
    }

    /**
     * Whether a query parameter name may be stored in a view or replayed from it
     *
     * @param string $name Raw parameter name (may end with [] or [key])
     * @return bool
     */
    public static function isAllowedParam($name)
    {
        if (!preg_match('/^[A-Za-z0-9_\-\.]+(\[[A-Za-z0-9_\-\.]*\])?$/', $name)) {
            return false;
        }
        $base = strtolower(preg_replace('/\[.*$/', '', $name));

        return !in_array($base, self::FORBIDDEN_PARAMS, true) && strpos($base, 'button_') !== 0;
    }

    /**
     * Keep only the list state of a view: its URL reduced to a same-page relative URL
     * without any action parameter, its filters, columns and display mode.
     * Also used on stored views before applying them (views saved by older versions).
     *
     * @param mixed  $data    Decoded view_data
     * @param string $pageUrl Page key (path relative to DOL_URL_ROOT, plus "?type=X")
     * @return array
     */
    public static function sanitizeViewData($data, $pageUrl)
    {
        $clean = ['url' => '', 'filters' => [], 'selectedFields' => '', 'displayMode' => 'common'];
        if (!is_array($data)) {
            return $clean;
        }

        if (isset($data['displayMode']) && is_string($data['displayMode']) && preg_match('/^[a-z0-9]{1,32}$/i', $data['displayMode'])) {
            $clean['displayMode'] = $data['displayMode'];
        }

        if (isset($data['selectedFields']) && is_string($data['selectedFields'])) {
            $clean['selectedFields'] = substr(preg_replace('/[^A-Za-z0-9_\.,\-]/', '', $data['selectedFields']), 0, 4000);
        }

        if (isset($data['filters']) && is_array($data['filters'])) {
            foreach ($data['filters'] as $name => $value) {
                if (count($clean['filters']) >= 200 || !self::isAllowedParam((string) $name)) {
                    continue;
                }
                if (is_array($value)) {
                    $values = [];
                    foreach ($value as $v) {
                        if (is_scalar($v)) {
                            $values[] = substr((string) $v, 0, 1000);
                        }
                    }
                    $clean['filters'][$name] = array_slice($values, 0, 200);
                } elseif (is_scalar($value)) {
                    $clean['filters'][$name] = substr((string) $value, 0, 1000);
                }
            }
        }

        // URL: only the path of the page itself is kept, never a scheme or host
        if (isset($data['url']) && is_string($data['url'])) {
            $path = (string) parse_url($data['url'], PHP_URL_PATH);
            $query = (string) parse_url($data['url'], PHP_URL_QUERY);
            $pagePath = (string) strtok($pageUrl, '?');
            if ($pagePath !== '' && $path !== '' && $path[0] === '/' && strpos($path, '..') === false
                && substr($path, -strlen($pagePath)) === $pagePath) {
                $pairs = [];
                foreach (explode('&', $query) as $pair) {
                    if ($pair === '') {
                        continue;
                    }
                    $name = urldecode(strtok($pair, '='));
                    if (self::isAllowedParam($name)) {
                        $pairs[] = $pair;
                    }
                }
                $clean['url'] = $path . (empty($pairs) ? '' : '?' . implode('&', $pairs));
            }
        }

        return $clean;
    }

    /**
     * Relative URL that applies a view, columns included. Same logic as applyViewState() in js/savedviews.js.
     *
     * @param array $viewData Sanitized view data
     * @return string Empty when the view holds nothing to apply
     */
    public static function buildApplyUrl($viewData)
    {
        if (empty($viewData['url'])) {
            return '';
        }

        $url = $viewData['url'];
        if (!empty($viewData['selectedFields']) && !preg_match('/[?&]selectedfields=/', $url)) {
            $url .= (strpos($url, '?') === false ? '?' : '&') . 'selectedfields=' . urlencode($viewData['selectedFields']) . '&formfilteraction=listafterchangingselectedfields';
        }

        return strpos($url, '?') === false ? '' : $url;
    }
}
