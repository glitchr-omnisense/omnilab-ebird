# omnilab/ebird

## Installation

```sh
composer require omnilab/ebird
```

```php
use Omnilab\Ebird\EbirdSourceFactory;

$ebird = (new EbirdSourceFactory($httpClient))->create([
    'key' => getenv('EBIRD_API_KEY') ?: null,  // the user's eBird API key; none: the taxonomy only
    'locale' => 'en',
]);
```

With the Symfony bundle, the factory is registered as `ebird` (see the core's docs); keep the key
in an environment variable or the secrets vault, never in the code.

## Which API

The eBird API 2.0, `https://api.ebird.org/v2/` (documentation:
[documenter.getpostman.com/view/664302/S1ENwy59](https://documenter.getpostman.com/view/664302/S1ENwy59)).
The key goes in the `X-eBirdApiToken` header. On 2026-10-16, without a key: `ref/taxonomy/ebird`
answered (200), `ref/taxon/find`, `data/obs/geo/recent` and `product/checklist/view` answered 403.

## Calls

| Method | Reads | eBird | Key |
|---|---|---|---|
| `entity()` | a species code: `amerob`, `ebird:amerob`, `https://ebird.org/species/amerob` | `ref/taxonomy/ebird?fmt=json&species=&locale=` | no |
| | a scientific or common name, exactly (`Turdus migratorius`, `American Robin`) | `ref/taxon/find?q=&locale=`, then the taxonomy | yes |
| | a checklist: `S123456789`, `ebird.checklist:S123456789`, its page | `product/checklist/view/{subId}` | yes |
| `search()` | `Query::$text`: eBird's search of names (its site's search box), one page, then one taxonomy call for the page | `ref/taxon/find`, `ref/taxonomy/ebird?species=a,b,c` | yes |
| `occurrences()` | an `OccurrenceQuery` (below) | `data/obs/...` | yes |

An unknown code is null (eBird answers `[]`). A bare string of lower-case letters and digits is a
code, `S` and digits a checklist, anything else a name. An identifier of another scheme is refused
before any call.

## What a taxon holds

`id` (`ebird:amerob`), `label` (its scientific name), `domain` taxonomy, `kind` (its category:
`species`, `issf`, `spuh`, `slash`, `hybrid`, `form`...), `synonyms` (its common name), `url`,
`related` (`parent`: the species a subspecies group is reported as), and: `taxon_name`,
`common_name` (in the `locale`), `category`, `taxonomic_order`, `order`, `family`,
`family_common_name`, `banding_codes`, `report_as`.

## Occurrences

| `OccurrenceQuery` | eBird |
|---|---|
| `place`: a `Point`, `radius` | `data/obs/geo/recent?lat=&lng=&dist=` (km, 50 at most; `radius_km`, 25, by default); latitude and longitude to two decimals, as eBird asks |
| `place`: none, filter `region` (`FR`, `US-NY`, `US-NY-109`) | `data/obs/{region}/recent` |
| `from` | `back`: the days from now to it, 1 to 30 (14 when none); further back refused |
| `to` | none or today; a period ending before yesterday is refused, but for a region's single day: |
| `from` and `to` on one date, with `region` | `data/obs/{region}/historic/{y}/{m}/{d}` (that day's observations) |
| `OccurrenceQuery::TAXON` | a species code or an `Identifier` of `ebird`: `.../recent/{code}`; on a historic day, the page filtered |
| `EbirdSource::PROVISIONAL` (`provisional`) | `includeProvisional` (false by default) |
| `limit` | `maxResults` (10,000 at most); one page, no cursor |

Refused before any call: a `BBox`, both or neither of a point and a region, a radius over 50 km, a
sort, any other filter. eBird's recent observations are **the latest of each species at each
location**, not every one. An observation holds: `id` (its checklist, `ebird.checklist:S...`:
eBird gives a line of a checklist no public id of its own, so the observations of one checklist
share it - occurrences stay out of the Merger), `kind` `observation`, `time` (its day at midnight
UTC: eBird writes the place's local time without an offset), `place`, `url` (its checklist),
`related` (`taxon`), and `taxon_name`, `common_name`, `observed_at` (the local time as eBird wrote
it), `count`, `location`, `location_id`, `location_private`, `valid`, `reviewed`.

A checklist holds `observed_at`, `location_id`, `protocol`, `duration` (minutes), `species_count`,
`observers`, `recorded_by`, `complete`, and its species related (`species`); its place is not read
(eBird gives its location's id; the hotspot's coordinates would be a second call).

## Verified, and not

Against the real service on 2026-10-16, without a key: the American Robin by its code (*Turdus
migratorius*, Turdidae, banding code AMRO); the European Robin in French (Rougegorge familier);
two codes in one taxonomy call; an unknown code (`[]`); the 403 of the calls that need a key.

**Not tested live**: everything that needs a key - a taxon by its name, the search, the
checklists, the observations around a point or in a region, a region's day. Their tests answer
responses constructed after eBird's documentation (`Tests/Constructed/`, whose README says so: the
taxa real, the places, counts, times and checklist ids placeholders). A user with a key should
check the shape of these answers before relying on them.
