#!/usr/bin/env python
# coding: utf-8

import sys
import os
import json
import subprocess
import pprint

# Constants
PRINT_SEP = "------------------------"

ROOT_DIR = os.path.dirname(os.path.dirname(os.path.realpath(__file__)))
WEB_ROOT = "/srv/www/" # In the apache container.
DEFAULT_EMAIL = "guy.sartorelli+local-dev@silverstripe.com"

SITES_AVAILABLE_DIR = ROOT_DIR + "/config/apache-2.4/sites-available/"
SITES_ENABLED_DIR = ROOT_DIR + "/config/apache-2.4/sites-enabled/"
SSL_CERT_DIR = ROOT_DIR + "/config/apache-2.4/ssl/"
PIMP_MY_LOG_CONFIG_FILE = ROOT_DIR + "/default-sites/pimp-my-log.local/htdocs/config.user.php"
ERROR_LOG_SUFFIX = ".error.log"
ACCESS_LOG_SUFFIX = ".access.log"
# APACHE_LOG_DIR = ROOT_DIR + "/peristent/logs/apache"

DEFAULT_SSL_COUNTRY = "NZ"
DEFAULT_SSL_STATE = "Wellington"
DEFAULT_SSL_CITY = "Wellington"
DEFAULT_SSL_ORGANIZATION = "Silverstripe"
DEFAULT_SSL_UNIT = "Development"

ENVIRONMENTS = {
    "Hip Hop Virtual Machine": "hhvm:9000",
    "PHP FPM 7.3": "php-fpm-7.3:9000",
    "PHP FPM 7.4": "php-fpm-7.4:9000",
    "PHP FPM 8.0": "php-fpm-8.0:9000"
}

VIRTUAL_HOST_TEMPLATE = """
<VirtualHost *:80>
        ServerName %s
        ServerAlias www.%s

        ServerAdmin %s
        DocumentRoot %s

        ErrorLog ${APACHE_LOG_DIR}/%s
        CustomLog ${APACHE_LOG_DIR}/%s combined

        <Directory "%s">
                Order allow,deny
                Allow from all
                AllowOverride FileInfo All
                Require all granted
        </Directory>

        ProxyPassMatch ^/(.*\.php(/.*)?)$ fcgi://%s%s$1
</VirtualHost>
"""

VIRTUAL_HOST_TEMPLATE_SSL = """
<VirtualHost *:443>
        ServerName %s
        ServerAlias www.%s

        ServerAdmin %s
        DocumentRoot %s

        ErrorLog ${APACHE_LOG_DIR}/%s
        CustomLog ${APACHE_LOG_DIR}/%s combined

        SSLEngine on
        SSLCertificateFile /etc/apache2/ssl/%s.crt
        SSLCertificateKeyFile /etc/apache2/ssl/%s.key

        <FilesMatch "\.(cgi|shtml|phtml|php)$">
            SSLOptions +StdEnvVars
        </FilesMatch>
        <Directory /usr/lib/cgi-bin>
                        SSLOptions +StdEnvVars
        </Directory>
        BrowserMatch "MSIE [2-6]" \\
                        nokeepalive ssl-unclean-shutdown \\
                        downgrade-1.0 force-response-1.0
        BrowserMatch "MSIE [17-9]" ssl-unclean-shutdown

        <Directory "%s">
                Order allow,deny
                Allow from all
                AllowOverride FileInfo All
                Require all granted
        </Directory>

        ProxyPassMatch ^/(.*\.php(/.*)?)$ fcgi://%s%s$1
</VirtualHost>
"""

PIMP_MY_LOG_TEMPLATE = """
                },
                "apache2": {
                        "display" : "%s",
                        "path"    : "\/var\/log\/apache2\/%s",
                        "refresh" : 4,
                        "max"     : 100,
                        "notify"  : true,
                        "format"  : {
                                "type"         : "NCSA",
                                "regex"        : "|^((\\S*) )*(\\S*) (\\S*) (\\S*) \\[(.*)\\] \"(\\S*) (.*) (\\S*)\" ([0-9]*) (.*)( \"(.*)\" \"(.*)\"( [0-9]*/([0-9]*))*)*$|U",
                                "export_title" : "URL",
                                "match"        : {
                                        "Date"    : 6,
                                        "IP"      : 3,
                                        "CMD"     : 7,
                                        "URL"     : 8,
                                        "Code"    : 10,
                                        "Size"    : 11,
                                        "Referer" : 13,
                                        "UA"      : 14,
                                        "User"    : 5,
                                        "\\u03bcs" : 16
                                },
                                "types": {
                                        "Date"    : "date:H:i:s",
                                        "IP"      : "ip:geo",
                                        "URL"     : "txt",
                                        "Code"    : "badge:http",
                                        "Size"    : "numeral:0b",
                                        "Referer" : "link",
                                        "UA"      : "ua:{os.name} {os.version} | {browser.name} {browser.version}\/100",
                                        "\\u03bcs" : "numeral:0,0"
                                },
                                "exclude": {
                                        "URL": ["\/favicon.ico\/", "\/\\.pml\\.php.*$\/"],
                                        "CMD": ["\/OPTIONS\/"]
                                }
                        }
"""

CERTIFICATE_GENERATION_COMMAND = """
    openssl req -x509 -nodes -days 365 -newkey rsa:2048 -keyout %s.key -out %s.crt -subj "/C=%s/ST=%s/cL=%s/O=%s/OU=%s/CN=%s"
"""

def get_user_data():
    options = {}

    options["domain"] = input("Domain name: ")

    options["email"] = input("Admin email (default "
                                 + DEFAULT_EMAIL  + "): ")

    options["email"] = options["email"] if options["email"] else DEFAULT_EMAIL

    options["site_root"] = WEB_ROOT + options["domain"] + "/htdocs/"

    return options

def make_site_dir(options):
    if not os.path.exists(options["site_root"]):
        os.makedirs(options["site_root"])
        print("Created " + options["site_root"])
    else:
        print(options["site_root"] + " already exits, skipping")

def get_ssl_data():
    options = {}

    print("Please provide the following details to generate your cert. Leave blank to use default.")

    options["country"] = input("Country (default "
                                  + DEFAULT_SSL_COUNTRY +  "): ")

    options["country"] = options["country"] if options["country"] else DEFAULT_SSL_COUNTRY

    options["state"] = input("State (default "
                                  + DEFAULT_SSL_STATE +  "): ")

    options["state"] = options["state"] if options["state"] else DEFAULT_SSL_STATE


    options["city"] = input("City (default "
                                  + DEFAULT_SSL_CITY +  "): ")

    options["city"] = options["city"] if options["city"] else DEFAULT_SSL_CITY


    options["organization"] = input("Organization (default "
                                  + DEFAULT_SSL_ORGANIZATION +  "): ")

    options["organization"] = options["organization"] if options["organization"] else DEFAULT_SSL_ORGANIZATION


    options["unit"] = input("Unit (default "
                                  + DEFAULT_SSL_UNIT +  "): ")

    options["unit"] = options["unit"] if options["unit"] else DEFAULT_SSL_UNIT


    return options

def generate_ssl_cert(options):
    command = CERTIFICATE_GENERATION_COMMAND % (
        SSL_CERT_DIR + options["domain"],
        SSL_CERT_DIR + options["domain"],
        options["country"],
        options["state"],
        options["city"],
        options["organization"],
        options["unit"],
        options["domain"]
    )

    proc = subprocess.Popen(command,
                        shell=True, stdin=subprocess.PIPE,
                        stdout=subprocess.PIPE,
                        stderr=subprocess.PIPE)

def make_virtual_host(options, enable_ssl):
    filename = SITES_AVAILABLE_DIR + options["domain"] + ".conf"

    file = open(filename, "w+")

    print(VIRTUAL_HOST_TEMPLATE % (
        options["domain"],
        options["domain"],
        options["email"],
        options["site_root"],
        options["domain"] + ERROR_LOG_SUFFIX,
        options["domain"] + ACCESS_LOG_SUFFIX,
        options["site_root"],
        options["environment"],
        options["site_root"]
    ), file=file)

    if(enable_ssl):
        print(VIRTUAL_HOST_TEMPLATE_SSL % (
            options["domain"],
            options["domain"],
            options["email"],
            options["site_root"],
            options["domain"] + ERROR_LOG_SUFFIX,
            options["domain"] + ACCESS_LOG_SUFFIX,
            options["domain"],
            options["domain"],
            options["site_root"],
            options["environment"],
            options["site_root"]
        ), file=file)

    return filename

def symlink_virtual_host(options):
    filename = options["domain"] + ".conf"

    os.chdir(SITES_ENABLED_DIR)
    if not os.path.exists(SITES_ENABLED_DIR + filename):
        os.symlink("../sites-available/" + filename, SITES_ENABLED_DIR + filename)
        print("Virtual host enabled")
    else:
        print("Virtual host already enabled, skipping.")

def choose_environment(options):
    while True:
        print("Please choose one of the following PHP environments: ")

        keys = list(ENVIRONMENTS.keys())
        for index, elem in enumerate(keys):
            print("    " + str(index) + ": " + ENVIRONMENTS[elem])

        environment = input("Environment number: ")

        if environment.isdigit() and int(environment) < len(keys):
            options["environment"] = ENVIRONMENTS[keys[int(environment)]]
            break
        else:
            print("Invalid environment number.")


    return options

def update_hosts_file(options):
    update_value = "\"127.0.0.1 " + options["domain"] + "\""

    command = "gksu -- bash -c 'echo %host >> /etc/hosts'"
    command = command.replace("%host", update_value)

    proc = subprocess.Popen(command,
                        shell=True, stdin=subprocess.PIPE,
                        stdout=subprocess.PIPE,
                        stderr=subprocess.PIPE)

def update_pimp_my_log_config_file(options):
    with open(PIMP_MY_LOG_CONFIG_FILE) as f:
        data = json.load(f)

    pprint.pprint(data);

def cmd_exists(cmd):
    return subprocess.call("type " + cmd, shell=True,
        stdout=subprocess.PIPE, stderr=subprocess.PIPE) == 0


def main():
    print("""
    ###########################################
    # PHP DEV FARM VIRTUAL HOST CREATION TOOL #
    ###########################################
    """)

    if not os.path.exists(ROOT_DIR + "/www"):
        print("You need to run " + ROOT_DIR + "/bin/setup.py to provision your environment.")
        sys.exit()

    options = get_user_data()

    print(PRINT_SEP)

    make_site_dir(options)

    print(PRINT_SEP)

    options = choose_environment(options)

    print(PRINT_SEP)

    enable_ssl = input("Would you like to enable https for this site (y, n)? ")

    if enable_ssl == "y":
        ssl_options = get_ssl_data()

        ssl_options["domain"] = options["domain"]

        generate_ssl_cert(ssl_options)

        virtualhost_filename = make_virtual_host(options, True)
    else:
        virtualhost_filename = make_virtual_host(options, False)

    print(PRINT_SEP)

    symlink_virtual_host(options)

    print(PRINT_SEP)

#    pimp_that_log = raw_input("Would you like to add this site to Pimp My Log (y, n)? ")

 #   if pimp_that_log == "y":
  #      update_pimp_my_log_config_file(options)

   # print PRINT_SEP

    # if cmd_exists("gksu"):
        # update = input("Would you like to automatically update the hosts file with an entry for this site (y, n)? ")

        # if update == "y":
            # update_hosts_file(options)
    # else:
        # print("I need 'gksu' installed to automatically set hosts file.")
        
    print("Add the URL to your hosts file and restart apache.")

main()
