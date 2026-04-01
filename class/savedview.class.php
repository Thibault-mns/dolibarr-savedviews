<?php
/* Copyright (C) 2024
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
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

    /** @var int */
    public $fk_user;

    /** @var string Page URL (relative path) */
    public $page_url;

    /** @var string */
    public $label;

    /** @var array View data (columns, filters, mode) */
    public $view_data;

    /** @var int Position for ordering */
    public $position;

    /** @var int */
    public $date_creation;

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
        $sql .= "entity, fk_user, page_url, label, view_data, position, date_creation";
        $sql .= ") VALUES (";
        $sql .= (int) $this->entity;
        $sql .= ", " . (int) $this->fk_user;
        $sql .= ", '" . $this->db->escape($this->page_url) . "'";
        $sql .= ", '" . $this->db->escape($this->label) . "'";
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

        $sql = "SELECT rowid, entity, fk_user, page_url, label, view_data, position, date_creation";
        $sql .= " FROM " . MAIN_DB_PREFIX . "savedviews";
        $sql .= " WHERE rowid = " . (int) $id;
        $sql .= " AND entity = " . (int) $conf->entity;

        $resql = $this->db->query($sql);
        if ($resql) {
            if ($this->db->num_rows($resql)) {
                $obj = $this->db->fetch_object($resql);

                $this->id = $obj->rowid;
                $this->entity = $obj->entity;
                $this->fk_user = $obj->fk_user;
                $this->page_url = $obj->page_url;
                $this->label = $obj->label;
                $this->view_data = json_decode($obj->view_data, true);
                $this->position = $obj->position;
                $this->date_creation = $this->db->jdate($obj->date_creation);

                return 1;
            }
            return 0;
        }

        $this->error = $this->db->lasterror();
        return -1;
    }

    /**
     * Update object into database
     *
     * @param User $user User that modifies
     * @return int >0 if OK, <0 if KO
     */
    public function update($user)
    {
        $this->db->begin();

        $sql = "UPDATE " . MAIN_DB_PREFIX . "savedviews SET";
        $sql .= " label = '" . $this->db->escape($this->label) . "'";
        $sql .= ", view_data = '" . $this->db->escape(json_encode($this->view_data)) . "'";
        $sql .= ", position = " . (int) $this->position;
        $sql .= " WHERE rowid = " . (int) $this->id;
        $sql .= " AND fk_user = " . (int) $user->id;

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
     * Delete object in database
     *
     * @param User $user User that deletes
     * @return int >0 if OK, <0 if KO
     */
    public function delete($user)
    {
        $this->db->begin();

        $sql = "DELETE FROM " . MAIN_DB_PREFIX . "savedviews";
        $sql .= " WHERE rowid = " . (int) $this->id;
        $sql .= " AND fk_user = " . (int) $user->id;

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
     * Fetch all saved views for a specific user and page.
     * Single query (no N+1). Only returns views belonging to $userId.
     *
     * @param int    $userId  User ID
     * @param string $pageUrl Page URL filter (optional)
     * @return array|int Array of SavedView objects, or -1 on DB error
     */
    public function fetchAllForUser($userId, $pageUrl = '')
    {
        global $conf;

        $views = [];

        $sql = "SELECT rowid, entity, fk_user, page_url, label, view_data, position, date_creation";
        $sql .= " FROM " . MAIN_DB_PREFIX . "savedviews";
        $sql .= " WHERE entity = " . (int) $conf->entity;
        $sql .= " AND fk_user = " . (int) $userId;
        if (!empty($pageUrl)) {
            $sql .= " AND page_url = '" . $this->db->escape($pageUrl) . "'";
        }
        $sql .= " ORDER BY position ASC, date_creation ASC";

        $resql = $this->db->query($sql);
        if ($resql) {
            while ($obj = $this->db->fetch_object($resql)) {
                $view = new SavedView($this->db);
                $view->id = $obj->rowid;
                $view->entity = $obj->entity;
                $view->fk_user = $obj->fk_user;
                $view->page_url = $obj->page_url;
                $view->label = $obj->label;
                $view->view_data = json_decode($obj->view_data, true);
                $view->position = $obj->position;
                $view->date_creation = $this->db->jdate($obj->date_creation);
                $views[] = $view;
            }
            return $views;
        }

        $this->error = $this->db->lasterror();
        return -1;
    }

    /**
     * Get view data as array for JSON response
     *
     * @return array
     */
    public function toArray()
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'page_url' => $this->page_url,
            'view_data' => $this->view_data,
            'position' => $this->position,
        ];
    }
}
