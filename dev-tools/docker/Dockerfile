FROM ubuntu:20.04

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

VOLUME ["/var/www"]

ENV TZ=Pacific/Auckland
RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone

# Using apt-get instead of apt to prevent warning about apt not having a stable CLI interface

# Make all php versions other than the current main version (7.4) available
RUN apt-get update && apt-get install -y software-properties-common
RUN add-apt-repository -y ppa:ondrej/php
RUN add-apt-repository -y ppa:ondrej/apache2

# Uncomment this if you wish to run chromium beta
# add-apt-repository -y ppa:chromium-team/beta

# Ensure any packages are installed in Dockerfile rather than docker_bash_script so
# that this 'slice' is cached for future builds
# Increment echo int to force an update when adding new packages for installation
RUN apt-get update && echo 7 > /dev/null

# Needed for a couple of the installs
RUN apt-get install -y wget

# Set this arg so that apt-utils does not cause other warnings
ARG DEBIAN_FRONTEND=noninteractive

# bcmath extension and chromium gave warnings when installing if this was missing
RUN apt-get install -y apt-utils

RUN apt-get install -y apache2

#RUN apt-get install -y chromium-browser
#RUN apt-get install -y chromium-chromedriver
# Install chrome, chromedriver, and dependencies
RUN apt-get install -y fonts-liberation xdg-utils libasound2 libatk-bridge2.0-0 libatk1.0-0 libatspi2.0-0 libcairo2 libcups2 libgbm1 libgtk-3-0 libnspr4 libnss3 libpango-1.0-0 libxdamage1 libxkbcommon0 unzip
RUN wget https://dl.google.com/linux/direct/google-chrome-stable_current_amd64.deb
RUN dpkg -i google-chrome-stable_current_amd64.deb
# Install and verify version of chromedriver
RUN BROWSER_MAJOR=$(google-chrome --version | sed 's/Google Chrome \([0-9]*\).*/\1/g') \
    && wget https://chromedriver.storage.googleapis.com/LATEST_RELEASE_${BROWSER_MAJOR} -O chrome_version \
    && wget https://chromedriver.storage.googleapis.com/`cat chrome_version`/chromedriver_linux64.zip \
    && unzip chromedriver_linux64.zip \
    && mv chromedriver /usr/local/bin/ \
    && DRIVER_MAJOR=$(chromedriver --version | sed 's/ChromeDriver \([0-9]*\).*/\1/g') \
    && echo "chrome version: $BROWSER_MAJOR" \
    && echo "chromedriver version: $DRIVER_MAJOR" \
    && if [ $BROWSER_MAJOR != $DRIVER_MAJOR ]; then echo "VERSION MISMATCH"; exit 1; fi

# mailhog
RUN apt-get install -y golang-go
RUN apt-get install -y git

# PHP version specific packages
# A note about xdebug - much faster without it, noticable when running all phpunit tests within a module,
# Disabled by default in docker_bash_script

RUN apt-get install -y libapache2-mod-php7.4
RUN apt-get install -y php7.4
RUN apt-get install -y php7.4-bcmath
RUN apt-get install -y php7.4-cli
RUN apt-get install -y php7.4-curl
RUN apt-get install -y php7.4-dev
RUN apt-get install -y php7.4-dom
RUN apt-get install -y php7.4-gd
RUN apt-get install -y php7.4-intl
RUN apt-get install -y php7.4-json
RUN apt-get install -y php7.4-ldap
RUN apt-get install -y php7.4-mbstring
RUN apt-get install -y php7.4-mysql
# RUN apt-get install -y php7.4-pgsql
RUN apt-get install -y php7.4-tidy
RUN apt-get install -y php7.4-xdebug
RUN apt-get install -y php7.4-zip

RUN apt-get install -y libapache2-mod-php8.0
RUN apt-get install -y php8.0
RUN apt-get install -y php8.0-bcmath
RUN apt-get install -y php8.0-cli
RUN apt-get install -y php8.0-curl
RUN apt-get install -y php8.0-dev
RUN apt-get install -y php8.0-dom
RUN apt-get install -y php8.0-gd
RUN apt-get install -y php8.0-intl
# RUN apt-get install -y php8.0-json - built into php8.0 now
RUN apt-get install -y php8.0-ldap
RUN apt-get install -y php8.0-mbstring
RUN apt-get install -y php8.0-mysql
# RUN apt-get install -y php8.0-pgsql
RUN apt-get install -y php8.0-tidy
RUN apt-get install -y php8.0-xdebug
RUN apt-get install -y php8.0-zip

RUN apt-get install -y libapache2-mod-php8.1
RUN apt-get install -y php8.1
RUN apt-get install -y php8.1-bcmath
RUN apt-get install -y php8.1-cli
RUN apt-get install -y php8.1-curl
RUN apt-get install -y php8.1-dev
RUN apt-get install -y php8.1-dom
RUN apt-get install -y php8.1-gd
RUN apt-get install -y php8.1-intl
RUN apt-get install -y php8.1-ldap
RUN apt-get install -y php8.1-mbstring
RUN apt-get install -y php8.1-mysql
# RUN apt-get install -y php8.1-pgsql
RUN apt-get install -y php8.1-tidy
RUN apt-get install -y php8.1-xdebug
RUN apt-get install -y php8.1-zip

# PHP CLI
# Use php8.0 by default
RUN rm /etc/alternatives/php
RUN ln -s /usr/bin/php8.0 /etc/alternatives/php

# Apache2 CLI
# Defaults to php7.4, change to php8.0
RUN rm /etc/apache2/mods-enabled/php7.4.conf
RUN rm /etc/apache2/mods-enabled/php7.4.load
RUN ln -s /etc/apache2/mods-available/php8.0.conf /etc/apache2/mods-enabled/php8.0.conf
RUN ln -s /etc/apache2/mods-available/php8.0.load /etc/apache2/mods-enabled/php8.0.load

# Install other packages
RUN apt-get install -y nano

# Install WKHTMLTOPDF QT ptached
RUN apt-get install -y fontconfig libxrender1 xfonts-75dpi xfonts-base
RUN wget https://github.com/wkhtmltopdf/packaging/releases/download/0.12.6-1/wkhtmltox_0.12.6-1.focal_amd64.deb
RUN dpkg -i wkhtmltox_0.12.6-1.focal_amd64.deb
RUN cp /usr/local/bin/wkhtmltopdf /usr/bin/
RUN cp /usr/local/bin/wkhtmltoimage /usr/bin/

# Put localhost in place
COPY docker_apache_default /etc/apache2/sites-available/000-default.conf
RUN a2enmod rewrite

EXPOSE 80
EXPOSE 443

# Default www-data user/group id is 33, change it to 1000 to match the default user on host
# https://jtreminio.com/blog/running-docker-containers-as-current-host-user/#ok-so-what-actually-works
ARG USER_ID=1000
ARG GROUP_ID=1000
RUN userdel -f www-data &&\
    if getent group www-data ; then groupdel www-data; fi &&\
    groupadd -g ${GROUP_ID} www-data &&\
    useradd -l -u ${USER_ID} -g www-data www-data &&\
    install -d -m 0755 -o www-data -g www-data /home/www-data &&\
    chown --changes --silent --no-dereference --recursive \
          --from=33:33 ${USER_ID}:${GROUP_ID} \
        /home/www-data

# Bash script - anything else that's just easier to write in raw bash than dockerfile
COPY docker_bash_script /usr/local/bin/docker_bash_script
RUN chmod +x /usr/local/bin/docker_bash_script
RUN /usr/local/bin/docker_bash_script
RUN rm /usr/local/bin/docker_bash_script

COPY entrypoint /usr/local/bin/start_container
RUN chmod +x /usr/local/bin/start_container
CMD ["/usr/local/bin/start_container"]
