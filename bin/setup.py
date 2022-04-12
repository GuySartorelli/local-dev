#!/usr/bin/env python
# coding: utf-8

import os
import subprocess

# Constants
ROOT_DIR = os.path.dirname(os.path.dirname(os.path.realpath(__file__)))
DEFAULT_EMAIL = "guy.sartorelli+dev-local@silverstripe.com"

# All relative to ROOT_DIR
DIRECTORIES = {
    "sites_available": "/config/apache-2.4/sites-available",
    "sites_enabled": "/config/apache-2.4/sites-enabled",
    "ssl": "/config/apache-2.4/ssl",
    "logs_apache": "/persistent/logs/apache",
    "logs_php": "/persistent/logs/php",
    "db": "/persistent/mariadb"
}

def get_user_data():
    options = {}

    print """
    ###########################
    # PHP DEV FARM SETUP TOOL #
    ###########################
    """

    print """
    This script will guide you through the setup of php-dev-farm.
    You will only have to complete this process once, after which your environment
    will be ready for prime time.

    Web root
    ---------------------
    Where you place your web root is up to you. This setup script will create a
    symbolic link to this directory so automation scripts work correctly.
    Internally the Apache container will mount this directory to '/srv/www'.

    MariaDB data
    ---------------------
    For MariaDB to persist data a directory from the host must be mounted into the
    db docker container.
    If you already have db data you would like to migrate, you may move the
    contents of '/var/lib/mysql' into '%db_dir' after
    completing setup.

    Virtualhosts
    ---------------------
    Apache config is located in '%root_dir/config/apache-2.4', you can easily
    customize Apache by editing files in this directory. If you wish to create
    virtualhost files for pre php-dev-farm sites, you can use
    '%root_dir/bin/add-dev-site.py' to quickly port these sites over.
    """.replace("%root_dir", ROOT_DIR).replace("%db_dir", DIRECTORIES["db"])

    options["web_root"] = raw_input("Enter web root (without trailing slash): ")
    options["default_email"] = raw_input("Enter the default email for new dev sites: ")

    return options

def make_dirs(options):
    for key, value in DIRECTORIES.iteritems():
        mod_cur_dir = ROOT_DIR + value
        if not os.path.exists(mod_cur_dir):
            os.makedirs(mod_cur_dir)
            print "Created " + mod_cur_dir
        else:
            print "Skipped " + mod_cur_dir + " already exists"

def symlink_web_root(options):
    if not os.path.exists("www"):
        os.symlink(options["web_root"], "www")
        print "Created symlink to " + options["web_root"]
    else:
        print "Skipped " + options["web_root"] + " already exists"

def symlink_defalult_sites(options):
    if not os.path.exists("www"):
        os.symlink(options["web_root"], "www")
        print "Created symlink to " + options["web_root"]
    else:
        print "Skipped " +  options["web_root"] + " already exists "

def main():
    options = get_user_data()

    os.chdir(ROOT_DIR)

    make_dirs(options)

    # symlink_web_root(options)

    print "Setup complete. Run " + ROOT_DIR + "/start.sh to start environment."

main()
