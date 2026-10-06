<?php

namespace Database\Seeders\Showcase\Concerns;

use Fleetbase\LaravelMysqlSpatial\Types\Point;
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
        // method_exists rather than declaring seedArea() here with a null default, because two
        // traits composed into the same class cannot both declare the same method — PHP calls
        // it a collision and refuses, and every area seeder composes this trait alongside its
        // own. Leaving the method undeclared here lets an area trait supply it cleanly.
        $area = method_exists($this, 'seedArea') ? $this->seedArea() : null;

        return $area ? 'showcase-' . $area : 'showcase';
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
     * Human-readable phrase for the odd note that does not fit fixtureNote()'s noun shape.
     * Kept separate from seedName() because one is infrastructure and never rendered, while
     * this appears on screen.
     */
    protected function seedLabel(): string
    {
        return 'Fleetbase demo';
    }

    /**
     * Prefix for human-facing identifiers — internal_id, tracking numbers, work order ids.
     * FLT rather than upstream's TEST, because these are read by people looking at the
     * marketing site.
     */
    protected function identifierPrefix(): string
    {
        return 'FLT';
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
        return getenv('SHOWCASE_ORG_NAME') ?: 'Fleetbase Pte Ltd';
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

    /**
     * Close a ring and flip it into GeoJSON's axis order.
     *
     * Fixtures are written [lat, lng] — the order every Fleetbase seeder and the Point
     * constructor use. GeoJSON is [lng, lat]. Getting this backwards puts Singapore in the
     * Indian Ocean, and the screenshot still renders, so it is worth doing in exactly one place.
     */
    private function geoJsonRing(array $coordinates): array
    {
        $ring = array_map(fn (array $coordinate) => [$coordinate[1], $coordinate[0]], $coordinates);

        // A ring has to close. Callers write the corners and forget the repeat.
        if ($ring[0] !== end($ring)) {
            $ring[] = $ring[0];
        }

        return $ring;
    }

    /**
     * GeoJSON rather than a spatial Polygon object, and that is a compatibility fix.
     *
     * On fleetops v0.7.57, Zone::$border casts through Fleetbase\FleetOps\Casts\Polygon, and
     * handing it a LaravelMysqlSpatial\Types\Polygon fails at insert with
     *
     *     SQLSTATE[22003]: Cannot get geometry object from data you send to the GEOMETRY field
     *
     * because the value reaches MySQL as `(103.8 1.2,...)` — the ring without its POLYGON()
     * wrapper. Verified it is not a forceFill artefact: Zone::create() with a Polygon object
     * fails identically. The cast's GeoJSON branch works for both Zone and ServiceArea, so
     * everything goes through it.
     *
     * Upstream's Testing seeders pass objects and presumably work on the fleetops version they
     * ship with; showcase runs against whatever the newest release publishes, which is exactly
     * the kind of drift this repo has to absorb.
     */
    protected function polygon(array $coordinates): array
    {
        return ['type' => 'Polygon', 'coordinates' => [$this->geoJsonRing($coordinates)]];
    }

    protected function multiPolygon(array $coordinates): array
    {
        return ['type' => 'MultiPolygon', 'coordinates' => [[$this->geoJsonRing($coordinates)]]];
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
        $model = new $modelClass();
        if ($publicId = $this->derivedPublicId($modelClass, $attributes)) {
            $attributes['public_id'] = $publicId;
        }
        if ($internalId = $this->derivedInternalId($modelClass, $attributes)) {
            $attributes['internal_id'] = $internalId;
        }
        $attributes = $this->filterColumns($model, array_merge([
            'uuid'       => $this->derivedUuid($modelClass, $attributes) ?? (string) Str::uuid(),
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

    /**
     * A public id derived from the record's seed key, for models that would otherwise get a
     * random one on every run. Fleet-Ops lists print the ID column (vehicle_dxaf8kyj6a), so a
     * random id made those screenshots differ on every release for no reason. HasPublicId
     * keeps a preset value. Null when the record has no seed key, or the model no public id.
     */
    protected function derivedPublicId(string $modelClass, array $attributes): ?string
    {
        if (isset($attributes['public_id']) || !method_exists($modelClass, 'getPublicIdType')) {
            return null;
        }

        $key = $attributes['_key'] ?? null;
        if (!$key && is_array($attributes['meta'] ?? null) && isset($attributes['meta']['seed_id'])) {
            $key = ($attributes['meta']['seed'] ?? $this->seedName()) . ':' . $attributes['meta']['seed_id'];
        }
        if (!$key) {
            return null;
        }

        $type = $modelClass::getPublicIdType() ?: strtolower(class_basename($modelClass));

        return $type . '_' . substr(hash('sha256', 'public-id:' . $key), 0, 10);
    }

    /**
     * The internal id HasInternalId would generate — the company's initials and six digits —
     * with the digits derived from the seed key instead of random. Fleet-Ops lists print it
     * (Internal ID column), so random digits changed those screenshots on every release.
     */
    protected function derivedInternalId(string $modelClass, array $attributes): ?string
    {
        if (isset($attributes['internal_id']) || !method_exists($modelClass, 'generateInternalId')) {
            return null;
        }

        $key = $attributes['_key'] ?? null;
        if (!$key && is_array($attributes['meta'] ?? null) && isset($attributes['meta']['seed_id'])) {
            $key = ($attributes['meta']['seed'] ?? $this->seedName()) . ':' . $attributes['meta']['seed_id'];
        }
        if (!$key) {
            return null;
        }

        // Same prefix rule as HasInternalId: first letters of the first two words of the
        // company name, else its first two letters.
        $prefix  = '';
        $company = isset($attributes['company_uuid']) ? \Fleetbase\Models\Company::where('uuid', $attributes['company_uuid'])->first() : null;
        if ($company) {
            $words  = explode(' ', $company->name);
            $prefix = count($words) > 1
                ? strtoupper(mb_substr($words[0], 0, 1) . mb_substr($words[1], 0, 1))
                : strtoupper(mb_substr($company->name, 0, 2));
        }

        $digits = str_pad((string) (hexdec(substr(hash('sha256', 'internal-id:' . $key), 0, 8)) % 1000000), 6, '0', STR_PAD_LEFT);

        return $prefix . $digits;
    }

    /**
     * A UUID derived from the model and seed key, so screens that print a record's uuid (the
     * webhook details panel, QR codes built from an order's uuid) are the same on every run.
     * v4-shaped. Null when the record has no seed key.
     */
    protected function derivedUuid(string $modelClass, array $attributes): ?string
    {
        $key = $attributes['_key'] ?? null;
        if (!$key && is_array($attributes['meta'] ?? null) && isset($attributes['meta']['seed_id'])) {
            $key = ($attributes['meta']['seed'] ?? $this->seedName()) . ':' . $attributes['meta']['seed_id'];
        }
        if (!$key) {
            return null;
        }

        $h = hash('sha256', 'uuid:' . $modelClass . ':' . $key);

        return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-4' . substr($h, 13, 3) . '-a' . substr($h, 17, 3) . '-' . substr($h, 20, 12);
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
