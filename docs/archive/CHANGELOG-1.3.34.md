# 1.3.34
Sponsorship (boosting) payments no longer depend on hidden or fake Tutor courses.

- The sponsorship record itself is now the item of a real native Tutor order (unpaid until paid). No course post is created, no cart is used, nothing is enrolled.
- New pay step in the Sponsorship area: Tutor's active payment gateways (Stripe, PayPal, manual methods...) and the offline-payment module's methods, with the site administrator as payee. Billing details are collected there when a gateway needs them.
- Sponsorship revenue is recorded as 100% site earnings (no instructor share, no tax line).
- The sponsorship activates when the native order is paid (gateway, or the admin confirming an offline payment). Reconciliation now matches the order's item to the sponsorship record.
- Unpaid requests show "Pay now" in My sponsorship status and can be cancelled.
- One-time upgrade: old hidden checkout products are moved to the trash (not deleted), existing Tutor orders/items that pointed at them are re-pointed to their sponsorship record, and the old product options/pending meta are removed.
- Boosting/ads features (tiers, carousel, stickers, tutor boost, manager) are unchanged.
