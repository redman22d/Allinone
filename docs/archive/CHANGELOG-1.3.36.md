# 1.3.36 – payments go through the native Tutor cart and checkout
Tutor's cart and checkout only accept real courses, so each payable item uses a temporary hidden course that is deleted after use.

**Private sessions**
- Accepting a proposal puts the session in the student's Tutor cart and opens it; payment then happens at the Tutor checkout with the tutor's offline method (tioc).
- Falls back to the old pay-the-tutor-directly flow when Tutor's native cart is unavailable.
- The temporary course is deleted after payment, cancellation, rejection or timeout, together with the enrolment Tutor creates for it.

**Sponsorship / boosting**
- A request creates a temporary hidden course, adds it to the cart and redirects to the cart. Offline payment goes to the site owner; earnings stay 100% with the site.
- The custom pay page (gateway/offline form) is removed. "Pay now" re-adds the item to the cart; "Cancel" cancels the request.
- The temporary course is deleted when the order is paid, or when the request is cancelled, rejected or expires. Auto-cancel (1.3.35) still applies.
- A paid sponsorship is always set to active now (the "automatically activate" switch is gone). Activation happens the moment Tutor marks the order paid, not on the next page view. If the plan change overlaps a running plan, it is scheduled to start when that one ends, as before.
