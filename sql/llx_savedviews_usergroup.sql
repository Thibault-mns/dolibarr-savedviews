-- Copyright (C) 2026
--
-- This program is free software; you can redistribute it and/or modify
-- it under the terms of the GNU General Public License as published by
-- the Free Software Foundation; either version 3 of the License, or
-- (at your option) any later version.

-- User groups a view is shared with (views of scope 1)
CREATE TABLE llx_savedviews_usergroup (
    rowid           INTEGER AUTO_INCREMENT PRIMARY KEY,
    fk_savedview    INTEGER NOT NULL,
    fk_usergroup    INTEGER NOT NULL,
    UNIQUE INDEX uk_savedviews_usergroup (fk_savedview, fk_usergroup),
    INDEX idx_savedviews_usergroup_group (fk_usergroup)
) ENGINE=InnoDB;
