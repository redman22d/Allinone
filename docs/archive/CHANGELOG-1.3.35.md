# 1.3.35
- Abandoned sponsorship requests are now cancelled automatically.
- New setting (Sponsorship > Plans): "Auto-cancel unpaid requests after (days)", default 3, 0 = off.
- Runs in the hourly sponsorship cron. A request is cancelled only if it is still Pending, its native Tutor order is unpaid and incomplete, and no offline payment was submitted for it.
- The native Tutor order is set to Cancelled together with the sponsorship; the tutor gets the usual cancellation email.
