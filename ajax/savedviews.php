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
 * \file        ajax/savedviews.php
 * \ingroup     savedviews
 * \brief       AJAX handler for SavedViews module
 */

if (!defined('NOTOKENRENEWAL')) {
    define('NOTOKENRENEWAL', '1');
}
if (!defined('NOREQUIREMENU')) {
    define('NOREQUIREMENU', '1');
}
if (!defined('NOREQUIREHTML')) {
    define('NOREQUIREHTML', '1');
}
if (!defined('NOREQUIREAJAX')) {
    define('NOREQUIREAJAX', '1');
}

// Load Dolibarr environment (module can live in htdocs/custom/ or htdocs/)
$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
    $res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"] . "/main.inc.php";
}
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1;
$j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {
    $i--;
    $j--;
}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1)) . "/main.inc.php")) {
    $res = @include substr($tmp, 0, ($i + 1)) . "/main.inc.php";
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1))) . "/main.inc.php")) {
    $res = @include dirname(substr($tmp, 0, ($i + 1))) . "/main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
    $res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
    $res = @include "../../../main.inc.php";
}
if (!$res) {
    die("Include of main fails");
}

dol_include_once('/savedviews/class/savedview.class.php');

// Dolibarr >= 24 ships a normalized AJAX response object. Use it when present so the
// payload stays consistent with the rest of the application, keep the same keys otherwise.
if (file_exists(DOL_DOCUMENT_ROOT . '/core/class/jsonResponse.class.php')) {
    require_once DOL_DOCUMENT_ROOT . '/core/class/jsonResponse.class.php';
}

header('Content-Type: application/json; charset=utf-8');

/**
 * Output the JSON response and stop.
 * Keys are those of core JsonResponse: result, msg, newToken, data.
 *
 * @param int    $result   1 = OK, 0 = KO
 * @param mixed  $data     Payload
 * @param string $msg      Message (error text when $result = 0)
 * @param int    $httpcode HTTP status to force (0 = let the default apply)
 * @return never
 */
function savedviewsRespond($result, $data = null, $msg = '', $httpcode = 0)
{
    $result = $result ? 1 : 0;

    if (class_exists('JsonResponse')) {
        $response = new JsonResponse();
        $response->result = $result;
        $response->data = $data;
        $response->msg = $msg;
        // getResponse() forces a 400 when result is 0, so re-apply our own status after it
        $out = $response->getResponse();
        if ($httpcode > 0 && !headers_sent()) {
            http_response_code($httpcode);
        }
        echo $out;
        exit;
    }

    if ($httpcode <= 0 && !$result) {
        $httpcode = 400;
    }
    if ($httpcode > 0 && !headers_sent()) {
        http_response_code($httpcode);
    }
    echo json_encode([
        'result' => $result,
        'msg' => $msg,
        'newToken' => function_exists('newToken') ? newToken() : '',
        'data' => $data,
    ]);
    exit;
}

// Authentication check
if (empty($user->id)) {
    savedviewsRespond(0, null, 'Not authenticated', 401);
}

$action = GETPOST('action', 'aZ09');

// Write actions are POST only, so the CSRF token never travels in a URL
if (in_array($action, ['save', 'update', 'delete', 'setdefault'], true) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    savedviewsRespond(0, null, 'Method not allowed', 405);
}

// CSRF token check. main.inc.php only enforces it when MAIN_SECURITY_CSRF_WITH_TOKEN is set
// (not the default), so the token is compared here for every action. currentToken() is the
// value to use for AJAX calls and the one the page injects; this script sets NOTOKENRENEWAL so
// the session token does not rotate. newToken() is accepted too, since both are session-bound
// secrets and the two differ on the first request of a session or with token renewal on each call.
// When core ran the check itself and it failed, it emptied $_POST and set errorcode=InvalidToken.
$posttoken = GETPOST('token', 'alphanohtml');
if (GETPOST('errorcode', 'alpha') === 'InvalidToken'
    || empty($posttoken)
    || !in_array($posttoken, array_filter([currentToken(), newToken()]), true)
) {
    savedviewsRespond(0, null, 'Invalid or missing CSRF token', 403);
}

// Permissions: 'read' to list/apply views and choose a default one, 'create' to save new views.
// Updating or deleting an existing view is checked per view by SavedView::canEdit().
$canRead = $user->hasRight('savedviews', 'read');
$canCreate = $user->hasRight('savedviews', 'create');

if (!$canRead) {
    savedviewsRespond(0, null, 'Insufficient permissions', 403);
}
if ($action === 'save' && !$canCreate) {
    savedviewsRespond(0, null, 'Insufficient permissions', 403);
}

// Sanitize page_url: path characters plus the "?type=X" discriminator suffix
function savedviewsSanitizePageUrl($raw) {
    $url = trim($raw);
    $url = preg_replace('#[^a-zA-Z0-9/_\-\.\?=]#', '', $url);
    return $url;
}

/**
 * Decode and sanitize the posted view_data
 *
 * @param string $raw     Raw JSON
 * @param string $pageUrl Page key of the view
 * @return array
 * @throws Exception
 */
function savedviewsReadViewData($raw, $pageUrl)
{
    if (strlen($raw) > SavedView::VIEW_DATA_MAX_LENGTH) {
        throw new Exception('View data too large', 413);
    }
    $viewData = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Invalid view_data JSON: ' . json_last_error_msg(), 400);
    }

    return SavedView::sanitizeViewData($viewData, $pageUrl);
}

/**
 * Read the sharing fields (scope + user groups) from the request into a view
 *
 * @param SavedView $savedView View
 * @return void
 * @throws Exception
 */
function savedviewsReadSharing($savedView)
{
    $savedView->scope = SavedView::normalizeScope(GETPOST('scope', 'int'));
    $groups = GETPOST('usergroups', 'array');
    $savedView->usergroups = is_array($groups) ? array_map('intval', $groups) : [];
    if ($savedView->scope == SavedView::SCOPE_USERGROUP && empty(array_filter($savedView->usergroups))) {
        throw new Exception('Select at least one user group', 400);
    }
}

/**
 * Log a database error and return a message safe to show to the user
 *
 * @param SavedView $savedView View holding the error
 * @param string    $context   What failed
 * @return string
 */
function savedviewsDbError($savedView, $context)
{
    dol_syslog('savedviews ' . $context . ': ' . $savedView->error, LOG_ERR);
    return $context;
}

/**
 * Load a view the current user can see, or throw
 *
 * @param DoliDB $db   Database handler
 * @param User   $user Current user
 * @param int    $id   View id
 * @return SavedView
 * @throws Exception
 */
function savedviewsFetchVisible($db, $user, $id)
{
    if (empty($id)) {
        throw new Exception('Missing view ID', 400);
    }

    $savedView = new SavedView($db);
    $result = $savedView->fetch($id);
    if ($result < 0) {
        throw new Exception(savedviewsDbError($savedView, 'Failed to fetch view'), 500);
    }
    // Views the user cannot see are reported as missing, not as forbidden
    if ($result == 0 || !$savedView->isVisibleBy($user)) {
        throw new Exception('View not found', 404);
    }

    return $savedView;
}

/**
 * Apply the posted is_default flag for the current user
 *
 * @param DoliDB    $db        Database handler
 * @param User      $user      Current user
 * @param SavedView $savedView View
 * @return void
 * @throws Exception
 */
function savedviewsApplyDefault($db, $user, $savedView)
{
    if (!GETPOSTISSET('is_default')) {
        return;
    }
    $isDefault = GETPOST('is_default', 'int') ? true : false;
    $currentDefault = SavedView::getDefaultId($user, $savedView->page_url);

    if ($isDefault && $currentDefault != $savedView->id) {
        $result = SavedView::setDefaultId($db, $user, $savedView->page_url, $savedView->id);
    } elseif (!$isDefault && $currentDefault == $savedView->id) {
        $result = SavedView::setDefaultId($db, $user, $savedView->page_url, 0);
    } else {
        return;
    }
    if ($result < 0) {
        throw new Exception('Failed to save the default view', 500);
    }
}

// Exception codes are used as HTTP status codes by the handler below
try {
    switch ($action) {
        case 'save':
            $label = GETPOST('label', 'alphanohtml');
            $pageUrl = savedviewsSanitizePageUrl(GETPOST('page_url', 'none'));
            $viewDataRaw = GETPOST('view_data', 'none');

            if (empty($label) || empty($pageUrl) || empty($viewDataRaw)) {
                throw new Exception('Missing required parameters', 400);
            }

            $savedView = new SavedView($db);
            $savedView->page_url = $pageUrl;
            $savedView->label = dol_trunc($label, 128, 'right', 'UTF-8', 1);
            $savedView->view_data = savedviewsReadViewData($viewDataRaw, $pageUrl);
            savedviewsReadSharing($savedView);

            $result = $savedView->create($user);
            if ($result <= 0) {
                throw new Exception(savedviewsDbError($savedView, 'Failed to create view'), 500);
            }
            savedviewsApplyDefault($db, $user, $savedView);

            savedviewsRespond(1, ['view' => $savedView->toArray($user)]);
            break;

        case 'update':
            $savedView = savedviewsFetchVisible($db, $user, GETPOSTINT('id'));
            if (!$savedView->canEdit($user)) {
                throw new Exception('Access denied', 403);
            }

            $label = GETPOST('label', 'alphanohtml');
            if (!empty($label)) {
                $savedView->label = dol_trunc($label, 128, 'right', 'UTF-8', 1);
            }
            if (GETPOSTISSET('scope')) {
                savedviewsReadSharing($savedView);
            }
            // "Replace with the current view": new filters, columns and display mode
            $viewDataRaw = GETPOST('view_data', 'none');
            if (!empty($viewDataRaw)) {
                $savedView->view_data = savedviewsReadViewData($viewDataRaw, $savedView->page_url);
            } else {
                // Views saved before 1.2.0 were not sanitized and may now get shared
                $savedView->view_data = SavedView::sanitizeViewData($savedView->view_data, $savedView->page_url);
            }

            $result = $savedView->update($user);
            if ($result <= 0) {
                throw new Exception(savedviewsDbError($savedView, 'Failed to update view'), 500);
            }
            savedviewsApplyDefault($db, $user, $savedView);

            savedviewsRespond(1, ['view' => $savedView->toArray($user)]);
            break;

        case 'setdefault':
            // Personal preference: any view the user can see may become his default one
            $savedView = savedviewsFetchVisible($db, $user, GETPOSTINT('id'));
            savedviewsApplyDefault($db, $user, $savedView);

            savedviewsRespond(1, ['view' => $savedView->toArray($user)]);
            break;

        case 'delete':
            $savedView = savedviewsFetchVisible($db, $user, GETPOSTINT('id'));
            if (!$savedView->canEdit($user)) {
                throw new Exception('Access denied', 403);
            }

            if (SavedView::getDefaultId($user, $savedView->page_url) == $savedView->id) {
                SavedView::setDefaultId($db, $user, $savedView->page_url, 0);
            }

            $result = $savedView->delete($user);
            if ($result <= 0) {
                throw new Exception(savedviewsDbError($savedView, 'Failed to delete view'), 500);
            }

            savedviewsRespond(1);
            break;

        case 'list':
            $pageUrl = savedviewsSanitizePageUrl(GETPOST('page_url', 'none'));

            $savedView = new SavedView($db);
            $views = $savedView->fetchAllVisible($user, $pageUrl);

            if (!is_array($views)) {
                throw new Exception(savedviewsDbError($savedView, 'Failed to fetch views'), 500);
            }

            $viewsData = [];
            foreach ($views as $view) {
                $viewsData[] = $view->toArray($user);
            }

            savedviewsRespond(1, ['views' => $viewsData]);
            break;

        default:
            throw new Exception('Unknown action', 400);
    }
} catch (Exception $e) {
    $code = $e->getCode();
    savedviewsRespond(0, null, $e->getMessage(), ($code >= 400 && $code <= 599) ? $code : 400);
}
