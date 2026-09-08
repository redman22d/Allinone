# Tutor LMS Suite

WordPress/Tutor LMS plugin source is in `tutor-lms-suite/`.
The original supplied archive is preserved as `tutor-lms-suite (1).zip`.

See [REVIEW.md](REVIEW.md) for fixes, validation limits, and staging checks.

## Checks

```sh
php tests/regression.php
php tests/contact.php
php tests/menu.php
find tutor-lms-suite -name '*.js' -exec node --check '{}' \;
```

## Build an installable archive

```sh
mkdir -p artifacts
zip -qr artifacts/tutor-lms-suite-1.0.3.zip tutor-lms-suite
```

Back up the database and test on staging before replacing the production plugin.
