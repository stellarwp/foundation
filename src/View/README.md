# Foundation View

> [!WARNING]
> **This is a read-only repository!** For pull requests or issues, see [stellarwp/foundation](https://github.com/stellarwp/foundation).

Foundation View renders trusted PHP templates to strings.

## Installation

```shell
composer require stellarwp/foundation-view
```

## Render a template

```php
use StellarWP\Foundation\View\PhpView;

$view = new PhpView(__DIR__ . '/views');
$html = $view->render('account/summary', [
    'name' => 'Alex',
]);
```

This renders `views/account/summary.php` with `$name` available to the template. Supply names without the `.php` extension. Templates are executable PHP, so only use trusted application templates and escape values for their output context, e.g. `esc_html($name)` in WordPress HTML.

## Container registration

Set `view.directory` to an existing readable directory, then register `StellarWP\Foundation\View\ViewProvider`. Inject `Contracts\View` for rendering or `Contracts\DirectoryAwareView` when a consumer needs directory selection. Both resolve to the same configured `PhpView` instance by default.

```php
$otherView = $view->withDirectory(__DIR__ . '/email-views');
$html      = $otherView->render('receipt', [
    'name' => 'Alex',
]);
```

`withDirectory()` returns a new renderer and leaves the original unchanged.

## Template boundaries

Absolute names, parent-directory traversal and null bytes are rejected. Resolved files must stay inside the configured directory, including through symlinks. Missing or inaccessible templates throw `Exceptions\ViewNotFoundException`.

Rendering captures output and cleans up removable buffers opened by the template when it fails. Templates must leave the renderer's output buffer intact and must not close, flush or replace it. This is not a sandbox for untrusted PHP; templates can still perform arbitrary PHP operations.
