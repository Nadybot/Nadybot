# nadyui

nadyui is the in-development version of an official web-based UI for [Nadybot](https://github.com/Nadybot/Nadybot).

The goal is to make using Nadybot easier and providing tools for admins to manage the bot and provide a dashboard-like experience. Therefore, it leverages Nadybot's HTTP API and uses the websocket for communication. Both have to be enabled for nadyui to work.

## Installation

Grab a release bundle and extract it to Nadybot's `html` directory. After enabling the webserver and websocket, you can then access it on the port you configured. Default username and passwords will be generated on each Nadybot restart, check the console output. Alternatively, `!webauth` will give you credentials to log in.

### Compiles and hot-reloads for development

```
npm run serve
```

### Compiles and minifies for production

```
npm run build
```

### Lints and fixes files

```
npm run lint
```

### Build within podman

```
./build.sh
```
