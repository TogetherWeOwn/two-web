-- Runs once, the first time the postgres volume is created.
-- The test suite runs against Postgres, not sqlite, so it needs its own database.
CREATE DATABASE two_web_test OWNER two_web;
