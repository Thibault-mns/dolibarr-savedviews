-- Copyright (C) 2024
--
-- This program is free software; you can redistribute it and/or modify
-- it under the terms of the GNU General Public License as published by
-- the Free Software Foundation; either version 3 of the License, or
-- (at your option) any later version.

CREATE TABLE llx_savedviews (
    rowid           INTEGER AUTO_INCREMENT PRIMARY KEY,
    entity          INTEGER DEFAULT 1 NOT NULL,
    fk_user         INTEGER NOT NULL,
    page_url        VARCHAR(255) NOT NULL,
    label           VARCHAR(128) NOT NULL,
    view_data       TEXT NOT NULL,
    position        INTEGER DEFAULT 0,
    date_creation   DATETIME NOT NULL,
    tms             TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_savedviews_user (fk_user),
    INDEX idx_savedviews_page (page_url),
    INDEX idx_savedviews_entity (entity)
) ENGINE=InnoDB;
