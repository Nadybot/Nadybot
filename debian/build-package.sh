#!/bin/bash
set -euo pipefail
if [ "$#" -ne 1 ]; then
  echo "Syntax: $0 <release>"
  exit 1
fi
curl -L -s -S -o /build/nadybot.zip "https://github.com/Nadybot/Nadybot/releases/download/$1/nadybot-bundle-$1.zip"
cd /build/
unzip nadybot.zip
cd Nadybot
cp packages/*.service debian/
tar czf "../nadybot_$1.orig.tar.gz" .
dpkg-buildpackage -S -us -uc
cp "../nadybot_$1"* /data/
