## Setup
- Run build.sh
- Add the bin directory to $PATH in your .bashrc
  - e.g.: `export PATH=$PATH:/home/gsartorelli/local-dev/bin`

## Usage
Run start.sh to run local dev environment.

Run bin/add-dev-site.py to add a new site.

From the working directory (/srv/www/some-site.local/*) run `run-php-script` with arguments to run commands from the php docker container for that site.
