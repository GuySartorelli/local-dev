#!/bin/bash

# Usage example:
# run-php-script.sh 'composer install'

if [[ $PWD != /srv/www/* ]]; then
  # TODO: Allow user to define an arbitrary path to run the script in.
  echo "You aren't in /srv/www/ - this probably isn't a valid project."
  exit 1
fi


# TODO: Dynamically figure out the PHP version from the virtualhost.
PHP_VER=8.0
SITE_DIR="$(pwd | awk -F/ '{print $4}')"
WEB_PATH="/srv/www/${SITE_DIR}/htdocs"

INPUT_COMMAND=$1

docker exec -it "local-dev-php-fpm-${PHP_VER}-1" env TERM=xterm-256color bash -c "cd ${WEB_PATH} && ${INPUT_COMMAND}"