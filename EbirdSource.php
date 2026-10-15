<?php

namespace Omnilab\Ebird;

use Omnilab\Exception\InvalidConfigException;
use Omnilab\Exception\NotSupportedException;
use Omnilab\Model\Domain;
use Omnilab\Model\Entity;
use Omnilab\Model\Identifier;
use Omnilab\Model\Identifiers;
use Omnilab\Model\Point;
use Omnilab\Model\Property;
use Omnilab\Model\Scheme;
use Omnilab\Source\Capability;
use Omnilab\Source\HttpSource;
use Omnilab\Source\OccurrenceQuery;
use Omnilab\Source\Page;
use Omnilab\Source\Query;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * eBird (the Cornell Lab of Ornithology), through the eBird API 2.0
 * (api.ebird.org/v2): a bird taxon by its species code, in the
 * eBird/Clements taxonomy - its scientific and common names (in a
 * locale), category, taxonomic order, family, banding codes -, keyless; and,
 * with the user's eBird API key, a taxon by its name, a search of names, a
 * checklist by its id, and the recent observations around a point or in a
 * region, or a region's observations of one day (occurrences()).
 *
 * The key is the user's to obtain from eBird (ebird.org/api/keygen, with
 * an eBird account, under eBird's API terms) and to pass as the "key"
 * option: it is sent as X-eBirdApiToken. Without it, the calls that need
 * it are refused before any call.
 */
final class EbirdSource extends HttpSource
{
    public const BASE_URI = 'https://api.ebird.org/v2/';

    /** eBird's limits: a radius of 50 km, 30 days back, 10,000 observations a call. */
    public const MAX_RADIUS_KM = 50;
    public const MAX_DAYS_BACK = 30;
    public const MAX_RESULTS = 10000;

    /** eBird's own filter: a region code (a country, FR; a subnational one, US-NY; a county, US-NY-109). */
    public const REGION = 'region';

    /** eBird's own filter: include the observations not yet reviewed (provisional); false by default. */
    public const PROVISIONAL = 'provisional';

    public function __construct(
        HttpClientInterface $http,
        private readonly ?string $key = null,
        private readonly string $locale = 'en',
        private readonly float $radius = 25.0,
        string $baseUri = self::BASE_URI,
        array $headers = [],
        float $throttle = 0.0,
    ) {
        parent::__construct($http, $baseUri, null === $key ? $headers : $headers + ['X-eBirdApiToken' => $key], $throttle);
    }

    public function getName(): string
    {
        return 'ebird';
    }

    public function capabilities(): array
    {
        return [Capability::ENTITY, Capability::SEARCH, Capability::OCCURRENCES];
    }

    /**
     * A taxon by its species code (amerob, ebird:amerob, its page), keyless;
     * by its scientific or common name (the key: taxon/find, then the
     * taxonomy); a checklist by its id (S123456789, the key). One call, two
     * for a name.
     */
    public function entity(Identifier|string $id): ?Entity
    {
        $schemes = [Scheme::EBIRD, Scheme::EBIRD_CHECKLIST];
        if ($id instanceof Identifier) {
            $identifier = \in_array($id->scheme, $schemes, true) ? $id : throw NotSupportedException::identifier('ebird', (string) $id);
        } else {
            $text = trim($id);
            $identifier = Identifier::parse($text) ?? (preg_match('~^S\d{4,12}$~i', $text) ? Identifier::tryOf(Scheme::EBIRD_CHECKLIST, $text) : (preg_match('~^[a-z][a-z0-9]{3,11}$~', $text) ? Identifier::tryOf(Scheme::EBIRD, $text) : null));
            if (null !== $identifier && !\in_array($identifier->scheme, $schemes, true)) {
                throw NotSupportedException::identifier('ebird', $id);
            }
            if (null === $identifier) {
                return '' === $text ? null : $this->byName($text);
            }
        }
        if (Scheme::EBIRD_CHECKLIST === $identifier->scheme) {
            $this->needKey('read a checklist');
            $checklist = $this->getJson('product/checklist/view/'.$identifier->value);

            return \is_array($checklist) && isset($checklist['subId']) ? $this->toChecklist($checklist) : null;
        }
        $taxon = $this->getJson('ref/taxonomy/ebird', ['fmt' => 'json', 'species' => $identifier->value, 'locale' => $this->locale])[0] ?? null;

        return \is_array($taxon) && isset($taxon['speciesCode']) ? $this->toTaxon($taxon) : null;
    }

    /**
     * eBird's search of names (ref/taxon/find, the one of its site's
     * search box: common and scientific names, in the locale), its order,
     * one page; the key. A domain other than taxonomy answers nothing.
     */
    public function search(Query $query): Page
    {
        $text = trim($query->text);
        if ((null !== $query->domain && Domain::TAXONOMY !== $query->domain) || '' === $text || null !== $query->cursor) {
            return new Page();
        }
        $this->needKey('search');
        $found = \array_slice($this->getJson('ref/taxon/find', ['locale' => $this->locale, 'q' => $text]) ?? [], 0, max(1, $query->limit));
        $codes = array_values(array_filter(array_map(static fn (mixed $f) => \is_array($f) && \is_string($f['code'] ?? null) ? $f['code'] : null, $found)));
        if (!$codes) {
            return new Page();
        }
        $taxa = $this->getJson('ref/taxonomy/ebird', ['fmt' => 'json', 'species' => implode(',', $codes), 'locale' => $this->locale]) ?? [];
        $byCode = [];
        foreach ($taxa as $t) {
            if (\is_array($t) && isset($t['speciesCode'])) {
                $byCode[$t['speciesCode']] = $this->toTaxon($t);
            }
        }

        return new Page(array_values(array_filter(array_map(static fn (string $c) => $byCode[$c] ?? null, $codes))));
    }

    /**
     * Observations, with the key. Around a Point (the query's radius, the
     * configured one - 25 km - by default, 50 at most): eBird's recent
     * observations (data/obs/geo/recent), back from now to the query's
     * start, 30 days at most - the latest observation of each species at
     * each location, not every one. In a region (filter "region": FR,
     * US-NY): its recent observations likewise, or, when the period is one
     * day (from and to on the same date), that day's (historic). Filters:
     * OccurrenceQuery::TAXON (a species code: that species only), region,
     * provisional. No BBox, no sort (eBird's order), one page.
     */
    public function occurrences(OccurrenceQuery $query): Page
    {
        $filters = $this->filters($query, OccurrenceQuery::TAXON, self::REGION, self::PROVISIONAL);
        if (null !== $query->sort) {
            throw NotSupportedException::operation('ebird', \sprintf('sort observations by "%s" (eBird answers its own order)', $query->sort));
        }
        if (null !== $query->place && !$query->place instanceof Point) {
            throw NotSupportedException::operation('ebird', 'take a box (it reads a point and a radius, or a region)');
        }
        $region = isset($filters[self::REGION]) ? strtoupper(trim((string) $filters[self::REGION])) : null;
        if ((null === $region) === (null === $query->place)) {
            throw NotSupportedException::operation('ebird', 'list observations without a point or a region, or with both');
        }
        $species = $this->species($filters[OccurrenceQuery::TAXON] ?? null);
        $radius = $query->radius ?? $this->radius;
        if ($radius > self::MAX_RADIUS_KM) {
            throw NotSupportedException::operation('ebird', \sprintf('list observations within %s km (eBird stops at %d)', $radius, self::MAX_RADIUS_KM));
        }
        $this->needKey('list observations');
        $common = ['includeProvisional' => (bool) ($filters[self::PROVISIONAL] ?? false), 'sppLocale' => $this->locale, 'maxResults' => max(1, min(self::MAX_RESULTS, $query->limit))];
        $day = null !== $query->from && null !== $query->to && $query->from->format('Y-m-d') === $query->to->format('Y-m-d') ? $query->from : null;
        if (null !== $region && null !== $day) {
            $path = 'data/obs/'.rawurlencode($region).'/historic/'.$day->format('Y/n/j');
            $parameters = $common + ['rank' => 'mrec', 'detail' => 'simple'];
            unset($parameters['maxResults']);
            $records = $this->getJson($path, $parameters) ?? [];
            if (null !== $species) {
                $records = array_values(array_filter($records, static fn (mixed $r) => \is_array($r) && ($r['speciesCode'] ?? null) === $species));
            }
            $records = \array_slice($records, 0, max(1, $query->limit));
        } else {
            $back = $this->back($query);
            $path = null !== $region ? 'data/obs/'.rawurlencode($region).'/recent' : 'data/obs/geo/recent';
            $path .= null !== $species ? '/'.rawurlencode($species) : '';
            $place = $query->place instanceof Point ? ['lat' => round($query->place->latitude, 2), 'lng' => round($query->place->longitude, 2), 'dist' => (int) ceil($radius)] : [];
            $records = $this->getJson($path, $place + ['back' => $back] + $common) ?? [];
        }
        $entities = array_values(array_filter(array_map($this->toObservation(...), array_values(array_filter($records, static fn (mixed $r) => \is_array($r) && isset($r['speciesCode']) && null !== Identifier::tryOf(Scheme::EBIRD_CHECKLIST, $r['subId'] ?? null)))), static fn (Entity $e) => null !== $e->time && null !== $e->place));

        return new Page($entities);
    }

    private function needKey(string $what): void
    {
        if (null === $this->key || '' === $this->key) {
            throw new InvalidConfigException(\sprintf('[ebird] An eBird API key (the "key" option, from ebird.org/api/keygen) is needed to %s.', $what));
        }
    }

    /** The days back a recent query reaches: from now to its start, 1 to 30; a period ending before today is refused. */
    private function back(OccurrenceQuery $query): int
    {
        $now = new \DateTimeImmutable();
        if (null !== $query->to && $query->to < $now->modify('-1 day')) {
            throw NotSupportedException::operation('ebird', 'list the recent observations of a period that ended before yesterday (a region\'s single past day is read whole: from and to on that date)');
        }
        if (null === $query->from) {
            return 14;
        }
        $days = (int) ceil(($now->getTimestamp() - $query->from->getTimestamp()) / 86400);
        if ($days > self::MAX_DAYS_BACK) {
            throw NotSupportedException::operation('ebird', \sprintf('go back %d days (eBird\'s recent observations stop at %d)', $days, self::MAX_DAYS_BACK));
        }

        return max(1, $days);
    }

    private function species(mixed $taxon): ?string
    {
        if (null === $taxon) {
            return null;
        }
        if ($taxon instanceof Identifier) {
            return Scheme::EBIRD === $taxon->scheme ? $taxon->value : throw NotSupportedException::identifier('ebird', (string) $taxon, 'filter observations by');
        }

        return Identifier::tryOf(Scheme::EBIRD, (string) $taxon)?->value ?? throw new \InvalidArgumentException(\sprintf('"%s" is no eBird species code.', (string) $taxon));
    }

    private function byName(string $name): ?Entity
    {
        $this->needKey('look a taxon up by its name');
        $lower = mb_strtolower($name);
        foreach ($this->getJson('ref/taxon/find', ['locale' => $this->locale, 'q' => $name]) ?? [] as $found) {
            // "American Robin - Turdus migratorius": either side, exactly.
            $names = array_map(static fn (string $n) => mb_strtolower(trim($n)), explode(' - ', (string) ($found['name'] ?? '')));
            if (\is_string($found['code'] ?? null) && \in_array($lower, $names, true)) {
                return $this->entity(Identifier::of(Scheme::EBIRD, $found['code']));
            }
        }

        return null;
    }

    /** @param array<string, mixed> $t a record of eBird's taxonomy */
    private function toTaxon(array $t): Entity
    {
        $code = (string) $t['speciesCode'];
        $name = self::text($t['sciName'] ?? null) ?? $code;
        $common = self::text($t['comName'] ?? null);
        $properties = [];
        $add = static function (string $key, string $label, string|float|null $value) use (&$properties): void {
            if (null !== $value && '' !== $value) {
                $properties[] = new Property($key, $label, $value, 'ebird');
            }
        };
        $add('taxon_name', 'Taxon name', $name);
        $add('common_name', 'Common name', $common);
        $add('category', 'Category (eBird)', self::text($t['category'] ?? null));
        $add('taxonomic_order', 'Taxonomic order (eBird)', is_numeric($t['taxonOrder'] ?? null) ? (float) $t['taxonOrder'] : null);
        $add('order', 'Order', self::text($t['order'] ?? null));
        $add('family', 'Family', self::text($t['familySciName'] ?? null));
        $add('family_common_name', 'Family (common name)', self::text($t['familyComName'] ?? null));
        $add('banding_codes', 'Banding codes', \is_array($t['bandingCodes'] ?? null) && $t['bandingCodes'] ? implode(', ', $t['bandingCodes']) : null);
        $add('report_as', 'Reported as', self::text($t['reportAs'] ?? null));

        return new Entity(
            id: Identifier::of(Scheme::EBIRD, $code),
            label: $name,
            domain: Domain::TAXONOMY,
            kind: self::text($t['category'] ?? null),
            synonyms: null === $common ? [] : [$common],
            properties: $properties,
            url: 'https://ebird.org/species/'.$code,
            source: 'ebird',
            related: array_filter(['parent' => Identifiers::of(isset($t['reportAs']) ? Identifier::tryOf(Scheme::EBIRD, $t['reportAs']) : null)], static fn (Identifiers $ids) => \count($ids) > 0),
        );
    }

    /**
     * An observation: the latest of its species at its location in the
     * query, a line of a checklist. eBird gives such a line no public id of
     * its own: the entity is keyed by its checklist (ebird.checklist), which
     * the other species of that checklist share - occurrences stay out of
     * the Merger. eBird writes its time in the location's local time without an
     * offset: the entity's time is its day at midnight UTC, the local time
     * in observed_at.
     *
     * @param array<string, mixed> $o
     */
    private function toObservation(array $o): Entity
    {
        $code = (string) $o['speciesCode'];
        $name = self::text($o['sciName'] ?? null) ?? $code;
        $properties = [];
        $add = static function (string $key, string $label, string|int|bool|null $value) use (&$properties): void {
            if (null !== $value && '' !== $value) {
                $properties[] = new Property($key, $label, $value, 'ebird');
            }
        };
        $add('taxon_name', 'Taxon name', $name);
        $add('common_name', 'Common name', self::text($o['comName'] ?? null));
        $add('observed_at', 'Observed at (local time of the place)', self::text($o['obsDt'] ?? null));
        $add('count', 'How many', is_numeric($o['howMany'] ?? null) ? (int) $o['howMany'] : null);
        $add('location', 'Location', self::text($o['locName'] ?? null));
        $add('location_id', 'Location (eBird id)', self::text($o['locId'] ?? null));
        $add('location_private', 'Private location', isset($o['locationPrivate']) ? (bool) $o['locationPrivate'] : null);
        $add('valid', 'Valid', isset($o['obsValid']) ? (bool) $o['obsValid'] : null);
        $add('reviewed', 'Reviewed', isset($o['obsReviewed']) ? (bool) $o['obsReviewed'] : null);
        $day = \is_string($o['obsDt'] ?? null) && preg_match('~^(\d{4}-\d{2}-\d{2})~', $o['obsDt'], $m) ? new \DateTimeImmutable($m[1].' 00:00:00', new \DateTimeZone('UTC')) : null;
        $place = is_numeric($o['lat'] ?? null) && is_numeric($o['lng'] ?? null) ? new Point((float) $o['lat'], (float) $o['lng']) : null;
        $checklist = Identifier::tryOf(Scheme::EBIRD_CHECKLIST, $o['subId'] ?? null);

        return new Entity(
            id: $checklist ?? throw new \LogicException('An observation without its checklist.'),
            label: $name.' - '.implode(', ', array_filter([self::text($o['locName'] ?? null), $day?->format('Y-m-d')])),
            domain: Domain::TAXONOMY,
            kind: 'observation',
            properties: $properties,
            url: 'https://ebird.org/checklist/'.$checklist->value,
            time: $day,
            place: $place,
            source: 'ebird',
            related: array_filter(['taxon' => Identifiers::of(Identifier::tryOf(Scheme::EBIRD, $code))], static fn (Identifiers $ids) => \count($ids) > 0),
        );
    }

    /** @param array<string, mixed> $c a checklist of product/checklist/view */
    private function toChecklist(array $c): Entity
    {
        $id = (string) $c['subId'];
        $species = [];
        foreach ($c['obs'] ?? [] as $o) {
            if (\is_array($o) && null !== ($code = Identifier::tryOf(Scheme::EBIRD, $o['speciesCode'] ?? null))) {
                $species[] = $code;
            }
        }
        $properties = [];
        foreach ([
            ['observed_at', 'Observed at (local time of the place)', self::text($c['obsDt'] ?? null)],
            ['location_id', 'Location (eBird id)', self::text($c['locId'] ?? null)],
            ['protocol', 'Protocol', self::text($c['protocolId'] ?? null)],
            ['duration', 'Duration (minutes)', is_numeric($c['durationHrs'] ?? null) ? (int) round($c['durationHrs'] * 60) : null],
            ['species_count', 'Species', is_numeric($c['numSpecies'] ?? null) ? (int) $c['numSpecies'] : null],
            ['observers', 'Observers', is_numeric($c['numObservers'] ?? null) ? (int) $c['numObservers'] : null],
            ['recorded_by', 'Recorded by', self::text($c['userDisplayName'] ?? null)],
            ['complete', 'All species reported', isset($c['allObsReported']) ? (bool) $c['allObsReported'] : null],
        ] as [$key, $label, $value]) {
            if (null !== $value && '' !== $value) {
                $properties[] = new Property($key, $label, $value, 'ebird');
            }
        }
        $day = \is_string($c['obsDt'] ?? null) && preg_match('~^(\d{4}-\d{2}-\d{2})~', $c['obsDt'], $m) ? new \DateTimeImmutable($m[1].' 00:00:00', new \DateTimeZone('UTC')) : null;

        return new Entity(
            id: Identifier::of(Scheme::EBIRD_CHECKLIST, $id),
            label: 'eBird checklist '.$id.(null === $day ? '' : ', '.$day->format('Y-m-d')),
            domain: Domain::TAXONOMY,
            kind: 'checklist',
            properties: $properties,
            url: 'https://ebird.org/checklist/'.$id,
            time: $day,
            source: 'ebird',
            related: array_filter(['species' => new Identifiers($species)], static fn (Identifiers $ids) => \count($ids) > 0),
        );
    }
}
