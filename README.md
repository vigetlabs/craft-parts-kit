# Craft CMS Parts Kit Plugin

Decoupled parts kit for Craft CMS that powers a Storybook-esque UI using your actual Twig templates. This plugin scans your `templates/parts-kit` directory, builds a navigation JSON, and serves a prebuilt UI that loads each part in an iframe.

The UI is provided by the Parts Kit JS library. See the JS project docs: [vigetlabs/parts-kit](https://github.com/vigetlabs/parts-kit).

https://github.com/user-attachments/assets/b1205f58-1d8b-4c73-9bad-60b3e6eb2015

## Key features

- Low abstraction: render with your real Twig templates
- Zero-build UI: just a script tag and a custom element
- Auto-generated navigation from your `templates/parts-kit` files
- Clean URLs for each part (e.g. `/parts-kit/button/default`)

## Requirements

This plugin requires Craft CMS 5.0.0 or later, and PHP 8.2 or later.

## Installation

You can install this plugin from the Plugin Store or with Composer.

### From the Plugin Store

Go to the Plugin Store in your project’s Control Panel and search for Parts Kit”. Then press “Install”.

### With Composer

Open your terminal and run the following commands:

```bash
# go to the project directory
cd /path/to/my-project

# tell Composer to load the plugin
composer require viget/craft-parts-kit

# tell Craft to install the plugin
craft plugin/install craft-parts-kit
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
- `templates/parts-kit/card/card-with-image.twig` → `/parts-kit/forms/select`

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

## Credits

Built by [Viget](https://www.viget.com). The Parts Kit UI is powered by our JavaScript library documented at [vigetlabs/parts-kit](https://github.com/vigetlabs/parts-kit).
