<?php

namespace Omnilab\Ebird;

use Omnilab\Config;
use Omnilab\Source\SourceFactory;
use Omnilab\Source\SourceInterface;
use Symfony\Component\HttpClient\HttpClient;

/**
 * eBird: bird taxa by species code (keyless), and with the user's eBird API
 * key, taxa by name, a search, checklists and observations.
 *
 *   options:
 *     key: '%env(EBIRD_API_KEY)%'      # the user's eBird API key (ebird.org/api/keygen); none: the taxonomy only
 *     locale: en                       # the common names' language (eBird's locales: en, fr, es, de...)
 *     radius_km: 25                    # the circle around a Point, for occurrences() when the query gives none (50 at most)
 *     mailto: 'support@glitchr.io'     # in the User-Agent
 *     throttle: 0
 *     base_uri: 'https://api.ebird.org/v2/'
 */
final class EbirdSourceFactory extends SourceFactory
{
    protected function populate(Config $c): void
    {
        $c->defaults([
            'omnilab.factory_name' => 'ebird',
            'omnilab.required_options' => [],
            'key' => null,
            'locale' => 'en',
            'radius_km' => 25.0,
            'throttle' => 0.0,
            'base_uri' => EbirdSource::BASE_URI,
        ]);
    }

    protected function build(Config $c): SourceInterface
    {
        $key = $c->get('key');

        return new EbirdSource(
            $this->http ?? HttpClient::create(),
            null === $key || '' === $key ? null : (string) $key,
            (string) $c->get('locale'),
            (float) $c->get('radius_km'),
            (string) $c->get('base_uri', EbirdSource::BASE_URI),
            ['User-Agent' => self::userAgent($c)],
            (float) $c->get('throttle'),
        );
    }
}
