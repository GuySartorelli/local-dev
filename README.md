## Setup
- Run `composer install` inside the `./dev-tools/` directory.
- Run `cp ./dev-tools/.env.example ./dev-tools/.env`
  - Fill in and change details as appropriate
- Add the bin directory to $PATH.
  | Shell           | Command                                                                     |
  | --------------- | --------------------------------------------------------------------------- |
  | BASH            | `export PATH=$PATH:$HOME/local-dev/dev-tools/bin`                     |
  | BASH Permanent  | `echo 'export PATH=$PATH:$HOME/local-dev/dev-tools/bin' >> ~/.bashrc` |
  | Fish            | `fish_add_path $HOME/local-dev/dev-tools/bin`                         |
  | ZSH             | `export PATH=$PATH:$HOME/local-dev/dev-tools/bin`                     |
  | ZSH Permanent   | `echo 'export PATH=$PATH:$HOME/local-dev/dev-tools/bin' >> ~/.zshrc`  |

## Usage
- Run `dev-tools list` to see available commands and usage.
- Run `dev-tools help [command]` to get help information for the script or for a specific command.

### Shortcuts
There are some (currently undocumented) shortcuts for common tasks such as running composer commands or dev/build. Check out `src/App/Application.php` to see what those are.

## Development

### PHP Versions
When adding new PHP versions, don't forget to update the list in .env.example and .env
