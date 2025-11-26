# WPMUDEV Test Plugin #

This is a plugin that can be used for testing coding skills for WordPress and PHP.

# Development

## Composer
Install composer packages
`composer install`

## Build Tasks (npm)
Everything should be handled by npm.

Install npm packages
`npm install`

| Command              | Action                                                |
|----------------------|-------------------------------------------------------|
| `npm run watch`      | Compiles and watch for changes.                       |
| `npm run compile`    | Compile production ready assets.                      |
| `npm run build`  | Build production ready bundle inside `/build/` folder |

The build task now installs Composer dependencies in production mode (`--no-dev`, optimized autoloader) inside the release directory to ensure the final zip only contains the runtime packages required in WordPress.
