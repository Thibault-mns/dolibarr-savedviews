<?php
/* Copyright (C) 2024
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
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

        if (empty($user->id)) {
            return 0;
        }

        $langs->load('savedviews@savedviews');

        require_once DOL_DOCUMENT_ROOT . '/custom/savedviews/class/savedview.class.php';

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
                token: "' . newToken() . '",
                ajaxUrl: "' . dol_buildpath('/savedviews/ajax/savedviews.php', 1) . '",
                currentPage: ' . json_encode($currentPage) . ',
                views: ' . json_encode($viewsData) . ',
                labels: {
                    saveView: ' . json_encode(html_entity_decode($langs->trans('SaveView'), ENT_QUOTES, 'UTF-8')) . ',
                    enterLabel: ' . json_encode(html_entity_decode($langs->trans('EnterViewLabel'), ENT_QUOTES, 'UTF-8')) . ',
                    deleteView: ' . json_encode(html_entity_decode($langs->trans('DeleteView'), ENT_QUOTES, 'UTF-8')) . ',
                    confirmDelete: ' . json_encode(html_entity_decode($langs->trans('ConfirmDeleteView'), ENT_QUOTES, 'UTF-8')) . ',
                    viewSaved: ' . json_encode(html_entity_decode($langs->trans('ViewSaved'), ENT_QUOTES, 'UTF-8')) . ',
                    viewDeleted: ' . json_encode(html_entity_decode($langs->trans('ViewDeleted'), ENT_QUOTES, 'UTF-8')) . ',
                    error: ' . json_encode(html_entity_decode($langs->trans('Error'), ENT_QUOTES, 'UTF-8')) . '
                }
            };
        </script>';

        echo '<script src="' . dol_buildpath('/savedviews/js/savedviews.js', 1) . '?v=' . $jsVersion . '"></script>';

        return 0;
    }

    /**
     * Get current page URL (relative path without query string for base matching)
     *
     * @return string
     */
    private function getCurrentPageUrl()
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '';

        $parsed = parse_url($uri);
        $path = $parsed['path'] ?? '';

        $path = preg_replace('#^/htdocs#', '', $path);
        $path = preg_replace('#^/dolibarr#i', '', $path);

        return $path;
    }
}
