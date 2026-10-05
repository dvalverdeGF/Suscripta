<?php

declare(strict_types=1);

namespace App\Tests\Unit\Processing\Application\Provider;

use App\Catalog\Domain\Entity\Provider;
use App\Catalog\Domain\Entity\ProviderParser;
use App\Mailbox\Application\Imap\ImapMessageHeader;
use App\Processing\Application\Provider\ExtractWithProviderParser;
use App\Processing\Application\Provider\ProviderParserRegistry;
use App\Processing\Domain\Provider\ProviderMatch;
use App\Processing\Domain\Provider\ProviderParseResult;
use App\Processing\Domain\Provider\ProviderParserContext;
use App\Processing\Domain\Provider\ProviderParserInterface;
use App\Shared\Application\Clock;
use App\Tests\Support\Catalog\InMemoryProviderParserRepository;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(ExtractWithProviderParser::class)]
final class ExtractWithProviderParserTest extends TestCase
{
    private InMemoryProviderParserRepository $parsers;
    private Provider $provider;
    private Clock $clock;

    protected function setUp(): void
    {
        $this->parsers = new InMemoryProviderParserRepository();
        $this->provider = new Provider('OVHcloud', 'ovhcloud', system: true);
        $this->clock = new Clock(new MockClock(new DateTimeImmutable('2026-10-05 10:00:00')));
    }

    public function testItReturnsNullForAnUnknownProvider(): void
    {
        $extractor = $this->extractor(['ovh' => $this->succeeding('ovh')]);

        self::assertNull($extractor($this->header(), ProviderMatch::unknown()));
    }

    public function testItReturnsNullWhenTheProviderHasNoParsers(): void
    {
        $extractor = $this->extractor(['ovh' => $this->succeeding('ovh')]);

        self::assertNull($extractor($this->header(), $this->known()));
    }

    public function testItReturnsTheResultOfTheParserThatRecognisesTheDocument(): void
    {
        $this->parsers->add(new ProviderParser($this->provider, 'ovh'));

        $extractor = $this->extractor(['ovh' => $this->succeeding('ovh')]);
        $result = $extractor($this->header(), $this->known());

        self::assertNotNull($result);
        self::assertSame('ovh', $result->parserKey);
        self::assertSame(2990, $result->amountMinor);
    }

    public function testASuccessIsRecordedOnTheParserRow(): void
    {
        $row = new ProviderParser($this->provider, 'ovh');
        $this->parsers->add($row);

        $extractor = $this->extractor(['ovh' => $this->succeeding('ovh')]);
        $extractor($this->header(), $this->known());

        self::assertSame(1, $row->getSuccessCount());
        self::assertSame(0, $row->getFailureCount());
        self::assertSame('2026-10-05', $row->getLastUsedAt()?->format('Y-m-d'));
    }

    public function testItTriesTheNextParserWhenTheFirstOneDoesNotRecogniseTheDocument(): void
    {
        $this->parsers->add(new ProviderParser($this->provider, 'antiguo'));
        $this->parsers->add(new ProviderParser($this->provider, 'ovh'));

        $extractor = $this->extractor([
            'antiguo' => $this->failing('antiguo'),
            'ovh' => $this->succeeding('ovh'),
        ]);

        $result = $extractor($this->header(), $this->known());

        self::assertNotNull($result);
        self::assertSame('ovh', $result->parserKey);
    }

    public function testTwoLiveTemplatesDoNotAccumulateFalseFailures(): void
    {
        $old = new ProviderParser($this->provider, 'antiguo');
        $new = new ProviderParser($this->provider, 'ovh');
        $this->parsers->add($old);
        $this->parsers->add($new);

        $extractor = $this->extractor([
            'antiguo' => $this->failing('antiguo'),
            'ovh' => $this->succeeding('ovh'),
        ]);

        $extractor($this->header(), $this->known());

        self::assertSame(0, $old->getFailureCount(), 'Un parser que no reconoce esta plantilla no ha fallado.');
        self::assertSame(1, $new->getSuccessCount());
    }

    public function testAFailureIsRecordedOnlyWhenNoParserSucceeded(): void
    {
        $row = new ProviderParser($this->provider, 'ovh');
        $this->parsers->add($row);

        $extractor = $this->extractor(['ovh' => $this->failing('ovh')]);

        self::assertNull($extractor($this->header(), $this->known()));
        self::assertSame(1, $row->getFailureCount());
        self::assertSame(0, $row->getSuccessCount());
    }

    public function testAParserThatKeepsFailingEndsUpDisabled(): void
    {
        $row = new ProviderParser($this->provider, 'ovh');
        $this->parsers->add($row);

        $extractor = $this->extractor(['ovh' => $this->failing('ovh')]);

        for ($i = 0; $i < 5; ++$i) {
            $extractor($this->header(), $this->known());
        }

        self::assertFalse($row->isEnabled(), 'Tras cinco fallos sin aciertos es más honesto dejar de intentarlo.');
    }

    public function testAnOrphanKeyIsSkippedAndNotCountedAsAFailure(): void
    {
        $orphan = new ProviderParser($this->provider, 'parser-que-ya-no-existe');
        $this->parsers->add($orphan);

        $extractor = $this->extractor(['ovh' => $this->succeeding('ovh')]);

        self::assertNull($extractor($this->header(), $this->known()));
        self::assertSame(0, $orphan->getFailureCount(), 'Una fila huérfana no es un fallo del parser.');
    }

    public function testItSkipsADisabledParser(): void
    {
        $disabled = new ProviderParser($this->provider, 'ovh');
        $disabled->disable();
        $this->parsers->add($disabled);

        $extractor = $this->extractor(['ovh' => $this->succeeding('ovh')]);

        self::assertNull($extractor($this->header(), $this->known()));
    }

    public function testItPassesTheBodyAndTheProviderNameToTheParser(): void
    {
        $this->parsers->add(new ProviderParser($this->provider, 'ovh'));

        $seen = null;
        $parser = new class($seen) implements ProviderParserInterface {
            public function __construct(private ?ProviderParserContext &$seen)
            {
            }

            public function key(): string
            {
                return 'ovh';
            }

            public function parse(ProviderParserContext $context): ?ProviderParseResult
            {
                $this->seen = $context;

                return new ProviderParseResult(
                    parserKey: 'ovh',
                    confidence: 0.5,
                    amountMinor: 100,
                    currency: 'EUR',
                    invoiceNumber: null,
                    invoiceDate: null,
                    dueDate: null,
                    billingPeriod: null,
                    plan: null,
                    serviceName: null,
                    renewalDate: null,
                    signals: [],
                );
            }
        };

        $extractor = $this->extractor(['ovh' => $parser]);
        $extractor($this->header(), $this->known(), 'Importe total: 1,00 €');

        self::assertInstanceOf(ProviderParserContext::class, $seen);
        self::assertSame('Importe total: 1,00 €', $seen->bodyText);
        self::assertSame('OVHcloud', $seen->providerName);
    }

    public function testItPassesTheStoredConfigurationToTheParser(): void
    {
        $this->parsers->add(new ProviderParser($this->provider, 'ovh', config: ['currency' => 'USD']));

        $seen = null;
        $parser = new class($seen) implements ProviderParserInterface {
            public function __construct(private ?ProviderParserContext &$seen)
            {
            }

            public function key(): string
            {
                return 'ovh';
            }

            public function parse(ProviderParserContext $context): ?ProviderParseResult
            {
                $this->seen = $context;

                return null;
            }
        };

        $extractor = $this->extractor(['ovh' => $parser]);
        $extractor($this->header(), $this->known());

        self::assertInstanceOf(ProviderParserContext::class, $seen);
        self::assertSame('USD', $seen->configString('currency'));
    }

    /**
     * @param array<string, ProviderParserInterface> $parsers
     */
    private function extractor(array $parsers): ExtractWithProviderParser
    {
        return new ExtractWithProviderParser(
            $this->parsers,
            new ProviderParserRegistry($parsers),
            $this->clock,
        );
    }

    private function known(): ProviderMatch
    {
        return new ProviderMatch($this->provider, null, 'domain', 100);
    }

    private function header(): ImapMessageHeader
    {
        return new ImapMessageHeader(
            uid: 1,
            messageId: '<x@y>',
            fromAddress: 'factures@ovh.com',
            fromName: 'OVHcloud',
            replyTo: null,
            toAddresses: ['yo@miempresa.com'],
            subject: 'Tu factura de OVHcloud',
            receivedAt: new DateTimeImmutable('2026-10-03 08:00:00'),
            sizeBytes: 2000,
            contentType: 'text/plain',
            attachmentNames: [],
            attachmentTypes: [],
        );
    }

    private function succeeding(string $key): ProviderParserInterface
    {
        return new class($key) implements ProviderParserInterface {
            public function __construct(private readonly string $key)
            {
            }

            public function key(): string
            {
                return $this->key;
            }

            public function parse(ProviderParserContext $context): ?ProviderParseResult
            {
                return new ProviderParseResult(
                    parserKey: $this->key,
                    confidence: 0.9,
                    amountMinor: 2990,
                    currency: 'EUR',
                    invoiceNumber: 'FRA-1',
                    invoiceDate: null,
                    dueDate: null,
                    billingPeriod: null,
                    plan: null,
                    serviceName: null,
                    renewalDate: null,
                    signals: [],
                );
            }
        };
    }

    private function failing(string $key): ProviderParserInterface
    {
        return new class($key) implements ProviderParserInterface {
            public function __construct(private readonly string $key)
            {
            }

            public function key(): string
            {
                return $this->key;
            }

            public function parse(ProviderParserContext $context): ?ProviderParseResult
            {
                return null;
            }
        };
    }
}
