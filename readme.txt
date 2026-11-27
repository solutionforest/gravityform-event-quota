=== Event Quota for Gravity Forms ===
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.3
License: GPLv2 or later

Limit Gravity Forms choices by field combination. A full choice is disabled and the full message is appended to its label.

== Setup ==

1. Install and activate Gravity Forms, then activate this plugin.
2. Open the form Settings, then Quota.
3. Select the Drop Down, Radio, or Checkbox fields that make up the quota.
4. Subtract 1 per combination, or subtract a Number / Quantity field.
5. Enter a limit for each combination. Leave it blank for unlimited. 0 means full. The full message is appended to the choice label.

== Counting ==

* Only Active entries count.
* Unpaid Pending payments keep a seat.
* Trash, Spam, Failed, Refunded, Voided, and Cancelled do not count.
* Editing an entry, changing the quantity, deleting an entry, or changing its status is reflected on the next check.
* When several checkbox options are selected, each matching combination is counted once, or each is counted by the number field.
* Submit checks the quota again so two people cannot take the last seat.
