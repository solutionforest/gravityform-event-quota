# Changelog

## 1.0.4

- Add this changelog.
- Document which release zip to install. The folder inside `event-quota-for-gravity-forms-x.y.z.zip` is `gravityform-event-quota`. The GitHub **Source code (zip)** unpacks to a versioned folder, and WordPress treats that as a different plugin.

## 1.0.3

- Rename the plugin to Event Quota for Gravity Forms.
- Point the plugin URL at https://github.com/solutionforest/gravityform-event-quota.
- Check GitHub releases and offer updates on the Plugins screen.
- Build the installable zip in a GitHub Action when a `v*` tag is pushed.

## 1.0.2

- Add the author, author URL, and plugin URL.

## 1.0.0

- First release.
- Limit a form by Drop Down, Radio, and Checkbox combinations.
- Count one seat per combination, or the value of a Number or Quantity field.
- Disable a full choice and append the full message.
- Count Active entries, including unpaid Pending payments. Trash, Spam, and failed, refunded, voided, or cancelled payments release the seat.
- Check the quota again on the final submit.
