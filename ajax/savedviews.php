<?php
/* Copyright (C) 2024
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
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

require_once '../../../main.inc.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/functions.lib.php';
require_once DOL_DOCUMENT_ROOT . '/custom/savedviews/class/savedview.class.php';

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

// Sanitize page_url: allow path characters (/letters/digits/._-) but nothing else
function sanitizePageUrl($raw) {
    $url = trim($raw);
    $url = preg_replace('#[^a-zA-Z0-9/_\-\.]#', '', $url);
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
