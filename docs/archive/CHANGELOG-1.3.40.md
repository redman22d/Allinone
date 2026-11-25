# Tutor Learning Suite 1.3.40

## Ads module 1.6.0
- New **ad order system**: `[ostudy_ad_order]` (order form + tracking tab) and `[ostudy_ad_track]` (tracking only).
- Guests receive an order number (`AD-XXXXXXXX`) and follow it with number + email/phone; logged-in users see "My orders".
- New **OStudy Ads > Orders** admin screen: status workflow, message to customer, internal notes, e-mail notifications,
  and one-click "Create ad from this order" (paused draft; order Live/Completed keeps the ad in sync).
- New settings: accept orders, order page URL, notification email, accent colour, max upload size.
- Modernised banner, sidebar and popup design; "Advertise here" hover link; RTL-safe CSS.
- Fix: automatic popups were mounted inside the hidden staging container and never displayed; they now mount on `<body>`.
- Database: new `{prefix}ostudy_ads_orders` table (created automatically on next admin load; DB version 1.6.0).
