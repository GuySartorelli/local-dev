#!/usr/bin/env bash
WWWUID=`id -u www-data`
export WWWUID
DOCKERHOSTIP="172.17.0.1" #`ip addr show dev docker0 | grep inet | cut -d ' ' -f 6 | cut -d '/' -f 1`
export DOCKERHOSTIP
export UID

docker-compose up -d
