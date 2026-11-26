# Gravity Forms Event Quota

Limit a Gravity Forms form by the combination of choices a person selects. Each combination has its own limit. When a choice would go over that limit, only that choice is disabled and a full message is added to its label. The other choices stay available.

For example, **Date = 11/28** and **Time = Morning** can have a limit of 30, while **Date = 11/28** and **Time = Afternoon** can have a limit of 20.

Quota is stored per form. One form has one quota group.

## Requirements

- WordPress 6.0 or newer
- PHP 7.4 or newer
- Gravity Forms 2.6 or newer

## Setup

1. Install and activate Gravity Forms, then activate this plugin.
2. Open the form and go to **Settings → Quota**.
3. Turn the quota on and select the Drop Down, Radio, or Checkbox fields that define a combination.
4. Choose how a submission is counted:
   - **Subtract 1** for each selected combination, or
   - **Subtract the value** of one Number or Quantity field for each selected combination.
5. Fill in the combination table. Leave a limit blank for unlimited. `0` means that combination is already full.

The form checks the quota when those fields change. It checks again on submit, so two people cannot take the last seat.

## What counts

| Entry | Seat |
| --- | --- |
| Active, including an unpaid Pending payment | Held |
| No payment add-on | Held |
| Trash, Spam | Released |
| Failed, Refunded, Voided, Cancelled | Released |

Editing an entry, changing the quantity, deleting an entry, or changing its status is reflected the next time the form checks. Counts are read from current entries. There is no stored counter.

A checkbox uses one seat for each checked option, or the number-field value for each checked option. If that number is larger than the seats left, the option is marked full and disabled.

If the other fields in the combination have not been selected yet, an option is not marked full.

## Full message

The default suffix is `(Full)`, appended to the choice label, for example `Morning (Full)`. It is passed through `__()` with the text domain `gf-event-quota`, so a translation file in `languages/` can replace it.

A message typed into the combination table is saved as entered and is not translated.

## Limits

- At most 500 combinations. Above that, the settings page asks you to use fewer fields or choices and does not save the table.
- A missing number field falls back to subtracting 1, so an incomplete setup cannot oversell.
- Save & Continue does not enforce the quota. The final submission does.
