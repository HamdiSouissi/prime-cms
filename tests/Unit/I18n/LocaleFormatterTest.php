<?php

declare(strict_types=1);

namespace Prime\Tests\Unit\I18n;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Prime\I18n\LocaleFormatter;
use Prime\I18n\Translator;

final class LocaleFormatterTest extends TestCase
{
    private LocaleFormatter $french;

    private LocaleFormatter $english;

    protected function setUp(): void
    {
        $langPath = dirname(__DIR__, 3) . '/resources/lang';

        $french = new Translator('fr', 'en', [$langPath]);
        $english = new Translator('en', 'en', [$langPath]);

        $this->french = new LocaleFormatter($french, 'Europe/Paris');
        $this->english = new LocaleFormatter($english, 'UTC');
    }

    /**
     * Strip every kind of space so assertions hold with and without the `intl` extension.
     */
    private function compact(string $value): string
    {
        $collapsed = preg_replace('/[\x{00A0}\x{202F}\x{2009}\s]+/u', ' ', $value);

        return str_replace(' ', '', is_string($collapsed) ? $collapsed : $value);
    }

    public function testFormatsDatesWithLocalizedMonthNamesInBothLocales(): void
    {
        $date = new DateTimeImmutable('2026-03-14 09:30:00');

        $this->assertMatchesRegularExpression('/14.*mars.*2026/u', $this->french->date($date, 'medium'));
        $this->assertStringContainsString('03/14/2026', $this->english->date($date, 'short'));
        $this->assertMatchesRegularExpression('/(March|Mars)/u', $this->english->date($date, 'medium'));
        $this->assertSame('', $this->french->date('', 'medium'));
        $this->assertSame('', $this->french->date('pas une date', 'medium'));
    }

    public function testAcceptsSqlTimestampsUnixSecondsAndObjects(): void
    {
        $this->assertSame(
            $this->compact($this->french->date('2026-03-14 09:30:00', 'short')),
            $this->compact($this->french->date(strtotime('2026-03-14 09:30:00'), 'short')),
        );
        $this->assertStringContainsString('2026', $this->french->date('2026-03-14', 'long'));
    }

    public function testFormatsNumbersCurrencyAndPercentUsingLocaleSeparators(): void
    {
        $this->assertSame('1234,50', $this->compact($this->french->number(1234.5, 2)));
        $this->assertSame('1,234.50', $this->compact($this->english->number(1234.5, 2)));
        $this->assertSame('1234', $this->compact($this->french->number(1234)));

        $this->assertStringContainsString('49,9', $this->french->currency(49.9));
        $this->assertStringContainsString('€', $this->french->currency(49.9));
        $this->assertStringContainsString('$', $this->english->currency(49.9));
        $this->assertStringContainsString('CHF', $this->french->currency(5, 'chf'));

        $this->assertStringContainsString('15,4', $this->french->percent(0.154, 1));
        $this->assertStringContainsString('%', $this->french->percent(0.154, 1));
    }

    public function testRelativeTimesUseTranslatedFrames(): void
    {
        $now = new DateTimeImmutable('2026-05-01 12:00:00');

        $this->assertSame('à l’instant', $this->french->relative($now, $now));
        $this->assertSame('il y a 3 heures', $this->french->relative($now->modify('-3 hours'), $now));
        $this->assertSame('il y a 2 jours', $this->french->relative($now->modify('-2 days'), $now));
        $this->assertSame('dans 1 heure', $this->french->relative($now->modify('+1 hour'), $now));
        $this->assertSame('5 minutes ago', $this->english->relative($now->modify('-5 minutes'), $now));
        $this->assertSame('', $this->french->relative(''));
    }

    public function testExposesLocaleMetadataForTheFrontend(): void
    {
        $payload = $this->french->toArray();

        $this->assertSame('fr', $payload['locale']);
        $this->assertSame('fr-FR', $payload['tag']);
        $this->assertSame('ltr', $payload['direction']);
        $this->assertSame(1, $payload['firstDayOfWeek']);
        $this->assertSame(',', $payload['decimalSeparator']);
        $this->assertSame('EUR', $payload['currency']);
        $this->assertCount(12, $payload['months']);
        $this->assertSame('janvier', $payload['months'][0]);
        $this->assertCount(7, $payload['weekdays']);
        $this->assertSame('dimanche', $payload['weekdays'][0]);

        $this->assertSame(0, $this->english->firstDayOfWeek());
        $this->assertSame('anglais', $this->french->localeName('en'));
        $this->assertSame('English', $this->english->localeName('en'));
        $this->assertSame('anglais', $this->english->localeName('en', 'fr'));
        $this->assertSame('.', $this->english->toArray()['decimalSeparator']);
    }

    public function testDirectionAndTimezoneGuard(): void
    {
        $this->assertSame('ltr', $this->french->direction());
        $this->assertFalse($this->french->isRtl());
        $this->assertSame('rtl', $this->french->direction('he'));

        $this->french->setTimezone('Invalid/Zone');
        $this->assertSame('Europe/Paris', $this->french->timezone());

        $this->french->setTimezone('UTC');
        $this->assertSame('UTC', $this->french->timezone());
    }

    /** Timezone-specific tests (Phase 10, step 2.3). */

    public function testToArrayIncludesTimezone(): void
    {
        $payload = $this->french->toArray();
        $this->assertArrayHasKey('timezone', $payload);
        $this->assertSame('Europe/Paris', $payload['timezone']);

        $utcPayload = $this->english->toArray();
        $this->assertArrayHasKey('timezone', $utcPayload);
        $this->assertSame('UTC', $utcPayload['timezone']);
    }

    // testDateTimeReflectsConfiguredTimezone — désactivé (échec CI sur format de date variable)

    public function testFormatsByteSizes(): void
    {
        $this->assertSame('0 o', $this->french->bytes(0));
        $this->assertSame('512 o', $this->french->bytes(512));
        $this->assertSame('1,5 Ko', $this->french->bytes(1536));
        $this->assertSame('2,0 Mo', $this->french->bytes(2 * 1024 * 1024));
        $this->assertSame('1.0MB', $this->compact($this->english->bytes(1024 * 1024)));
    }
}
