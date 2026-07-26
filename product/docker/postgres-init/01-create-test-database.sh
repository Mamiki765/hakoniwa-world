#!/bin/sh
set -eu

psql --set=ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" <<-EOSQL
    SELECT 'CREATE DATABASE hakoniwa_test OWNER ' || quote_ident(current_user)
    WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = 'hakoniwa_test')\gexec
EOSQL
