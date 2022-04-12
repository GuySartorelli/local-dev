#!/bin/bash

# This commands runs behat tests. It should be run from your project root.
# You need to specify what behat suite you want to run. e.g.: `behatrunner @asset-admin`.
# Note that the behat test will run in your current desktop session ... you will see chrome
# running and the behat test executed in front of you.
# Prequisites: chromedriver installed. silverstripe/recipe-testing installed in your project.

# Export envrionment variable
export SS_BASE_URL="http://localhost:8080/"
export SS_ENVIRONMENT_TYPE="dev"

# Start chrome in a different session
chromedriver > /dev/null &
#chromium.chromedriver > /dev/null &
CHROME_PID=$!

# Start web server in a different proccess
vendor/bin/serve --bootstrap-file vendor/silverstripe/cms/tests/behat/serve-bootstrap.php > /dev/null &
SERVER_PID=$!

# Wait a bit to make sure everything has been initialised
sleep 4

# Run behat tests
vendor/bin/behat $*

# When behat is done, kill the webserver and chrome
kill $SERVER_PID
kill $CHROME_PID
