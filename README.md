# Laravel Nodeless Starter Kit

Laravel Nodeless is a Laravel starter kit for building Livewire applications without a frontend build system.

It starts from Laravel's official Livewire starter kit, then removes Node, npm, Vite, Tailwind compilation, and all related CI steps. The result is a PHP-first application scaffold that can be installed, developed, tested, and deployed with Composer and Laravel tooling only.

## Philosophy

Laravel is productive because the framework gives you a cohesive, batteries-included path for building server-rendered applications. This starter kit keeps that path intentionally small:

- No Node runtime requirement.
- No npm install step.
- No Vite dev server.
- No frontend build step in local development, CI, or deployment.
- Livewire and Flux remain the primary UI layer.
- Static CSS, JavaScript, and fonts are checked in under `public/build` and served directly by Laravel.

This tradeoff is deliberate. You give up an editable Tailwind/Vite pipeline in exchange for a starter kit that works in PHP-only environments and has fewer moving parts.

## What's Included

- Laravel 13
- Livewire 4
- Flux 2
- Fortify authentication
- Two-factor authentication
- Passkey support
- Prebuilt Tailwind/Flux assets served from `public/build`

## What's Removed

- `package.json`
- `package-lock.json`
- `node_modules`
- `vite.config.js`
- `resources/css/app.css`
- `resources/js/*`
- npm and Vite steps from Composer scripts and GitHub Actions

## Installation

Create a new Laravel application using this starter kit:

```bash
laravel new {project} --using=artisan-build/laravel-nodeless
```

Replace `{project}` with the directory name for your new application.

## Getting Started

Install dependencies and prepare the application:

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
```

Run the development server:

```bash
php artisan serve
```

Run the test suite:

```bash
php artisan test
```

## Composer Scripts

This starter kit includes an opinionated Composer workflow for keeping Laravel applications ready to ship:

- `composer setup` installs dependencies, creates `.env`, generates the app key, and runs migrations.
- `composer dev` starts Laravel's local development server without Vite or Node.
- `composer lint` runs Laravel Pint and fixes PHP style issues.
- `composer test:lint` runs Laravel Pint in check-only mode.
- `composer lint:check` is an alias for the check-only Pint run.
- `composer rector` runs Rector with the Laravel code quality, collection, and Laravel level sets.
- `composer stan` runs PHPStan through Larastan at level 6.
- `composer test` clears cached config, checks PHP formatting, and runs the Laravel test suite.
- `composer ide-helper` regenerates Laravel IDE Helper files and model mixins.
- `composer ready` runs IDE helper generation, Rector, Pint, PHPStan, tests, and Composer audit.
- `composer report` runs Rector, Pint, PHPStan, tests, and Composer audit as a non-blocking report.
- `composer ci:check` runs the default project test check used by this starter kit.
- `php artisan fresh` resets the database to a fresh, seeded state. You can add additional steps to set your application up for local development.
- `php artisan flux:pro` installs Flux Pro by adding the Flux Pro Composer repository and requiring `livewire/flux-pro`. This assumes your Flux Pro credentials are already saved globally; if they are not, run `php artisan flux:activate` instead.

## Working With Assets

The starter kit intentionally does not require a source asset pipeline. Application CSS, font CSS, fonts, and the passkey browser helper are committed as static files in `public/build/assets`.

By default, there is still no frontend build step. The application loads `public/build/assets/app.css`, `public/build/assets/fonts.css`, and `public/build/assets/passkeys.js` directly.

If CSS size matters or you add Tailwind classes and want to regenerate the checked-in CSS, run the opt-in optimizer:

```bash
php artisan tailwind:optimize
```

The command downloads the standalone Tailwind CSS CLI for your operating system, caches it under `storage/app/tools`, scans the configured Tailwind sources, and writes the optimized output to `public/build/assets/app.css`. It does not require Node, npm, or Vite, and it is not part of the default setup or CI workflow.

You can force a fresh CLI download or change paths when needed:

```bash
php artisan tailwind:optimize --force-download
php artisan tailwind:optimize --tailwind-version=v4.3.0
php artisan tailwind:optimize --input=resources/css/tailwind.css --output=public/build/assets/app.css
```

## Repository

This project lives at `artisan-build/laravel-nodeless`.
