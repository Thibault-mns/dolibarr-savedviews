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

// Permissions: 'read' to list/apply views, 'create' for every write action
$canRead = $user->hasRight('savedviews', 'read');
$canCreate = $user->hasRight('savedviews', 'create');

if (!$canRead) {
    savedviewsRespond(0, null, 'Insufficient permissions', 403);
}
if (in_array($action, ['save', 'update', 'delete'], true) && !$canCreate) {
    savedviewsRespond(0, null, 'Insufficient permissions', 403);
}

// Sanitize page_url: path characters plus the "?type=X" discriminator suffix
function savedviewsSanitizePageUrl($raw) {
    $url = trim($raw);
    $url = preg_replace('#[^a-zA-Z0-9/_\-\.\?=]#', '', $url);
    return $url;
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

            $viewData = json_decode($viewDataRaw, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('Invalid view_data JSON: ' . json_last_error_msg(), 400);
            }

            $savedView = new SavedView($db);
            $savedView->page_url = $pageUrl;
            $savedView->label = dol_trunc($label, 128, 'right', 'UTF-8', 1);
            $savedView->view_data = $viewData;

            $result = $savedView->create($user);
            if ($result <= 0) {
                throw new Exception($savedView->error ?: 'Failed to create view', 500);
            }

            savedviewsRespond(1, ['view' => $savedView->toArray()]);
            break;

        case 'update':
            $id = GETPOSTINT('id');
            $label = GETPOST('label', 'alphanohtml');
            $viewDataRaw = GETPOST('view_data', 'none');

            if (empty($id)) {
                throw new Exception('Missing view ID', 400);
            }

            $savedView = new SavedView($db);
            $result = $savedView->fetch($id);

            if ($result < 0) {
                throw new Exception($savedView->error ?: 'Failed to fetch view', 500);
            }
            if ($result == 0) {
                throw new Exception('View not found', 404);
            }

            // A view belongs to a single user: nobody else may modify it, admin included
            if ($savedView->fk_user != $user->id) {
                throw new Exception('Access denied', 403);
            }

            if (!empty($label)) {
                $savedView->label = dol_trunc($label, 128, 'right', 'UTF-8', 1);
            }

            if (!empty($viewDataRaw)) {
                $viewData = json_decode($viewDataRaw, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new Exception('Invalid view_data JSON: ' . json_last_error_msg(), 400);
                }
                $savedView->view_data = $viewData;
            }

            $result = $savedView->update($user);
            if ($result <= 0) {
                throw new Exception($savedView->error ?: 'Failed to update view', 500);
            }

            savedviewsRespond(1, ['view' => $savedView->toArray()]);
            break;

        case 'delete':
            $id = GETPOSTINT('id');

            if (empty($id)) {
                throw new Exception('Missing view ID', 400);
            }

            $savedView = new SavedView($db);
            $result = $savedView->fetch($id);

            if ($result < 0) {
                throw new Exception($savedView->error ?: 'Failed to fetch view', 500);
            }
            if ($result == 0) {
                throw new Exception('View not found', 404);
            }

            if ($savedView->fk_user != $user->id) {
                throw new Exception('Access denied', 403);
            }

            $result = $savedView->delete($user);
            if ($result <= 0) {
                throw new Exception($savedView->error ?: 'Failed to delete view', 500);
            }

            savedviewsRespond(1);
            break;

        case 'list':
            $pageUrl = savedviewsSanitizePageUrl(GETPOST('page_url', 'none'));

            $savedView = new SavedView($db);
            $views = $savedView->fetchAllForUser($user->id, $pageUrl);

            if (!is_array($views)) {
                throw new Exception($savedView->error ?: 'Failed to fetch views', 500);
            }

            $viewsData = [];
            foreach ($views as $view) {
                $viewsData[] = $view->toArray();
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
