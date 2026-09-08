<?php

namespace Database\Seeders\Showcase\Concerns;

use Fleetbase\LaravelMysqlSpatial\Types\LineString;
use Fleetbase\LaravelMysqlSpatial\Types\MultiPolygon;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Fleetbase\LaravelMysqlSpatial\Types\Polygon;
use Fleetbase\Models\Company;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Shared fixture machinery for every showcase seeder.
 *
 * WHY THIS IS VENDORED RATHER THAN REUSED
 *
 * fleetbase/fleetops ships an equivalent concern at
 * server/seeders/Testing/Concerns/SeedsTestingData.php, and it is present in the running
 * container. We cannot use it. It declares
 *
 *     protected const SEED_NAME = 'fleetops-testing';
 *
 * and every helper reads `static::SEED_NAME`. PHP rejects a class that redeclares a trait
 * constant with a different value ("the definition differs and is considered incompatible"),
 * so a showcase seeder using that trait would tag its rows `fleetops-testing` — meaning
 * fleetops' own TestingSeeder would purge our fixtures and vice versa. Vendoring is forced,
 * not a preference.
 *
 * The consequence to respect: this file is a fork and will drift from its origin. It is
 * deliberately small and dependency-light so that drift stays cheap.
 *
 * NO FAKER. The published API image is installed with --no-dev, so fakerphp is absent. That
 * is fine — determinism wants fixed values anyway, and a random name would change the
 * screenshot on every run.
 */
trait SeedsShowcaseData
{
    /**
     * The tag written to every row's meta->seed (or its _key prefix).
     *
     * This is the whole isolation mechanism: seeding and purging are scoped to it, so the
     * showcase set can coexist with fleetops' `fleetops-testing` fixtures in one install
     * without either destroying the other.
     */
    protected function seedName(): string
    {
        return 'showcase';
    }

    /**
     * Note and description text. Unlike the fleetops testing fixtures — which deliberately
     * read as test data — these strings appear in marketing screenshots, so they have to read
     * like something a real operator wrote.
     */
    protected function fixtureNote(string $noun): string
    {
        return ucfirst($noun) . '.';
    }

    /**
     * Every timestamp derives from this, so "3 days ago" columns are stable across runs.
     * The capture pins the browser clock to a fixed instant relative to it.
     */
    protected function timestamp(int $hoursOffset = 0): Carbon
    {
        return Carbon::parse('2026-01-15 08:00:00', 'Asia/Singapore')->addHours($hoursOffset);
    }

    // ── Company ─────────────────────────────────────────────────────────────────────────

    /**
     * Resolve the showcase organization by name.
     *
     * Deliberately not core-api's ResolvesSeedCompany, which picks the first company it finds
     * — on a stack that has been poked at by hand, that is a coin flip. Showcase always owns
     * exactly one company and knows its name.
     */
    protected function resolveCompany(): ?Company
    {
        return Company::where('name', $this->organizationName())->first();
    }

    protected function organizationName(): string
    {
        return getenv('SHOWCASE_ORG_NAME') ?: 'Fleetbase Demo';
    }

    protected function prepareCompany(): ?Company
    {
        $company = $this->resolveCompany();

        if (!$company) {
            $this->command?->error(
                'No "' . $this->organizationName() . '" company found. Run the Organization seeder first: ./scripts/seed.sh organization'
            );

            return null;
        }

        // Several models resolve their tenant from the session rather than an argument.
        session(['company' => $company->uuid]);

        return $company;
    }

    // ── Identity and tagging ────────────────────────────────────────────────────────────

    protected function fixtureKey(string $seedId): string
    {
        return $this->seedName() . ':' . $seedId;
    }

    protected function meta(string $seedId, array $extra = []): array
    {
        return array_merge([
            'seed'    => $this->seedName(),
            'seed_id' => $seedId,
        ], $extra);
    }

    // ── Geometry ────────────────────────────────────────────────────────────────────────

    protected function point(float $lat, float $lng): Point
    {
        return new Point($lat, $lng);
    }

    protected function polygon(array $coordinates): Polygon
    {
        $points = array_map(fn (array $coordinate) => $this->point($coordinate[0], $coordinate[1]), $coordinates);

        // A polygon ring has to close. Callers write the corners and forget the repeat.
        if ($points[0]->getLat() !== end($points)->getLat() || $points[0]->getLng() !== end($points)->getLng()) {
            $points[] = $points[0];
        }

        return new Polygon([new LineString($points)]);
    }

    protected function multiPolygon(array $coordinates): MultiPolygon
    {
        return new MultiPolygon([$this->polygon($coordinates)]);
    }

    // ── Writing ─────────────────────────────────────────────────────────────────────────

    /**
     * Create a row, filtered to columns that actually exist on this release.
     *
     * The filter is what lets one set of fixtures survive a schema change: a column dropped
     * in a later release silently stops being written instead of throwing, which matters
     * because showcase runs against whatever the newest release ships.
     */
    protected function createRecord(string $modelClass, array $attributes, bool $withoutEvents = false): Model
    {
        /** @var Model $model */
        $model      = new $modelClass();
        $attributes = $this->filterColumns($model, array_merge([
            'uuid'       => (string) Str::uuid(),
            'created_at' => $this->timestamp(),
            'updated_at' => $this->timestamp(),
        ], $attributes));

        $model->forceFill($attributes);

        if ($withoutEvents) {
            $modelClass::withoutEvents(fn () => $model->save());
        } else {
            $model->save();
        }

        return $model;
    }

    protected function filterColumns(Model $model, array $attributes): array
    {
        $table = $model->getTable();

        if (!Schema::hasTable($table)) {
            return $attributes;
        }

        return array_filter(
            $attributes,
            fn (string $column) => Schema::hasColumn($table, $column),
            ARRAY_FILTER_USE_KEY
        );
    }

    // ── Reading and purging ─────────────────────────────────────────────────────────────

    /**
     * Scope a query to rows this seed owns.
     *
     * Two strategies because the models disagree: most carry a JSON `meta` column, a few
     * (ApiRequestLog among them) carry `_key` instead. Anything with neither is not purgeable
     * and returns an empty set rather than risking a delete of rows we did not create.
     */
    protected function seededQuery(string $modelClass)
    {
        /** @var Model $model */
        $model = new $modelClass();
        $table = $model->getTable();
        $query = $modelClass::query();

        if (Schema::hasColumn($table, 'meta')) {
            return $query->where('meta->seed', $this->seedName());
        }

        if (Schema::hasColumn($table, '_key')) {
            return $query->where('_key', 'like', $this->seedName() . ':%');
        }

        return $query->whereRaw('1 = 0');
    }

    protected function purgeModel(string $modelClass): void
    {
        $query = $this->seededQuery($modelClass);

        // forceDelete on soft-deleting models: a soft-deleted fixture still occupies its
        // unique keys, so the next reseed would collide.
        if (in_array(SoftDeletes::class, class_uses_recursive($modelClass), true)) {
            $query->forceDelete();

            return;
        }

        $query->delete();
    }

    protected function seededModel(string $modelClass, string $seedId): ?Model
    {
        /** @var Model $model */
        $model = new $modelClass();
        $table = $model->getTable();

        if (Schema::hasColumn($table, 'meta')) {
            return $modelClass::where('meta->seed', $this->seedName())
                ->where('meta->seed_id', $seedId)
                ->first();
        }

        if (Schema::hasColumn($table, '_key')) {
            return $modelClass::where('_key', $this->fixtureKey($seedId))->first();
        }

        return null;
    }
}
