-- Copyright (C) 2024
--
-- This program is free software; you can redistribute it and/or modify
-- it under the terms of the GNU General Public License as published by
-- the Free Software Foundation; either version 3 of the License, or
-- (at your option) any later version.

-- Indexes are defined in the main table.
-- Upgrade from 1.1.x: run after llx_savedviews.sql on every module activation,
-- "column already exists" errors are ignored by the module loader.
ALTER TABLE llx_savedviews ADD COLUMN scope TINYINT DEFAULT 0 NOT NULL;
