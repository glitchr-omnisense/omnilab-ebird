<?php

namespace Omnilab\Ebird\Tests;

use Omnilab\Ebird\EbirdSource;
use Omnilab\Ebird\EbirdSourceFactory;
use Omnilab\Exception\InvalidConfigException;
use Omnilab\Exception\NotSupportedException;
use Omnilab\Model\BBox;
use Omnilab\Model\Domain;
use Omnilab\Model\Identifier;
use Omnilab\Model\Point;
use Omnilab\Model\Scheme;
use Omnilab\Source\Capability;
use Omnilab\Source\OccurrenceQuery;
use Omnilab\Source\Query;
use Omnilab\Source\SourceInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The keyless taxonomy's answers were recorded from api.ebird.org on
 * 2026-10-16 (Tests/Fixtures); the calls that need a key answer what
 * Tests/Constructed holds, constructed after eBird's documentation - no key
 * was used (see its README).
 */
final class EbirdSourceTest extends TestCase
{
    /** @var list<string> */
    private array $urls = [];

    /** @var list<array<string, list<string>>> the headers sent */
    private array $headers = [];

    private function source(array $options = []): SourceInterface
    {
        $fixtures = __DIR__.'/Fixtures';
        $index = json_decode((string) file_get_contents($fixtures.'/index.json'), true);
        $http = new MockHttpClient(function (string $method, string $url, array $o) use ($fixtures, $index): MockResponse {
            $this->urls[] = rawurldecode($url);
            $this->headers[] = $o['normalized_headers'] ?? [];
            $path = (string) parse_url($url, \PHP_URL_PATH);
            $constructed = match (true) {
                str_starts_with($path, '/v2/ref/taxon/find') => 'taxon-find-robin.json',
                str_starts_with($path, '/v2/data/obs/geo/recent') => 'obs-geo-recent.json',
                str_contains($path, '/historic/') => 'obs-historic.json',
                str_starts_with($path, '/v2/product/checklist/view/') => 'checklist.json',
                default => null,
            };
            if (null !== $constructed) {
                return new MockResponse((string) file_get_contents(__DIR__.'/Constructed/'.$constructed));
            }
            $name = self::fixture($method, $url, $o['body'] ?? null);
            if (!isset($index[$name])) {
                self::fail('No recorded answer for '.rawurldecode($url));
            }

            return new MockResponse((string) file_get_contents($fixtures.'/'.$name), ['http_code' => $index[$name]['status']]);
        });

        return (new EbirdSourceFactory($http))->create($options + ['mailto' => 'support@glitchr.io']);
    }

    private static function fixture(string $method, string $url, ?string $body): string
    {
        $parts = parse_url($url);
        $readable = strtolower(trim((string) preg_replace('~[^A-Za-z0-9]+~', '-', rawurldecode(($parts['path'] ?? '').'-'.($parts['query'] ?? ''))), '-'));

        return substr($readable, 0, 70).'-'.substr(md5($method.' '.$url.' '.$body), 0, 8).'.json';
    }

    private function keyed(): SourceInterface
    {
        return $this->source(['key' => 'test-key']);
    }

    public function testCapabilities(): void
    {
        self::assertSame([Capability::ENTITY, Capability::SEARCH, Capability::OCCURRENCES], $this->source()->capabilities());
    }

    public function testATaxonByItsCodeWithoutAKey(): void
    {
        $robin = $this->source()->entity('amerob');

        self::assertSame(['https://api.ebird.org/v2/ref/taxonomy/ebird?fmt=json&species=amerob&locale=en'], $this->urls);
        self::assertArrayNotHasKey('x-ebirdapitoken', $this->headers[0]);
        self::assertSame(['ebird:amerob', 'Turdus migratorius', Domain::TAXONOMY, 'species'], [(string) $robin->id, $robin->label, $robin->domain, $robin->kind]);
        self::assertSame(['American Robin', 'Passeriformes', 'Turdidae', 'Thrushes and Allies', 'AMRO', 28633.0], [$robin->value('common_name'), $robin->value('order'), $robin->value('family'), $robin->value('family_common_name'), $robin->value('banding_codes'), $robin->value('taxonomic_order')]);
        self::assertSame('https://ebird.org/species/amerob', $robin->url);
        self::assertSame('Rougegorge familier', $this->source(['locale' => 'fr'])->entity(Identifier::of(Scheme::EBIRD, 'eurrob1'))?->value('common_name'));
        self::assertNull($this->source()->entity('zzzzzz'), 'eBird answers []');
    }

    public function testWhatNeedsTheKeyIsRefusedWithoutOneBeforeAnyCall(): void
    {
        $source = $this->source();
        foreach ([
            static fn () => $source->entity('American Robin'),
            static fn () => $source->entity('S100000001'),
            static fn () => $source->search(new Query('robin')),
            static fn () => $source->occurrences(new OccurrenceQuery(new Point(48.57, 7.75))),
        ] as $call) {
            try {
                $call();
                self::fail('not refused');
            } catch (InvalidConfigException $e) {
                self::assertStringContainsString('ebird.org/api/keygen', $e->getMessage());
            }
        }
        self::assertSame([], $this->urls);
    }

    public function testTheKeyIsSentAsItsHeader(): void
    {
        $robin = $this->keyed()->entity('American Robin');

        self::assertSame(['https://api.ebird.org/v2/ref/taxon/find?locale=en&q=American Robin', 'https://api.ebird.org/v2/ref/taxonomy/ebird?fmt=json&species=amerob&locale=en'], $this->urls);
        self::assertSame(['X-eBirdApiToken: test-key'], $this->headers[0]['x-ebirdapitoken']);
        self::assertSame('ebird:amerob', (string) $robin->id);
        self::assertSame('ebird:amerob', (string) $this->keyed()->entity('Turdus migratorius')?->id, 'either side of eBird\'s name');
    }

    public function testRecentObservationsAroundAPoint(): void
    {
        $page = $this->keyed()->occurrences(new OccurrenceQuery(new Point(48.5734, 7.7521), new \DateTimeImmutable('-7 days'), radius: 30, limit: 100));

        self::assertSame('https://api.ebird.org/v2/data/obs/geo/recent?lat=48.57&lng=7.75&dist=30&back=7&includeProvisional=false&sppLocale=en&maxResults=100', $this->urls[0]);
        self::assertCount(2, $page, 'an observation without its checklist left out');
        $first = $page->first();
        self::assertSame(['ebird.checklist:S100000001', 'observation', 'Erithacus rubecula'], [(string) $first->id, $first->kind, $first->value('taxon_name')]);
        self::assertSame(['2026-10-15T00:00:00+00:00', '2026-10-15 08:30', 2], [$first->time->format(\DATE_ATOM), $first->value('observed_at'), $first->value('count')], 'a local time without an offset: its day, the time apart');
        self::assertEquals(new Point(48.58, 7.75), $first->place);
        self::assertSame(['ebird:eurrob1'], $first->related('taxon')->keys());
    }

    public function testOneSpeciesAndARegionsDay(): void
    {
        $this->keyed()->occurrences(new OccurrenceQuery(new Point(48.5734, 7.7521), filters: [OccurrenceQuery::TAXON => 'eurrob1']));
        self::assertStringStartsWith('https://api.ebird.org/v2/data/obs/geo/recent/eurrob1?lat=48.57&lng=7.75&dist=25&back=14', $this->urls[0]);

        $day = new \DateTimeImmutable('2026-10-01');
        $page = $this->keyed()->occurrences(new OccurrenceQuery(null, $day, $day, [EbirdSource::REGION => 'fr', OccurrenceQuery::TAXON => Identifier::of(Scheme::EBIRD, 'eurrob1')]));
        self::assertSame('https://api.ebird.org/v2/data/obs/FR/historic/2026/10/1?includeProvisional=false&sppLocale=en&rank=mrec&detail=simple', $this->urls[1]);
        self::assertSame(['ebird.checklist:S100000003'], array_map(static fn ($e) => (string) $e->id, $page->items), 'the species kept');
    }

    public function testWhatEbirdCannotAnswerIsRefusedBeforeAnyCall(): void
    {
        $source = $this->keyed();
        foreach ([
            new OccurrenceQuery(new BBox(7, 48, 8, 49)),
            new OccurrenceQuery(new Point(48.57, 7.75), radius: 80),
            new OccurrenceQuery(new Point(48.57, 7.75), new \DateTimeImmutable('-60 days')),
            new OccurrenceQuery(new Point(48.57, 7.75), new \DateTimeImmutable('2025-01-01'), new \DateTimeImmutable('2025-01-31')),
            new OccurrenceQuery(),
            new OccurrenceQuery(new Point(48.57, 7.75), sort: OccurrenceQuery::NEWEST),
            new OccurrenceQuery(new Point(48.57, 7.75), filters: [OccurrenceQuery::COUNTRY => 'FR']),
        ] as $query) {
            try {
                $source->occurrences($query);
                self::fail('not refused');
            } catch (NotSupportedException) {
            }
        }
        self::assertSame([], $this->urls);
    }

    public function testAChecklistAndASearch(): void
    {
        $checklist = $this->keyed()->entity('ebird.checklist:S100000001');
        self::assertSame(['checklist', 2, 90, true], [$checklist->kind, $checklist->value('species_count'), $checklist->value('duration'), $checklist->value('complete')]);
        self::assertSame(['ebird:eurrob1', 'ebird:amerob'], $checklist->related('species')->keys());

        $page = $this->keyed()->search(new Query('robin'));
        self::assertSame(['Turdus migratorius', 'Erithacus rubecula'], array_map(static fn ($e) => $e->label, $page->items));
        self::assertStringEndsWith('species=amerob,eurrob1&locale=en', $this->urls[2], 'one taxonomy call for the page');
    }
}
