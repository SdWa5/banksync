-- SPDX-License-Identifier: GPL-3.0-or-later

CREATE TABLE llx_banksync_autopost(
    rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
    entity INTEGER DEFAULT 1 NOT NULL,
    fk_transaction INTEGER NOT NULL,
    decision VARCHAR(32) NOT NULL,
    reason VARCHAR(64) NOT NULL,
    detail TEXT,
    notified INTEGER DEFAULT 0 NOT NULL,
    date_creation DATETIME NOT NULL,
    date_decision DATETIME NOT NULL,
    fk_user INTEGER,
    tms TIMESTAMP
) ENGINE=innodb;
