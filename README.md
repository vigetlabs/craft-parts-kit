# Craft CMS Parts Kit Plugin

A simple component library plugin for Craft CMS (think [Storybook](https://storybook.js.org/) or [Fractal](https://fractal.build/)).

It uses Craft's built-in Twig rendering and does not depend on build tools or npm packages. 

This plugin scans your `templates/parts-kit` directory and serves a prebuilt UI that loads each component in an iframe.

The UI is provided by Viget's [Parts Kit Web Component](https://github.com/vigetlabs/parts-kit).

https://github.com/user-attachments/assets/b1205f58-1d8b-4c73-9bad-60b3e6eb2015

## Key features

- Low abstraction: render with your real Twig templates
- Zero-build tools: Simply add Twig files to the `templates/parts-kit` directory. 
- Clean URLs for each part (e.g. `/parts-kit/button/default`)

## Requirements

This plugin requires Craft CMS 5.0.0 or later, and PHP 8.2 or later.

## Installation

You can install this plugin from the Craft Plugin Store (Coming Soon) or with Composer.

### From the Plugin Store (Coming Soon)

Go to the Plugin Store in your project’s Control Panel and search for “Parts Kit”. Then press “Install”.

### With Composer

Open your terminal and run the following commands:

```bash
# go to the project directory
cd /path/to/my-project

# tell Composer to load the plugin
composer require viget/craft-parts-kit

# tell Craft to install the plugin
craft plugin/install parts-kit
```

## Setup & Usage

1. Create templates in `templates/parts-kit` (examples below).

2. (Optional) Create a configuration file at `config/parts-kit.php` to customize settings.

3. Visit `/parts-kit` on your site. The plugin registers this route and renders the UI.

That's it. The UI fetches its config from the plugin's JSON endpoint and lists your parts automatically.

## Configuration

You can customize the plugin's behavior by creating a `config/parts-kit.php` file in your project:

```php
<?php

return [
    // The directory where your parts kit templates are located.
    // This is both the URL you access and the path in your project's templates directory.
    // Default: 'parts-kit'
    'directory' => 'parts-kit',

    // Path to a Twig template that loads scripts & styles used by your parts.
    // This partial should contain the CSS and JS needed by your components.
    // The same partial can (and probably should) be included in your project's layout.
    // Default: null
    'headTemplatePath' => '_partials/head.twig',

    // Require a logged in user with admin or has the "View Parts Kit" permission to view parts kit URLs.
    // Set to false to allow anonymous access to the parts kit.
    // Default: true
    'requireViewPermission' => true,
];
```

### Example Head Template

Create a partial at `templates/_partials/head.twig`:

```twig
<link rel="stylesheet" href="/path/to/your/styles.css">
<script src="/path/to/your/scripts.js"></script>
```

This partial will be automatically included in the `<head>` of each parts kit page. You can also include this same partial in your main site layout to ensure consistency.

## Creating & Organizing Your Parts (Twig templates)

Create Twig templates under `templates/parts-kit`. Folders become navigation groups; files become pages. File and folder names are humanized for display.

Hidden files/folders (names starting with a dot) are ignored. Root-level `index.twig`/`index.html` are also ignored.

### Example tree

```
templates/
└── parts-kit/
    ├── button/
    │   └── default.twig
    └── forms/
        ├── select.twig
        ├── text-input.twig
        └── textarea.twig
```

Each file is rendered at a clean URL that mirrors the path without the extension. For example:

- `templates/parts-kit/button/default.twig` → `/parts-kit/button/default`
- `templates/parts-kit/forms/text-input.twig` → `/parts-kit/forms/text-input`

The Parts Kit plugin provides an Action URL that returns a JSON config used by our Parts Kit UI.

<details>
<summary>Show Example JSON</summary>

```json
{
  "schemaVersion": "0.0.1",
  "nav": [
    {
      "title": "Button",
      "url": null,
      "children": [
        {
          "title": "Default",
          "url": "/parts-kit/button/default",
          "children": []
        }
      ]
    }
  ]
}
```

</details>

## Example Usage in Templates

Create a file in the `templates/parts-kit/button/default.twig` directory and simply import and render your component:

```twig
{% from '_components/button' import Button %}

{{ Button({
  text: 'Button Primary',
  size: 'lg',
}) }}
```

That's it! No need to extend layouts or wrap your code in blocks.

## Testing

The plugin uses [Codeception](https://codeception.com/) with Craft's testing framework. Tests run against a real Craft instance, so a database is required.

1. Install the dev dependencies:

   ```bash
   composer install
   ```

2. Create a `craft_test` database (MySQL or PostgreSQL).

3. Copy the matching env example to `tests/.env` and fill in your database credentials:

   ```bash
   cp tests/.env.example.mysql tests/.env   # or tests/.env.example.pgsql
   ```

4. Run the suite:

   ```bash
   composer test              # all suites
   composer test-unit         # unit suite only
   composer test-functional   # functional suite only
   ```

Once the suite has run at least once, add `--env fast` to skip the database rebuild between runs (e.g. `./vendor/bin/codecept run unit --env fast`).

Continuous integration runs the suite on PHP 8.2 against MySQL and PostgreSQL for every pull request and pushes to `main` (see `.github/workflows/ci.yml`).

## Local development with DDEV

This repo ships a [DDEV](https://ddev.com/) harness so you can boot a real Craft CMS install with the plugin loaded and live-editable. The plugin source lives at the repo root; a full Craft app lives in `craft-install/` and loads the plugin via a Composer [path repository](https://getcomposer.org/doc/05-repositories.md#path) (a symlink), so edits to `src/` are reflected immediately.

### Getting started

```bash
ddev start   # Start the containers
ddev setup   # Install Craft + the plugin (idempotent, safe to re-run)
```

Then visit:

- **https://craft-parts-kit.ddev.site/parts-kit** — the component library, pre-populated with sample parts
- **https://craft-parts-kit.ddev.site/admin** — the control panel (login `admin` / `password`)

The dev install ships a few sample parts under `craft-install/templates/parts-kit/` and a `craft-install/config/parts-kit.php` that sets `requireViewPermission => false`, so `/parts-kit` is viewable anonymously without logging in. These dev-only files are excluded from the distributed plugin package and do not change the plugin's production defaults.

### Useful commands

```bash
ddev craft <command>                  # Run Craft CLI (e.g. migrate/all, plugin/list)
ddev composer <command>               # Operates on the PLUGIN root composer.json
ddev craft clear-caches/cp-resources  # Clear CP asset caches after JS/CSS changes
```

> **Gotcha:** `composer_root` is set to `.` in `.ddev/config.yaml`, so `ddev composer` targets the plugin's root `composer.json`, not the Craft app. To manage Craft app dependencies, run them against `craft-install/` explicitly:
>
> ```bash
> ddev exec -d /var/www/html/craft-install composer require <package>
> ```

> **Note:** The browsing UI loads from the unpkg CDN, so the dev environment needs network access.

## Credits

Built by [Viget](https://www.viget.com). The Parts Kit UI is powered by our JavaScript library documented at [vigetlabs/parts-kit](https://github.com/vigetlabs/parts-kit).
