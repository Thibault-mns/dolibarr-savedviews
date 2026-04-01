<?php
/* Copyright (C) 2024
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file        core/modules/modSavedViews.class.php
 * \ingroup     savedviews
 * \brief       Module descriptor for SavedViews
 */

include_once DOL_DOCUMENT_ROOT . '/core/modules/DolibarrModules.class.php';

/**
 * Class modSavedViews
 */
class modSavedViews extends DolibarrModules
{
    /**
     * Constructor
     *
     * @param DoliDB $db Database handler
     */
    public function __construct($db)
    {
        global $langs, $conf;
        $this->db = $db;

        $this->numero = 500200;
        $this->rights_class = 'savedviews';
        $this->family = "tools";
        $this->module_position = '90';
        $this->name = preg_replace('/^mod/i', '', get_class($this));
        $this->description = "Sauvegarde de vues pré-filtrées sur les listes";
        $this->descriptionlong = "Module permettant de sauvegarder et restaurer des vues personnalisées (colonnes, filtres, mode d'affichage) sur toutes les pages de liste Dolibarr";
        $this->version = '1.0.0';
        $this->const_name = 'MAIN_MODULE_' . strtoupper($this->name);
        $this->picto = 'fa-bookmark';

        $this->module_parts = [
            'hooks' => ['all'],
        ];

        $this->dirs = [];
        $this->config_page_url = [];

        $this->hidden = false;
        $this->depends = [];
        $this->requiredby = [];
        $this->conflictwith = [];
        $this->phpmin = [7, 0];
        $this->need_dolibarr_version = [16, 0];
        $this->langfiles = ["savedviews@savedviews"];

        $this->const = [];
        $this->tabs = [];
        $this->dictionaries = [];
        $this->boxes = [];
        $this->cronjobs = [];
        $this->rights = [];
        $this->menu = [];
    }

    /**
     * Function called when module is enabled.
     *
     * @param string $options Options when enabling module ('', 'noboxes')
     * @return int 1 if OK, 0 if KO
     */
    public function init($options = '')
    {
        $result = $this->_load_tables('/savedviews/sql/');
        if ($result < 0) {
            return -1;
        }

        return $this->_init([], $options);
    }

    /**
     * Function called when module is disabled.
     *
     * @param string $options Options when disabling module ('', 'noboxes')
     * @return int 1 if OK, 0 if KO
     */
    public function remove($options = '')
    {
        return $this->_remove([], $options);
    }
}
