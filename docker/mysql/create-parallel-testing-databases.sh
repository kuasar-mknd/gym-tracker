#!/usr/bin/env bash
#
# Sail ships create-testing-database.sh, which grants the application user
# ALL PRIVILEGES ON `testing%`. That covers Sail's default test database name,
# but this project uses `gym_tracker_testing` (see phpunit.xml), so the pattern
# never matched and Paratest could not create its per-process shard databases
# (`gym_tracker_testing_test_1`, `_test_2`, …). Every Feature test failed
# instantly under `artisan test -p` with an access-denied error.
#
# Underscores are wildcards in GRANT patterns, hence the escaping: the grant is
# restricted to the shard databases and nothing else.
#
# La base `gym_tracker_testing` elle-même n'existait pas non plus : Sail ne crée
# que `testing`, et un Sail neuf échouait dès le premier test, faute de base
# sur laquelle Paratest se connecte pour créer les siennes.

if [ -n "$MYSQL_USER" ]; then
    mysql --user=root --password="$MYSQL_ROOT_PASSWORD" <<-EOSQL
        CREATE DATABASE IF NOT EXISTS \`gym_tracker_testing\`;
        GRANT ALL PRIVILEGES ON \`gym_tracker_testing\`.* TO '$MYSQL_USER'@'%';
        GRANT ALL PRIVILEGES ON \`gym\_tracker\_testing\_test\_%\`.* TO '$MYSQL_USER'@'%';
	EOSQL
fi
