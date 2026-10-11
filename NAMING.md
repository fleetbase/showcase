# Screenshot naming

Every file this repo publishes is named by the same rule. The library on fleetbase.io was
named ad hoc as screenshots were taken, which left five different words for "a table of
things" and three for "the form that makes one"; `capture/naming-migration.json` is the map
that brings the existing files onto this scheme.

## The rule

```
<area>-<subject>-<view>[-<qualifier>][-google][-light].webp
```

| Part | Rule |
|---|---|
| `area` | The product surface, and **always** the folder the file lives in: `fleet-ops`, `iam`, `console`, `admin`, `developers`, `extensions`, `ledger`, `storefront`, `customer-portal`, `navigator-app`, `storefront-app`, `pallet` |
| `subject` | The resource. **Plural** for a collection (`orders-list`), **singular** for one record (`order-details`) |
| `view` | One word from the closed vocabulary below — never a synonym |
| `qualifier` | Only when two screenshots of the same view differ meaningfully: `-multi-stop`, `-s3`, `-smtp`, `-empty` |
| `-google` | The Google Maps variant of a live-map screen; Leaflet is unsuffixed |
| `-light` | The light-theme variant; dark is unsuffixed, matching the published library |

Mobile app screenshots add one folder level for the platform, `storefront-app/ios/` and `storefront-app/android/`, and otherwise follow the same rule: `storefront-app-product-details.webp`, `storefront-app-store-details-network-light.webp`.

### The view vocabulary

| Word | The screen it names | Replaces |
|---|---|---|
| `list` | An index of records | `-management`, `-overview`, `-inventory`, `-log`, `-table-list`, bare plurals |
| `details` | One record's panel or page | — |
| `create` | The form or dialog that makes one | `create-<x>`, `<x>-new`, `<x>-add-new` |
| `edit` | The form that changes one | `edit-<x>` |
| `settings` | A settings page | bare nouns (`admin-queue`) |
| `map`, `board`, `calendar`, `flow`, `builder`, `editor`, `import` | An alternate presentation of a resource | `-kanban`, `-panel`, `-page` |
| `menu`, `dialog`, `filters` | A transient overlay | — |

A **tab inside a details view** is named `<area>-<subject>-<tab>`, not
`<subject>-details-<tab>`: `fleet-ops-trailer-connections`, `fleet-ops-driver-positions`,
`fleet-ops-place-operations`.

### Three things the rule forbids

1. **Verb-first names.** `fleet-ops-order-create`, never `fleet-ops-create-order` — so every
   screenshot of a resource sorts together in a directory listing.
2. **Bare numbers.** `fleet-ops-orchestrator-1` says nothing; `fleet-ops-orchestrator-run` and
   `fleet-ops-orchestrator-workbench` say which screen you are looking at. Numbers are allowed
   only for genuinely ordered steps (`console-onboard-workspace-3`).
3. **Fixture detail in the name.** `fleet-ops-uk-order` names the data, not the screen, and
   stops being true the moment the fixtures change.

`customer-portal/` already followed all of this before the convention was written, and is the
reference example.

## Applying it

- **New screenshots:** the `file` in a `capture/manifests/*.mjs` entry is the name. Nothing
  else needs to change — the file name *is* the published name.
- **Existing library:** `capture/naming-migration.json` maps 95 old names to new ones, by
  area. The fleetbase.io migration renames the files, rewrites every reference in
  `content/docs` and `src/app`, and adds a permanent redirect per old path so external
  hotlinks — the monorepo README links nine of these — keep resolving.

## Deliberately not renamed

These need someone who can see the screens side by side, and a wrong name is worse than an
inconsistent one:

| File(s) | Question |
|---|---|
| `fleet-ops-scheduler`, `fleet-ops-scheduler-2` | Which is the order scheduler and which is the fleet schedule? Their alt text disagrees with the route names |
| `fleet-ops-track-order`, `fleet-ops-order-tracking-page` | Two names for what looks like one public tracking page |
| `fleet-ops-uk-order` | Superseded by the Singapore hero — delete rather than rename? |
| `storefront-settings`, `storefront-settings-overview` | Appear to be the same General Settings page at different crops |
| `storefront-{catalogs,food-trucks,orders,products}-{list,overview}` | Four `-overview`/`-list` pairs; if `-overview` is a distinct dashboard view it keeps a name, otherwise it is a duplicate |
