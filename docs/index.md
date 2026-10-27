# omnilab/ebird

The eBird API 2.0 (Cornell Lab of Ornithology: `https://api.ebird.org/v2/`) for
[omnisense/omnilab](https://github.com/glitchr-omnisense/omnilab): bird taxa by species code
without a key; with the user's key, taxa by name, a search, checklists, and the recent
observations around a point or in a region as occurrences. Not tested live beyond the taxonomy.

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

## Credentials

The taxonomy (`entity()` by species code) needs no key. Everything else - a taxon by name, the
search, checklists, observations - needs the user's eBird API key: an eBird account gives it at
[ebird.org/api/keygen](https://ebird.org/api/keygen), under the eBird API Terms of Use. It is
given as `key` and sent in the `X-eBirdApiToken` header. Without it those calls are refused before
they are made. Keep the key in an environment variable or the secrets vault.

## Options

The factory's options, with the defaults it applies (`EbirdSourceFactory`):

| Option | Type | Default | Required | Effect |
|---|---|---|---|---|
| `key` | `?string` | null | no | the user's eBird API key, sent as `X-eBirdApiToken`; null or empty: the taxonomy only, every other call refused (`InvalidConfigException`) before it is made |
| `locale` | `string` | `en` | no | the common names' language (eBird's locales: `en`, `fr`, `es`, `de`...) |
| `radius_km` | `float` | `25` | no | the circle around a `Point` for `occurrences()` when the query gives no `radius`; 50 at most |
| `mailto` | `?string` | null | no | a contact address, sent in the User-Agent |
| `user_agent` | `?string` | null | no | the whole User-Agent, in place of the one made from `mailto` |
| `throttle` | `float` | `0` | no | seconds left between two calls: eBird asks for reasonable volumes, no figure |
| `base_uri` | `string` | `https://api.ebird.org/v2/` | no | the API the source calls |

Per call, `occurrences()` reads the filters `OccurrenceQuery::TAXON` (a species code), `region`
(`EbirdSource::REGION`: `FR`, `US-NY`, `US-NY-109`) and `provisional`
(`EbirdSource::PROVISIONAL`); any other key is refused before any call ([Occurrences](#occurrences)).

## Capabilities

`capabilities()` answers `entity`, `search`, `occurrences`; an operation it does not answer throws `NotSupportedException` before any call.

| Capability | Supported | |
|---|---|---|
| `entity` | yes | a species code (`amerob`, `ebird:`, its page) without a key; a scientific or common name, or a checklist (`S123456789`), with the key; another scheme refused before any call |
| `search` | yes | eBird's search of names, one page; with the key only |
| `series` | no |  |
| `products` | no |  |
| `occurrences` | yes | the recent observations (the latest of each species at each location) around a `Point` or in a region, up to 30 days back, or a region's single past day; 10,000 at most, one page; with the key only |
| `datasets` | no |  |
| `jobs` | no |  |
| `open` | no |  |
| `structure` | no |  |
| `cross_references` | no | none: eBird gives no other base's id (Wikidata carries the species code, P3444) |
| `forecast` | no |  |
| `historical` | no |  |
| `area` | no | a box is refused before any call (a point and a radius, or a region) |
| `station` | no |  |
| `cloud_cover` | no |  |

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

## Example

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Omnilab\Ebird\EbirdSourceFactory;
use Omnilab\Exception\InvalidConfigException;
use Omnilab\Model\Point;
use Omnilab\Source\OccurrenceQuery;
use Symfony\Component\HttpClient\HttpClient;

$ebird = (new EbirdSourceFactory(HttpClient::create()))->create([
    'key' => getenv('EBIRD_API_KEY') ?: null,   // the user's key; none: the taxonomy only
    'locale' => 'fr',
    'mailto' => 'you@example.org',
]);

$robin = $ebird->entity('eurrob1');   // keyless: the taxonomy
echo $robin->label, ' (', $robin->id, '): ', $robin->value('common_name'), ', ', $robin->value('family'), ', ', $robin->value('category'), "\n";

try {
    $page = $ebird->occurrences(new OccurrenceQuery(new Point(48.5734, 7.7521), new DateTimeImmutable('-7 days'), radius: 10));
    foreach ($page as $observation) {
        echo '  ', $observation->value('observed_at'), ' ', $observation->value('common_name'), ' x', $observation->value('count'), "\n";
    }
} catch (InvalidConfigException $e) {
    echo $e->getMessage(), "\n";
}
```

Without a key (run on 2026-10-27: one call, the taxonomy; the observations refused before any
call):

```
Erithacus rubecula (ebird:eurrob1): Rougegorge familier, Muscicapidae, species
[ebird] An eBird API key (the "key" option, from ebird.org/api/keygen) is needed to list observations.
```

With a key the observations of the last week within 10 km of Strasbourg follow: not run here (no
key).

```yaml
# config/packages/omnilab.yaml
omnilab:
    sources:
        ebird: { factory: ebird, options: { key: '%env(default::EBIRD_API_KEY)%', locale: en, mailto: '%env(OMNILAB_MAILTO)%' } }
```

## Errors

Every call goes through `HttpSource`: a 404 or a 410 (and a 204) is null - an empty page for a list -; a 429 is a `RateLimitedException` (`retryAfter` from `Retry-After`); a 5xx, a time-out or a host that does not answer an `UnavailableException`; any other 4xx a `ProviderException` with its `status` and what the service said; an answer that is not JSON a `ProviderException`, a web page answered for JSON an `UnavailableException`.

Before any call: a call that needs the key without one (`InvalidConfigException`); an identifier
of another scheme, a box, both or neither of a point and a region, a radius over 50 km, a sort, a
filter not read, a period that ended before yesterday (but a region's single day), more than 30
days back (`NotSupportedException`); a taxon filter that is no species code
(`\InvalidArgumentException`). eBird's 403 (no key, a wrong one) is a `ProviderException`; an
unknown code is its `[]`, null.

## Verified, and not

Against the real service on 2026-10-16, without a key: the American Robin by its code (*Turdus
migratorius*, Turdidae, banding code AMRO); the European Robin in French (Rougegorge familier);
two codes in one taxonomy call; an unknown code (`[]`); the 403 of the calls that need a key.

**Not tested live**: everything that needs a key - a taxon by its name, the search, the
checklists, the observations around a point or in a region, a region's day. Their tests answer
responses constructed after eBird's documentation (`Tests/Constructed/`, whose README says so: the
taxa real, the places, counts, times and checklist ids placeholders). A user with a key should
check the shape of these answers before relying on them.

On 2026-10-27, without a key: the European Robin in French by its code, and the observations refused
before any call (the example above).

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by the Cornell Lab of Ornithology or eBird. The key is the user's, under the
[eBird API Terms of Use](https://www.birds.cornell.edu/home/ebird-api-terms-of-use/): a use that
is not commercial unless the Lab agrees, eBird and the Cornell Lab credited wherever the data are
shown, no redistribution as a dataset, reasonable volumes of calls, as the README's
[Terms of use](https://github.com/glitchr-omnisense/omnilab-ebird#terms-of-use) says. Whoever runs
the package is responsible for complying with them and for any use made of what it returns: see
the README's [Responsibility](https://github.com/glitchr-omnisense/omnilab-ebird#responsibility)
section.
