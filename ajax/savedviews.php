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

header('Content-Type: application/json; charset=utf-8');

// Authentication check
if (empty($user->id)) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

// CSRF token check (required for all write actions, tolerated on list)
$action = GETPOST('action', 'aZ09');
if ($action !== 'list' && !GETPOST('token', 'alphanohtml')) {
    http_response_code(403);
    echo json_encode(['error' => 'CSRF token required']);
    exit;
}

// Sanitize page_url: path characters plus the "?type=X" discriminator suffix
function sanitizePageUrl($raw) {
    $url = trim($raw);
    $url = preg_replace('#[^a-zA-Z0-9/_\-\.\?=]#', '', $url);
    return $url;
}

$response = ['success' => false];

try {
    switch ($action) {
        case 'save':
            $label = GETPOST('label', 'alphanohtml');
            $pageUrl = sanitizePageUrl(GETPOST('page_url', 'none'));
            $viewDataRaw = GETPOST('view_data', 'none');

            if (empty($label) || empty($pageUrl) || empty($viewDataRaw)) {
                throw new Exception('Missing required parameters');
            }

            $viewData = json_decode($viewDataRaw, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('Invalid view_data JSON: ' . json_last_error_msg());
            }

            $savedView = new SavedView($db);
            $savedView->page_url = $pageUrl;
            $savedView->label = $label;
            $savedView->view_data = $viewData;

            $result = $savedView->create($user);
            if ($result > 0) {
                $response = [
                    'success' => true,
                    'view' => $savedView->toArray(),
                ];
            } else {
                throw new Exception($savedView->error ?: 'Failed to create view');
            }
            break;

        case 'update':
            $id = GETPOSTINT('id');
            $label = GETPOST('label', 'alphanohtml');
            $viewDataRaw = GETPOST('view_data', 'none');

            if (empty($id)) {
                throw new Exception('Missing view ID');
            }

            $savedView = new SavedView($db);
            $result = $savedView->fetch($id);

            if ($result <= 0) {
                throw new Exception('View not found');
            }

            if ($savedView->fk_user != $user->id) {
                throw new Exception('Access denied');
            }

            if (!empty($label)) {
                $savedView->label = $label;
            }

            if (!empty($viewDataRaw)) {
                $viewData = json_decode($viewDataRaw, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new Exception('Invalid view_data JSON: ' . json_last_error_msg());
                }
                $savedView->view_data = $viewData;
            }

            $result = $savedView->update($user);
            if ($result > 0) {
                $response = [
                    'success' => true,
                    'view' => $savedView->toArray(),
                ];
            } else {
                throw new Exception($savedView->error ?: 'Failed to update view');
            }
            break;

        case 'delete':
            $id = GETPOSTINT('id');

            if (empty($id)) {
                throw new Exception('Missing view ID');
            }

            $savedView = new SavedView($db);
            $result = $savedView->fetch($id);

            if ($result <= 0) {
                throw new Exception('View not found');
            }

            if ($savedView->fk_user != $user->id) {
                throw new Exception('Access denied');
            }

            $result = $savedView->delete($user);
            if ($result > 0) {
                $response = ['success' => true];
            } else {
                throw new Exception($savedView->error ?: 'Failed to delete view');
            }
            break;

        case 'list':
            $pageUrl = sanitizePageUrl(GETPOST('page_url', 'none'));

            $savedView = new SavedView($db);
            $views = $savedView->fetchAllForUser($user->id, $pageUrl);

            if (is_array($views)) {
                $viewsData = [];
                foreach ($views as $view) {
                    $viewsData[] = $view->toArray();
                }
                $response = [
                    'success' => true,
                    'views' => $viewsData,
                ];
            } else {
                throw new Exception($savedView->error ?: 'Failed to fetch views');
            }
            break;

        default:
            throw new Exception('Unknown action');
    }
} catch (Exception $e) {
    $response = [
        'success' => false,
        'error' => $e->getMessage(),
    ];
}

echo json_encode($response);
