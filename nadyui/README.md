# nadyui

nadyui is the in-development version of the official web-based UI for Nadybot.

The goal is to make using Nadybot easier and providing tools for admins to manage the bot and provide a dashboard-like experience. Therefore, it leverages Nadybot's HTTP API and uses the websocket for communication. Both have to be enabled for nadyui to work.

## Installation

All Nadybot release bundles come pre-bundled with NadyUI. If you are using the git version of Nadybot, then a build specific to your version will also be built automatically and installed during launch of the bot.

To compile and install any changes, run `composer install-ui` at the Nadybot base directory. To do on-the-fly reloading of changed files during development, use `composer test-ui`.

### Compiles and hot-reloads for development

```shell
npm run build-dev
```

### Compiles and minifies for production

```shell
npm run build
```

### Lints and fixes files

```shell
npm run lint
```
