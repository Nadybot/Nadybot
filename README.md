# Budabot #

Budabot is a next-generation chatbot for Anarchy Online.

## Support & Bug Reports ##

For all support questions and bug reports please contact me in-game (Nadychat) or via email nadyita@hodorraid.org.

## Installation ##

There are three ways that you can obtain Budabot: Release Archives (recommended for most users), Latest Development, or Cloning the Repository (recommended for developers).

### Release Archives ###

You can download the latest stable version from link below. If you want the most stable version, choose the latest GA release.  If you want to test some of the newest features, choose an RC release.

<https://github.com/Nadyita/Budabot/releases>

### Latest Development ###

You can download the very latest version from the link below.  Note that this version is a development version, may not have been tested thoroughly, and may contain bugs.

<https://github.com/Nadyita/Budabot/archive/master.zip>

### Cloning The Repository ###

Alternatively you can clone the Budabot git repository. The advantage to doing this is that as new changes are committed you can simply do `git pull` to pull those changes into your copy. Note that this version is a development version, may not have been tested thoroughly, and may contain bugs. If you are planning on developing on Budabot, we recommend that you use this method.

<https://github.com/Nadyita/Budabot.git>

## Running Budabot ##

### Regular setup ###

To start the bot, run the ```chatbot.bat``` file (on linux run the ```chatbot.sh``` file). If it is your first time running the bot, it will take you through the configuration wizard to configure the bot. You will need to have a character name that you you want to run the bot as along with the username and password for the account that has that character. If you want to run this bot as an org bot, you will also need the EXACT org name of the org and the character that the bot runs as will need to have already been invited to the org.

### With Docker ###

In order to run the bot in Docker, first compile the image by issuing `docker build -t budabot .`.
You can then run the bot in test-mode like this:

```bash
docker run --rm \
  -e CONFIG_LOGIN=myaccount \
  -e CONFIG_PASSWORD=mypassword \
  -e CONFIG_BOTNAME=Mybot \
  -e CONFIG_SUPERADMIN=Myplayer \
  -e CONFIG_DB_TYPE=sqlite \
  -e CONFIG_DB_NAME=testdb \
  -e CONFIG_DB_HOST=/tmp \
  budabot
```

The database in this approach will be fresh on every start and you will expose your bot's password in the process list. Only use this for testing!

A systemd service for this type of configuration, using MariaDB in another docker container as database could look like this:

```ini
[Unit]
Description=My Cool Bot
Requires=docker.service
Requires=mariadb.service
After=docker.service

[Service]
Type=simple
ExecStartPre=-/usr/bin/docker stop "%n"
ExecStartPre=-/usr/bin/docker rm -f "%n"
ExecStart=/usr/bin/docker run \
	--rm \
	--name "%n" \
	--env-file /etc/sysconfig/mycoolbot \
	--link mariadb.service:mariadb \
	budabot
ExecStop=-/usr/bin/docker stop "%n"
ExecReload=/usr/bin/docker stop "%n"
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

and the corresponding configuration file in `/etc/sysconfig/mycoolbot`:

```ini
CONFIG_LOGIN=myaccountname
CONFIG_PASSWORD=mypassword
CONFIG_ORG=Myorg
CONFIG_SUPERADMIN=Mychar
CONFIG_BOTNAME=Mycoolbot
CONFIG_DIMENSION=5
CONFIG_DB_TYPE=mysql
CONFIG_DB_HOST=mariadb
CONFIG_DB_NAME=my_db_name
CONFIG_DB_USER=my_db_username
CONFIG_DB_PASS=my_db_pass_for_dbuser
```

This prevents passwords from showing up anywhere in the process list, but make sure you set the permissions of this file to `0600`, so no one except root can see your password.
