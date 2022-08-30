# Todo

- If a PR is for a version we're not currently installing, panic.
  - e.g. interactive "the PR you want is for x.y, but you're installing a.b. Continue installing? Your PR won't be checked out, you'll have to do that manually."
- Git commands
  - Set origins (make git-set-remotes a command here instead)
  - Create me a new PR branch
    - "pulls/$CURRENT_BRANCH/$DESCRIPTION"
  - Push the current PR branch
    - Runs phpcs linting
    - Checks for correct naming convention
    - Pushes to creative-commoners by default
- Finish up todos in codebase
- Set up a meta web server
  - basic page showing available projects with some basic info (url, mailhog, php version, etc)
  - phpmyadmin or similar to manage db
- Stop relying on 000-default.conf
  - Have a new placeholder 000-default.conf which just outputs some "this is the default page" message
  - Update what is current docker_apache_default to be a specific virtualhost for the specific site
- ??
