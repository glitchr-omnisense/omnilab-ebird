# omnilab/ebird

> **Not tested live** for everything that needs an eBird API key - a taxon by its name, the search,
> the checklists and the observations: no key was used (a key needs an eBird account, and the
> API's terms are the user's to accept). Their tests answer responses constructed after eBird's
> documentation (`Tests/Constructed/`). The taxonomy by species code, which needs no key, was
> checked against the real service on 2026-10-16.

eBird for [omnisense/omnilab](https://github.com/glitchr-omnisense/omnilab): a bird taxon by its eBird
species code, in the eBird/Clements taxonomy - its scientific and common names (in a locale), its
category, order and family, its banding codes -, without a key; and, with the user's eBird API
key, a taxon by its name, a search of names, a checklist by its id, and the recent observations
around a point or in a region, or a region's observations of one day, in the core's fourth shape
(occurrences).

```php
$ebird = (new EbirdSourceFactory($http))->create(['key' => $_ENV['EBIRD_API_KEY'] ?? null, 'locale' => 'fr']);
$robin = $ebird->entity('eurrob1');               // or 'ebird:eurrob1', its page; no key needed
$robin->label;                                    // Erithacus rubecula
$robin->value('common_name');                     // Rougegorge familier

// With a key: the latest observation of each species at each location within 30 km of
// Strasbourg over the last seven days.
$page = $ebird->occurrences(new OccurrenceQuery(new Point(48.5734, 7.7521),
    new \DateTimeImmutable('-7 days'), radius: 30));
```

```yaml
omnilab:
    sources:
        ebird:
            factory: ebird
            options:
                key: '%env(EBIRD_API_KEY)%'   # the user's key, from ebird.org/api/keygen; none: the taxonomy only
                locale: en                    # the common names' language
                radius_km: 25                 # the circle around a Point (50 at most)
```

Without a key, what needs one is refused before any call (`InvalidConfigException`, naming where
the key is obtained). eBird gives no other base's id: a taxon is named in the core's `ebird`
scheme (Wikidata's P3444 holds it), an observation keyed by its checklist (`ebird.checklist`).

## Terms of use

The eBird API is the Cornell Lab of Ornithology's. A key is obtained by the user with an eBird
account, at [ebird.org/api/keygen](https://ebird.org/api/keygen), under the
[eBird API Terms of Use](https://www.birds.cornell.edu/home/ebird-api-terms-of-use/): among them,
use that is not commercial unless the Cornell Lab agrees otherwise, attribution of eBird and of
the Cornell Lab of Ornithology wherever the data are shown, no redistribution of the data as a
dataset, and reasonable volumes of calls (sensitive species are withheld by eBird). For research
use of the full dataset, the Lab offers the eBird Basic Dataset on request. Obtaining the key and
keeping to these terms are the user's.

See [docs/](docs/index.md) for what is read, how it maps, and what eBird does not give here.

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by the Cornell Lab of Ornithology and eBird. It reaches their service on behalf of whoever runs it, and it does not
redistribute their data beyond the short excerpts recorded as test fixtures. Its MIT licence
covers this package's code only and grants no right to the service or to its data.

Whoever installs and runs this package is responsible for complying with the terms above, for
obtaining whatever they require (an account, a key, a licence or subscription, an attribution or
a citation, a rate limit to respect), and for any use made of what it returns. The authors
publish it free of charge, make no commercial use of it, and accept no liability for how it is
used (see [LICENSE](LICENSE)).

License: MIT.
