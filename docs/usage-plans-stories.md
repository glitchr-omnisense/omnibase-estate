---
title: Plans, stories and works
order: 31.5
---

# Plans, stories and works

Two things a home gets beside its lease: a **plan** of its rooms and surfaces, private, for its
tenants; and its **story** - its renovations told with before/after photos -, public only on the
**works page** (`/travaux`, the company's investments), with ready-to-post images for the networks.
A home's own page (`/biens/{slug}`) shows neither (Marco, 2026-11-01: « n'affiche pas les travaux
dans le bien final, ni les plans. Les plans sont pour les locataires, c'est privé »).

## The plan

### Rooms

`Entity\Room` (`estate_room`), a home's `Unit::getPlanRooms()` (`Unit::getRooms()` stays the number
of rooms it is let with):

| Field | |
|---|---|
| `name`, `kind` | `Enum\RoomKind`: living, dining, kitchen, bedroom, office, bathroom, shower, wc, entrance, corridor, storage, laundry, balcony, terrace, cellar, garage, other |
| `level` | 0 the home's own level, 1 upstairs, -1 the basement; each level is drawn apart |
| `polygon` | its corners in centimetres, snapped to a 10 cm grid (`Model\Polygon::GRID`) |
| `measuredSurface` | the figure measured on site, m²; it prevails over the drawn one and is shown as measured |
| `counted` | counted in the living area (Carrez); a new kind brings its default - balcony, terrace, cellar and garage are not |

`Room::getDrawnSurface()` (shoelace formula, two decimals), `getSurface()` (measured, else drawn),
`isMeasured()`.

### The living area and Unit::$surface

`Unit::getLivingArea()` is the sum of the counted rooms' surfaces, `null` while no room is drawn;
`getPlanSurface()` every room's. `Unit::$surface` stays what it was: the surface declared on the
lease and the listing. The plan never rewrites it. `Unit::getSurfaceGap()` is the living area less
the declared surface when they differ by half a square metre or more; the team sees it in the
editor and on the home's screen (« Surface des pièces »), tenants and the public never do.

### The editor

Back office › Biens › « Plan » (`estate_admin_plan`, `Controller\Admin\PlanController`),
`public/js/plan-editor.js` (no dependency; `assets:install` publishes it under `bundles/estate/`):

- draw a rectangle (R), move a room, drag a corner, double-click a wall for one more corner;
- select several rooms (Shift + click) and **merge** them into one outline (an L), or **split** a
  room along a vertical or horizontal line. Both work on the grid's cells: the rooms are filled
  cell by cell, joined or cut, and the outline traced back, so the result is always a clean polygon
  on the grid; a shape touching itself at a corner or with a hole is refused (make two rooms);
- name, kind, level, measured surface, counted; the totals and the gap with the declared surface
  live; undo (Ctrl+Z), zoom, pan, levels;
- an **architect's plan** underneath: an image, or a PDF's first page turned into a PNG at 150 dpi
  when Imagick reads PDFs (Ghostscript in the image, `Plans::readsPdf()`); scaled by two points
  and a known length (centimetres per image pixel), moved by an offset, its opacity.

Saved as JSON (`estate_admin_plan_save`, the `X-CSRF-Token` header): `Service\Plans::save()`
checks every room first (three corners at least, a surface, on the plan, 80 rooms at most) and
writes all or nothing; rooms sent with their id are updated, those left out deleted.

### Who sees it

A plan is private, always: there is no option to show it on the listing. `EstateVoter::UNIT_PLAN`:
the team, the tenant and co-tenants of the lease running today (not a guarantor, not a former
tenant), the associates of the home's company, its co-owners (ownership) and its owners under
management. Anyone else - a visitor, another tenant - gets a 404, as for a home without a plan.

- **The tenant**: a « Plan du logement » panel in `/locataire` - the SVG, each room labelled with its
  name and surface where they fit (its number otherwise), the rooms' table and the totals.
- `/plan/{id}` (`estate_unit_plan`) for the others, `/plan/{id}/imprimer` (`estate_unit_plan_print`):
  an A4 sheet with nothing around it.
- Never the public: not on `/biens/{slug}`, not anywhere.

`Model\FloorPlan::of($unit)` gives the pages each level's rooms with their outline, label point and
whether their name fits; `estate_plan(unit)` in Twig, the `m2` filter writes `12,4 m²`.

## The story

### Entries

`Entity\StoryEntry` (`estate_story_entry`): a home, a date, a title, a text, its photos, and:

- a state, `Enum\StoryState`: `in_progress` (works under way: `startedOn`, `plannedEndOn`) or `done`;
- the work orders it tells (`estate_story_entry_work_order`); `Stories::suggestedState()` says what
  their statuses suggest - done when every one is done, closed or cancelled - and the back office
  shows it; the team decides;
- `amount` (cents) and `showAmount`: the investment is public only when the team ticks it
  (`getPublicAmount()`), hidden by default;
- `published`: nothing of an entry is public until the team publishes it.

`Entity\StoryPhoto` (`estate_story_photo`): an image, a caption, `phase` (`Enum\PhotoPhase`: before,
during, after), `view` ("La cuisine": a before and an after of the same view make a pair,
`StoryEntry::getPairs()`; a photo during the works pairs with nothing, it shows the progress), and
`publishable`, private by default: a photo in a home lived in often shows its tenant's belongings
or people, so the team ticks « OK pour publier » photo by photo. `getPublicPhotos()`,
`getPublicPhotosOf($phase)` and `getPairs(true)` give only those.

### From the works

A work order's photos carry the same `phase` and `view` (`Document::$phase`, `Document::$view`): the
contractor picks them when sending a photo from `/artisan`, the team in « Documents des biens ».
Back office › Interventions › « Raconter » (`estate_admin_story_from_work`) makes the entry once
(`Stories::fromWorkOrder()`): in progress or done as the work order stands, started the day of the
visit, dated the day it was closed, its text the contractor's report, its amount the invoice (else
the quote), hidden; its photos copied from the work order's, each private. More work orders can
be linked on the entry's page (their photos come along, private too).

### No tenant's name

`Stories::tenantNames()` lists everyone who signed a lease of the home (tenants, co-tenants,
guarantors: first and last names, a surname of three letters or more). A text taken from the works
has them replaced by `[nom retiré]`; `Stories::publish()` refuses an entry whose title, text or a
photo's caption or view names one, or still carries the mark - the team rewrites it.

### The works page

`/travaux` (`estate_works`) is the only public place of the stories; a home's page shows no story,
no works and no before/after. It lists the works under way first (the home, what is done, started
on, the planned end, the amount when shown, a photo before and the progress photos), then the works
done (published entries telling work orders) with their before/after pairs as a slider
(`templates/client/_before_after.html.twig`, `public/js/before-after.js`,
`public/css/before-after.css`): without JavaScript the two photos side by side; with it one over the
other and a native range input between them (arrows, Page Up/Down, Home, End), one gentle sweep when
it comes into view except under `prefers-reduced-motion`. Each entry may link to its home's page.
The intro is the translation key `@estate.works.lead`: a site that edits its texts from the back
office (a site's « Textes du site ») rewrites it there.

## For the networks

The entry's page in the back office (`estate_admin_story`, « Photos et réseaux » on the stories'
screen) gives, from the pairs whose two photos are cleared:

- the images, made on the server by `Service\StoryImages` with Imagine (the core's own image
  library; Imagick when the extension is there, GD otherwise - no new dependency): the two photos
  side by side (one above the other in portrait) and a two-slide carousel (before, then after), in
  1080×1080, 1080×1350 and 1200×630, each with a band carrying the title, the place (district and
  city, never the address) and the company, and its mark. Cached in `%kernel.cache_dir%/estate_story`
  by what they are made of;
- all of them zipped with the suggested text (`estate_admin_story_zip`);
- a suggested text per network (`Stories::caption()`: the title and place, what was done cut at a
  sentence, the amount when shown, the company) and the public link with
  `utm_source=<network>&utm_medium=social&utm_campaign=histoire-<id>` (`Stories::url()`), on
  `/travaux` at the entry (`#histoire-<id>`).

`estate.story` configures the images: `font` and `font_regular` (TTF, OTF or WOFF; DejaVu Sans
otherwise), `mark` (a PNG or JPEG), `color` (the band's).

### Posting directly

Not done here. omnibase/social publishes through omnipost a film or a single picture
(`SocialPost`, `PostKind::IMAGE`), not a carousel, and needs a connected account (a Meta app, the
account as a tester), Messenger's async transport and its own tables. Posting an entry would take:
omnibase/social installed and configured in the site, a `SocialPost` made from the portrait pair
image and the caption (a carousel needs `SocialPost` to carry several pictures, omnipost's
contract already has carousels), behind a setting. Until then the team downloads the zip.
