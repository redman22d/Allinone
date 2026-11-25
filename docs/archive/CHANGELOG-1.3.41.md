# Tutor Learning Suite 1.3.41

## Ads module 1.6.1
- Admin-defined default payment instructions (Settings > Ad orders) and per-order amount due.
- Customers upload payment proof (image/PDF) from the tracking page; new status "Payment under review".
- Admin sees the proof on the order screen and is e-mailed when one arrives; status e-mail includes how to pay.
- Database: new payment columns on `{prefix}ostudy_ads_orders` (added automatically, DB version 1.6.1).
