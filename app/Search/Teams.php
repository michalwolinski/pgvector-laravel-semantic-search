<?php

declare(strict_types=1);

namespace App\Search;

/**
 * Spreads the articles over 200 teams with Zipf-shaped sizes, the way tenants of a SaaS app differ:
 * team 1 holds about 17% of the rows, team 200 about 0.1%. The team depends only on the wiki id.
 */
final class Teams
{
    public const int COUNT = 200;

    /** @var list<float>|null */
    private static ?array $cumulative = null;

    public static function of(int $wikiId): int
    {
        $point = crc32('team:'.$wikiId) / 4294967296;

        foreach (self::cumulative() as $index => $upper) {
            if ($point < $upper) {
                return $index + 1;
            }
        }

        return self::COUNT;
    }

    /** @return list<float> */
    private static function cumulative(): array
    {
        if (null !== self::$cumulative) {
            return self::$cumulative;
        }

        $weights = array_map(fn (int $rank): float => 1 / $rank, range(1, self::COUNT));
        $total = array_sum($weights);
        $running = 0.0;

        return self::$cumulative = array_map(function (float $weight) use ($total, &$running): float {
            $running += $weight / $total;

            return $running;
        }, $weights);
    }
}
