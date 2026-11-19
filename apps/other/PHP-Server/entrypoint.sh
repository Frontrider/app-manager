#!/bin/sh
set -eu

export APACHE_RUN_USER='#1000'
export APACHE_RUN_GROUP='#1000'
export APACHE_RUN_DIR=/tmp/apache2
export APACHE_LOCK_DIR=/tmp/apache2
export APACHE_LOG_DIR=/tmp/apache2
export APACHE_PID_FILE=/tmp/apache2/apache2.pid

mkdir -p "$APACHE_RUN_DIR"
exec "$@"
