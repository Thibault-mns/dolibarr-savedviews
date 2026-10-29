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
 * \file        class/actions_savedviews.class.php
 * \ingroup     savedviews
 * \brief       Hook actions for SavedViews module
 */

/**
 * Class ActionsSavedviews
 */
class ActionsSavedviews
{
    /**
     * @var DoliDB Database handler
     */
    public $db;

    /**
     * @var string Error message
     */
    public $error = '';

    /**
     * @var array Errors messages
     */
    public $errors = [];

    /**
     * @var string HTML output for hook
     */
    public $resprints;

    /**
     * @var array Results array
     */
    public $results = [];

    /**
     * Constructor
     *
     * @param DoliDB $db Database handler
     */
    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Hook on doActions - open the default view of a list when the user lands on it.
     * Done server-side with a redirect so the unfiltered list is not computed first.
     *
     * @param array $parameters Hook parameters
     * @param object $object Object
     * @param string $action Action
     * @param HookManager $hookmanager Hook manager
     * @return int 0=OK, negative on error
     */
    public function doActions($parameters, &$object, &$action, $hookmanager)
    {
        global $user;

        // A list calls doActions once, but some pages run several hook contexts
        static $alreadychecked = false;
        if ($alreadychecked) {
            return 0;
        }
        $alreadychecked = true;

        if (empty($user->id) || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET' || headers_sent()
            || !$user->hasRight('savedviews', 'read')) {
            return 0;
        }

        // Only a bare arrival on the list: any other parameter (filter, sort, page,
        // action, restore_lastsearch_values...) means the user is already working on it
        $allowedKeys = ['mainmenu', 'leftmenu', 'idmenu', 'type', 'contextpage'];
        foreach (array_keys($_GET) as $key) {
            if (!in_array($key, $allowedKeys, true)) {
                return 0;
            }
        }

        dol_include_once('/savedviews/class/savedview.class.php');

        $currentPage = $this->getCurrentPageUrl();
        $defaultId = SavedView::getDefaultId($user, $currentPage);
        if ($defaultId <= 0) {
            return 0;
        }

        // Coming from the same list (menu entry clicked from the list itself, filters
        // just reset...): the user wants the plain list, not the default view again
        $refererPath = (string) parse_url($_SERVER['HTTP_REFERER'] ?? '', PHP_URL_PATH);
        $currentPath = (string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
        if ($refererPath !== '' && $refererPath === $currentPath) {
            return 0;
        }

        $savedView = new SavedView($this->db);
        if ($savedView->fetch($defaultId) <= 0 || $savedView->page_url !== $currentPage || !$savedView->isVisibleBy($user)) {
            return 0;
        }

        $url = SavedView::buildApplyUrl(SavedView::sanitizeViewData($savedView->view_data, $currentPage));
        // The stored path must be the requested page itself
        if ($url === '' || parse_url($url, PHP_URL_PATH) !== $currentPath) {
            return 0;
        }

        // Keep the menu context of the request
        foreach (['mainmenu', 'leftmenu'] as $key) {
            $value = GETPOST($key, 'aZ09');
            if ($value !== '' && !preg_match('/[?&]' . $key . '=/', $url)) {
                $url .= '&' . $key . '=' . urlencode($value);
            }
        }

        header('Location: ' . $url);
        exit;
    }

    /**
     * Hook on printCommonFooter - inject JS/CSS on all pages
     *
     * @param array $parameters Hook parameters
     * @param object $object Object
     * @param string $action Action
     * @param HookManager $hookmanager Hook manager
     * @return int 0=OK, negative on error
     */
    public function printCommonFooter($parameters, &$object, &$action, $hookmanager)
    {
        global $conf, $user, $langs;

        // The hook can be called more than once on a page: inject the payload only once
        static $alreadyinjected = false;
        if ($alreadyinjected) {
            return 0;
        }

        // Permissions. 'read' is required to see and apply views, 'create' to save new ones,
        // 'manage' to modify the views shared by other users.
        // Note: rights are stored in llx_rights_def when the module is enabled, so an
        // upgraded install must be disabled/re-enabled to get them.
        if (empty($user->id) || !$user->hasRight('savedviews', 'read')) {
            return 0;
        }

        $canCreate = $user->hasRight('savedviews', 'create') ? 1 : 0;
        $canManage = $user->hasRight('savedviews', 'manage') ? 1 : 0;

        $alreadyinjected = true;

        $langs->load('savedviews@savedviews');

        dol_include_once('/savedviews/class/savedview.class.php');

        $currentPage = $this->getCurrentPageUrl();

        $savedView = new SavedView($this->db);
        $views = $savedView->fetchAllVisible($user, $currentPage);

        $viewsData = [];
        if (is_array($views)) {
            foreach ($views as $view) {
                $viewsData[] = $view->toArray($user);
            }
        }

        // Groups are only needed to share a view
        $userGroups = ($canCreate || $canManage) ? SavedView::getUserGroupOptions($this->db) : [];

        $labelKeys = [
            'saveView' => 'SaveView',
            'editView' => 'EditView',
            'viewName' => 'ViewName',
            'viewScope' => 'ViewScope',
            'scopePrivate' => 'ViewScopePrivate',
            'scopeUserGroup' => 'ViewScopeUserGroup',
            'scopePublic' => 'ViewScopePublic',
            'userGroups' => 'ViewUserGroups',
            'isDefault' => 'ViewIsDefault',
            'isDefaultHelp' => 'ViewIsDefaultHelp',
            'replaceWithCurrent' => 'ViewReplaceWithCurrent',
            'sharedBy' => 'ViewSharedBy',
            'defaultView' => 'DefaultView',
            'readOnlyView' => 'ViewReadOnly',
            'deleteView' => 'DeleteView',
            'viewActions' => 'ViewActions',
            'menuEdit' => 'ViewMenuEdit',
            'menuDelete' => 'ViewMenuDelete',
            'setDefault' => 'ViewSetDefault',
            'unsetDefault' => 'ViewUnsetDefault',
            'sharedWithAll' => 'ViewSharedWithAll',
            'sharedWithGroups' => 'ViewSharedWithGroups',
            'currentViewModified' => 'ViewCurrentModified',
            'updateWithCurrent' => 'ViewUpdateWithCurrent',
            'confirmDelete' => 'ConfirmDeleteView',
            'viewSaved' => 'ViewSaved',
            'viewDeleted' => 'ViewDeleted',
            'nameRequired' => 'ViewNameRequired',
            'groupRequired' => 'ViewGroupRequired',
            'save' => 'Save',
            'cancel' => 'Cancel',
            'delete' => 'Delete',
            'error' => 'Error',
        ];
        $labels = [];
        foreach ($labelKeys as $jsKey => $transKey) {
            $labels[$jsKey] = $langs->transnoentitiesnoconv($transKey);
        }

        $config = [
            'token' => currentToken() ?: newToken(),
            'ajaxUrl' => dol_buildpath('/savedviews/ajax/savedviews.php', 1),
            'currentPage' => $currentPage,
            'canCreate' => $canCreate,
            'canManage' => $canManage,
            // Same switch as core tooltips (core/js/lib_foot.js.php): native titles only
            'noMouseHover' => empty($conf->dol_no_mouse_hover) ? 0 : 1,
            'views' => $viewsData,
            'userGroups' => $userGroups,
            'labels' => $labels,
        ];

        $cssFile = dol_buildpath('/savedviews/css/savedviews.css', 0);
        $jsFile = dol_buildpath('/savedviews/js/savedviews.js', 0);
        $cssVersion = file_exists($cssFile) ? filemtime($cssFile) : time();
        $jsVersion = file_exists($jsFile) ? filemtime($jsFile) : time();

        echo '<link rel="stylesheet" href="' . dol_buildpath('/savedviews/css/savedviews.css', 1) . '?v=' . $cssVersion . '">';
        // Tooltip themes (jquery-tooltip --dark-mode) only exist in core from Dolibarr 24
        if (version_compare(DOL_VERSION, '24.0.0', '<')) {
            $backportCss = '/savedviews/backport/v24/theme/tooltips.css';
            echo '<link rel="stylesheet" href="' . dol_buildpath($backportCss, 1) . '?v=' . ((int) @filemtime(dol_buildpath($backportCss, 0))) . '">';
        }

        // HEX flags: the JSON is printed inside a <script> block
        $jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_PARTIAL_OUTPUT_ON_ERROR;
        if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
            $jsonFlags |= JSON_INVALID_UTF8_SUBSTITUTE;
        }
        // Nonce for the Content Security Policy (getNonce() exists from Dolibarr 18)
        $nonceAttr = function_exists('getNonce') ? ' nonce="' . getNonce() . '"' : '';

        echo '<script' . $nonceAttr . '>window.savedviews_config = ' . json_encode($config, $jsonFlags) . ';</script>';

        echo '<script' . $nonceAttr . ' src="' . dol_buildpath('/savedviews/js/savedviews.js', 1) . '?v=' . $jsVersion . '"></script>';

        return 0;
    }

    /**
     * Get current page key used to attach saved views.
     * Path relative to DOL_URL_ROOT, plus the structural 'type' param when present:
     * several distinct lists share the same path and differ only by type
     * (product/list.php?type=0|1, societe/list.php?type=c|p|f, ...).
     *
     * @return string
     */
    private function getCurrentPageUrl()
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '';

        $parsed = parse_url($uri);
        $path = $parsed['path'] ?? '';

        if (defined('DOL_URL_ROOT') && DOL_URL_ROOT !== '' && strpos($path, DOL_URL_ROOT) === 0) {
            $path = substr($path, strlen(DOL_URL_ROOT));
        }
        // Legacy prefixes (installs serving htdocs under /htdocs or /dolibarr with an
        // empty/mismatched DOL_URL_ROOT) — must stay stripped so existing keys keep matching
        $path = preg_replace('#^/htdocs#', '', $path);
        $path = preg_replace('#^/dolibarr#i', '', $path);

        $type = GETPOST('type', 'alphanohtml');
        if ($type !== '' && $type !== null) {
            $path .= '?type=' . $type;
        }

        return $path;
    }
}
