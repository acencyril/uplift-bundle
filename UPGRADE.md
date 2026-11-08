# Upgrading Uplift

What to do when moving from one minor version to the next. Every change is in
[CHANGELOG.md](CHANGELOG.md); this file lists only those that ask something of you.

## 0.5 → 0.6

### No own vendor by default

`UPLIFT_OWN_VENDOR` used to default to `acencyril`, the author's Composer vendor: when the
variable was not set, `acencyril/*` packages moved up to a minor on their own. It now
defaults to none, and every package follows `UPLIFT_PACKAGES`.

- **To keep the old behaviour, set the own vendor explicitly:**

  ```dotenv
  UPLIFT_OWN_VENDOR=acencyril
  ```

- If you publish packages yourself, name your own vendor instead (or as well):
  `UPLIFT_OWN_VENDOR=acme` or `UPLIFT_OWN_VENDOR=acme,acencyril`.
- If you had `UPLIFT_OWN_VENDOR=none` (or empty), nothing changes.

A project whose `.env` was written by the Flex recipe of 0.1 has
`UPLIFT_OWN_VENDOR=acencyril` there already: nothing changes for it either. Empty the value
if those packages should follow `UPLIFT_PACKAGES` like the others.

To check, `php bin/console uplift:plan --format=json` shows `policy.own_vendor` and, for each
move, its `category` (`own` when the own vendor applies, `other` otherwise).
