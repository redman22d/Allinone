# Tutor LMS Suite

WordPress/Tutor LMS plugin source is in `tutor-lms-suite/`.
The original supplied archive is preserved as `tutor-lms-suite (1).zip`.

See [REVIEW.md](REVIEW.md) for fixes, validation limits, and staging checks.

## Checks

```sh
php tests/regression.php
php tests/contact.php
php tests/menu.php
php tests/profile-socials.php
php tests/reset.php
find tutor-lms-suite -name '*.js' -exec node --check '{}' \;
```

## Build an installable archive

```sh
mkdir -p artifacts
zip -qr artifacts/tutor-lms-suite-1.0.5.zip tutor-lms-suite
```

Back up the database and test on staging before replacing the production plugin.

## WordPress/Tutor integration smoke checks

On a **disposable** WordPress site with Tutor and this suite activated, set
`WP_ENVIRONMENT_TYPE` to `local` or `development`, then run:

```sh
wp eval-file /path/to/Allinone/tests/wordpress-smoke.php
```

This uses real WordPress hooks and user metadata, creates/deletes a test user,
and checks registry/import/visibility and subscriber access. It does not simulate
browser form submission, checkout payments, bookings, or email delivery.
GitHub Actions runs the standalone suite on PHP 7.4 and 8.3; it does not run this
WordPress smoke test.
