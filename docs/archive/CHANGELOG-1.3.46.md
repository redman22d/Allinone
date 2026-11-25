# Tutor Learning Suite 1.3.46

## Offline Payment Details endpoint fix

- Registered `offline-payment-setup` as a hidden instructor dashboard endpoint (`show_ui => false`). The Payment Details page remains accessible from the existing tabs and does not add a duplicate sidebar item.
- Added a one-time rewrite-rule refresh when the offline-payment module version changes, so existing installations receive the new Tutor dashboard route after updating the plugin.
- Bumped the suite to 1.3.46 and the bundled offline-payment module to 1.6.11.

## Validation

- PHP syntax checks passed for the updated files.
- Dashboard route registration and template-map references were checked for matching slugs.
- Full package PHP/JavaScript syntax and ZIP integrity checks are recorded with the release build.
- Live route resolution still needs confirmation on the target WordPress/Tutor LMS installation.
