-- Keep the live users table compatible with the current GoTM admin account contract.
-- Some older production databases were created before users.username existed.
ALTER TABLE users
  ADD COLUMN IF NOT EXISTS username VARCHAR(100) NULL AFTER name;

CREATE UNIQUE INDEX IF NOT EXISTS uq_users_username
  ON users(username);
