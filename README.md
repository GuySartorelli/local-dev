## Setup
- Add the bin directory to $PATH in your .bashrc
  - e.g.: `export PATH=$PATH:/home/gsartorelli/local-dev/bin`

## Usage
Run `dev-tools list` to see available commands.

`dev-tools up` will create a new environment.
`dev-tools down` will pull down an environment.

Use `git-set-remotes` to automatically add remotes for silverstripe forks.

THE FOLLOWING CURRENTLY DOES NOT WORK AND WILL BE REPLACED WITH A DEV-TOOLS SCRIPT
From the working directory (/srv/www/some-site.local/*) run `run-php-script` with arguments to run commands from the php docker container for that site.
