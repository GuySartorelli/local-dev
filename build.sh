#!/usr/bin/env bash
WWWUID=`id -u www-data`
export WWWUID
DOCKERHOSTIP=`ifconfig docker0 | grep "inet addr" | cut -d ':' -f 2 | cut -d ' ' -f 1`
export DOCKERHOSTIP
mkdir -p logs
mkdir -p database
mkdir -p apache/sites-available/
mkdir -p apache/sites-enabled/


docker-compose build
