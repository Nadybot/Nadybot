# Item data export format

This describes the item data Nadybot imports for a server. A complete,
working example for Rubi-Ka is available at <https://items.aobots.org/rk5-items.json>

## Files and delivery

| File | Content |
|---|---|
| `<name>.json` | The export described below |
| `<name>.json.version` | Only the version string of the export, e.g. `18.08.58.03` |
| `<name>-not-in-game.json` (optional) | JSON array of AOIDs that are not in the game, e.g. `[40082,41325]` |

- The bot checks the `.version` file once a day and downloads the export
  only if the version changed. Please change the version whenever the data
  changes.
- Please serve the files with HTTP compression (`Content-Encoding: gzip`).
  The bot requests it, there is no need for `.gz` files.
- The export is read as a stream, so formatting and whitespace do not matter.

## Structure

```json
{
  "version": "18.08.58.03",
  "families": [
    {
      "name": "Blood-Stained Mace",
      "items": [
        [[165125, 1], [165126, 199]],
        [[165127, 200]]
      ]
    }
  ],
  "templates": {
    "165125": {
      "ql": 1,
      "name": "Blood-Stained Mace",
      "types": ["Weapon"],
      "stats": {"0": 67108867, "30": 1644430341, "76": 1, "79": 13333, "101": 3, "210": 200, "294": 280, "298": 320}
    },
    "165126": {
      "ql": 199,
      "name": "Blood-Stained Mace",
      "types": ["Weapon"],
      "stats": {"0": 67108867, "30": 1644430341, "76": 1, "79": 13333, "101": 821, "210": 200, "294": 280, "298": 320}
    },
    "165127": {
      "ql": 200,
      "name": "Blood Mace",
      "types": ["Weapon"],
      "buffs": {"1": 200, "96": 150, "118": 25},
      "stats": {"0": 67108867, "30": 1644430341, "76": 1, "79": 13333, "101": 826, "210": 200, "294": 280, "298": 320}
    },
    "21601": {
      "ql": 1,
      "name": "Ammo: Flamethrower Ammunition",
      "stats": {"0": -2143288317, "30": 517, "76": 0, "79": 32168, "298": 0}
    }
  }
}
```

The family holds two items: the pair 165125/165126 for QL 1–199 and the
single template 165127 "Blood Mace" at QL 200. The name is given, because
the longest common sequence of words of the template names would only be
"Mace". Ammo 21601 is not in any family, so it becomes an item of its own
at QL 1 (see below).

The order of the top-level keys does not matter. Unknown keys are ignored.

### `templates`

One entry per AOID (the key, as a string).

| Key | Required | Content |
|---|---|---|
| `name` | yes | Name of the template |
| `ql` | yes, for items | Reference QL of the template |
| `stats` | no | `{"<stat id>": value}`, see below |
| `buffs` | no | `{"<stat id>": amount}` of what the item modifies when worn/used. If an attribute is modified several times, only the first value is used; a list like `[150, -50]` is accepted, everything after the first value is ignored. |
| `types` | no | List of categories the item is listed under in the buff search, e.g. `["Wrists"]`, `["Hud", "Util"]`; see below |
| `froob_friendly` | no | `false` if the item cannot be used without an expansion; default `true` |

Stats the bot uses, all under their official stat IDs:

| Stat | ID |
|---|---|
| Flags | 0 |
| Can (item properties) | 30 |
| ItemClass (0 Misc, 1 Weapon, 2 Armor, 3 Implant, 5 Spirit) | 76 |
| Icon | 79 |
| Slot | 298 |
| AttackDelay | 294 |
| RechargeDelay | 210 |
| BurstRecharge | 374 |
| FullAutoRecharge | 375 |
| MultiMelee | 101 |
| MultiRanged | 134 |
| Damage type | 436 |
| Price (optional) | 74 |

Any other stats may be included, they are ignored for now.

Possible `types`, an item can have several of them:

| Type | Items |
|---|---|
| `Head` | Helmets and other items for the head slot |
| `Neck` | Items for the neck slot |
| `Back` | Cloaks, backpacks and other items for the back slot |
| `Shoulders` | Shoulder pads |
| `Chest` | Body armor |
| `Arms` | Sleeves |
| `Wrists` | Bracers and bracelets |
| `Hands` | Gloves |
| `Fingers` | Rings |
| `Legs` | Pants |
| `Feet` | Boots |
| `Weapon` | Weapons and shields |
| `Hud` | Items for the HUD slots |
| `Util` | Items for the utility slots |
| `Deck` | NCU memory and belts for the deck slots |
| `Use` | Items that apply a buff when used, e.g. tutoring devices |
| `Contract` | Contracts, e.g. "Universal Advantage" |
| `Tower` | Buffs you get for owning a NW tower |

An item that can be worn in several slots lists all of them, e.g.
`["Hud", "Util"]` or `["Back", "Chest"]`.

### `families`

A family is a list of items that belong together, e.g. all QLs of a weapon.
An item is either

- `[[aoid, ql]]` — a single template at exactly this QL, or
- `[[lowid, lowql], [highid, highql]]` — a low/high pair covering the QL
  range from `lowql` to `highql` (inclusive). `lowid` and `highid` may be the
  same AOID if one template covers a range of QLs.

Items should be listed in ascending QL order. `name` is optional; without
it, the bot uses the longest common sequence of words of all template names
in the family.

Each AOID should appear in at most one family.

### Templates without a family

A template that is not used in any family becomes an item of its own, at
its reference QL. Such single items do not need to be listed in `families`.
This means the export should only contain templates of real, obtainable
items (no NPC attacks or other internal templates), or they will show up in
the item search.

## Ignored by the importer

These are not turned into items, so it does not matter whether they are
exported:

- Implants with clusters (`^.+ Implant.+(Shiny|Bright|Faded)`); empty
  implants and clusters are imported
- Blister packs and pills (`^(Blister Pack|Pill with)`)
- Names ending in `_` and three digits (`_\d{3}$`), e.g. `DefaultMelee_001`

Nano programs and nano effects are not part of the export for now.
