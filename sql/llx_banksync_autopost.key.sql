-- SPDX-License-Identifier: GPL-3.0-or-later

ALTER TABLE llx_banksync_autopost ADD UNIQUE INDEX uk_banksync_autopost_transaction (entity, fk_transaction);
