## Setup
- Run build.sh
- Run `ln -s /path/to/this/dir/bin/run-php-script.sh /usr/local/bin/run-php-script`

## Usage
Run start.sh to run local dev environment.

Run bin/add-dev-site.py to add a new site.

From the working directory (/srv/www/some-site.local/*) run `run-php-script` with arguments to run commands from the php docker container for that site.
