#!/usr/bin/env bash

# This entry point script is needed so we can set environment variable DOCKER_HOST_IP to
# the IP the host uses on the docker bridge.

export DOCKER_HOST_IP=$(route -n | awk '/UG[ \t]/{print $2}')
php-fpm
