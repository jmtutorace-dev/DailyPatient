# Repository Guidelines

## Project Structure & Module Organization

This is the YAKAP GAMOT patient-and-medicine tracking application, built with PHP, MySQL/MariaDB, and XAMPP. Page controllers live at the repository root: `index.php` is the daily log, while files such as `dashboard.php`, `consultation.php`, `transmit.php`, and `physicians.php` provide focused screens. Put shared PHP layout and access-control code in `includes/`; put shared database, session, escaping, and CSV helpers in `config.php`.

Frontend assets are centralized in `css/style.css` and `js/script.js`. The schema and seed data are in `database/yakap_gamot.sql`. Keep temporary diagnostics such as `debug_fpe_check.php` out of commits unless they are intentionally becoming supported tools.

## Local Development & Verification

Run the project through XAMPP: start Apache and MySQL, import `database/yakap_gamot.sql` in phpMyAdmin, then open `http://localhost/DailyPatient/`. Update MySQL credentials only in `config.php` when local defaults differ.

There is no build step or automated test runner. Before submitting PHP changes, lint every edited PHP file:

```powershell
php -l index.php
php -l includes/header.php
```

Also manually exercise affected POST actions, redirects/flash messages, CSV exports, and the relevant desktop and mobile layout.

## Coding Style & Naming Conventions

Follow the nearby code: four-space PHP indentation, braces on the same line, single-quoted simple strings, and descriptive snake_case for PHP functions and variables (for example, `normalize_patient_name`). Use prepared `mysqli` statements for database input and `h()` when rendering user-controlled HTML. Keep one page’s request handling and markup together unless logic is genuinely shared.

Use kebab-case filenames and CSS class names (for example, `patient-consultation.php` and `.sidebar-toggle`); use camelCase JavaScript variables and `const`/`let`. Extend the existing shared stylesheet and script rather than adding page-specific duplicate assets.

## Database, Security & Data Rules

Treat `database/yakap_gamot.sql` as the schema source of truth. Make schema and seed-data changes there, use parameterized queries, validate request values, and never commit real credentials or patient data. Preserve the FPE rule implemented by `patient_is_registered()`: a patient has one first registration, and later visits are consultations.

## Commits & Pull Requests

Recent history uses very short, informal summaries; prefer a clearer imperative summary such as `Add patient history modal`. Keep commits focused. Pull requests should state the user-facing behavior, list schema/configuration changes, link the relevant issue when available, and include screenshots for UI changes plus the manual checks performed.
