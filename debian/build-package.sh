#!/bin/bash
cp /data/nadybot_*.orig.tar.gz /
mkdir /Nadybot
cd /Nadybot
tar xzf /nadybot_*.orig.tar.gz
dpkg-buildpackage -S -us -uc
mv ../nadybot_* /data/
