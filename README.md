## Setup
- Run `composer install` inside the `./dev-tools/` directory.
- Copy `./dev-tools/.env.example` to `./dev-tools/.env`
  - Fill in and change details as appropriate
- Add the bin directory to $PATH in your .bashrc
  - e.g.: `export PATH=$PATH:/home/$USER/local-dev/bin`

## Usage
- Run `dev-tools list` to see available commands and usage.
- Run `dev-tools help [command]` to get help information for the script or for a specific command.

- Use `git-set-remotes` to automatically add remotes for silverstripe forks.
  This will probably eventually be replaced with a dev-tools command.
