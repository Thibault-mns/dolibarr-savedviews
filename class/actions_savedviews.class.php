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

        // Permissions. 'read' is required to see and apply views, 'create' to manage them.
        // Note: rights are stored in llx_rights_def when the module is enabled, so an
        // install upgraded from 1.0.x must be disabled/re-enabled to get them.
        if (empty($user->id) || !$user->hasRight('savedviews', 'read')) {
            return 0;
        }

        $canCreate = $user->hasRight('savedviews', 'create') ? 1 : 0;

        $alreadyinjected = true;

        $langs->load('savedviews@savedviews');

        dol_include_once('/savedviews/class/savedview.class.php');

        $currentPage = $this->getCurrentPageUrl();

        $savedView = new SavedView($this->db);
        $views = $savedView->fetchAllForUser($user->id, $currentPage);

        $viewsData = [];
        if (is_array($views)) {
            foreach ($views as $view) {
                $viewsData[] = $view->toArray();
            }
        }

        $cssFile = dol_buildpath('/savedviews/css/savedviews.css', 0);
        $jsFile = dol_buildpath('/savedviews/js/savedviews.js', 0);
        $cssVersion = file_exists($cssFile) ? filemtime($cssFile) : time();
        $jsVersion = file_exists($jsFile) ? filemtime($jsFile) : time();

        echo '<link rel="stylesheet" href="' . dol_buildpath('/savedviews/css/savedviews.css', 1) . '?v=' . $cssVersion . '">';

        echo '<script>
            window.savedviews_config = {
                token: "' . (currentToken() ?: newToken()) . '",
                ajaxUrl: "' . dol_buildpath('/savedviews/ajax/savedviews.php', 1) . '",
                currentPage: ' . json_encode($currentPage) . ',
                canCreate: ' . $canCreate . ',
                views: ' . json_encode($viewsData) . ',
                labels: {
                    saveView: ' . json_encode($langs->transnoentities('SaveView')) . ',
                    enterLabel: ' . json_encode($langs->transnoentities('EnterViewLabel')) . ',
                    deleteView: ' . json_encode($langs->transnoentities('DeleteView')) . ',
                    confirmDelete: ' . json_encode($langs->transnoentities('ConfirmDeleteView')) . ',
                    viewSaved: ' . json_encode($langs->transnoentities('ViewSaved')) . ',
                    viewDeleted: ' . json_encode($langs->transnoentities('ViewDeleted')) . ',
                    error: ' . json_encode($langs->transnoentities('Error')) . '
                }
            };
        </script>';

        echo '<script src="' . dol_buildpath('/savedviews/js/savedviews.js', 1) . '?v=' . $jsVersion . '"></script>';

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
