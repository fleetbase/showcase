<?php

namespace Database\Seeders\Showcase\Storefront\Concerns;

// Vendored from fleetbase/storefront server/seeders/Testing, adapted for the showcase:
//   - its own namespace and trait name, so it cannot collide with the published trait;
//   - the company is the showcase organization, found by name;
//   - time is pinned: timestamp() is anchored at 2026-01-20 12:00 Asia/Singapore (the capture's
//     "today") instead of the server's real clock, so every run produces the same records;
//   - seed tags are showcase-storefront-*.
// Keep the structure in step with upstream when the Storefront models change.


use Fleetbase\Models\Company;
use Fleetbase\Models\CompanyUser;
use Fleetbase\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Low level helpers shared by every Storefront testing seeder.
 *
 * Every record a seeder creates is tagged with the seeder's seed name, either in a
 * `meta`/`options` JSON column or in a `_key`/`unique_identifier` column. That tag is
 * what makes a seeder idempotent: `purgeSeedData()` removes only records carrying the
 * tag, so the seeders can be re-run safely against a developer database.
 */
trait SeedsStorefrontShowcaseData
{

    /**
     * Seed name used by the first generation of Storefront testing seeders. Kept so
     * the refactored seeders still purge fixtures created before the split into
     * store and network seeders.
     */
    protected const LEGACY_SEED_NAME = 'showcase-storefront-legacy';

    /** @var array<string, string[]|null> column listings keyed by connection.table */
    protected array $tableColumnCache = [];

    /**
     * Seed name written into every record this seeder creates.
     */
    abstract protected function seedName(): string;

    /**
     * Every seed name this seeder is responsible for purging.
     *
     * @return string[]
     */
    protected function seedNames(): array
    {
        return array_values(array_unique([$this->seedName(), static::LEGACY_SEED_NAME]));
    }

    protected function resolveCompany(): ?Company
    {
        return Company::where('name', getenv('SHOWCASE_ORG_NAME') ?: 'Fleetbase Pte Ltd')->first();
    }

    protected function prepareCompany(): ?Company
    {
        $company = $this->resolveCompany();
        if (!$company) {
            $this->command?->error('No company found. Create a Fleetbase company before running Storefront testing seeders.');

            return null;
        }

        session(['company' => $company->uuid]);

        $user = $this->resolveUser($company);
        if ($user) {
            session(['user' => $user->uuid]);
        }

        return $company;
    }

    protected function resolveUser(Company $company): ?User
    {
        if (Str::isUuid($company->owner_uuid)) {
            $owner = User::where('uuid', $company->owner_uuid)->first();
            if ($owner) {
                return $owner;
            }
        }

        $companyUser = CompanyUser::where('company_uuid', $company->uuid)->first();
        if ($companyUser) {
            return User::where('uuid', $companyUser->user_uuid)->first();
        }

        return User::query()->orderBy('created_at')->first();
    }

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

    /**
     * Deterministic timestamp for seeded records.
     *
     * Records default to 08:00 on the first day of the current month. `$daysAgo`
     * shifts the anchor back so activity can be spread across a date range (dashboards
     * and analytics widgets need history, not one big spike). The result is never in
     * the future.
     */
    protected function timestamp(int $hoursOffset = 0, int $daysAgo = 0): Carbon
    {
        // The capture's "today", not the server's: see the header.
        $now = Carbon::parse('2026-01-20 12:00:00', $this->seedTimezone());

        $timestamp = $daysAgo > 0
            ? $now->copy()->subDays($daysAgo)->startOfDay()->addHours(8 + $hoursOffset)
            : $now->copy()->startOfMonth()->addHours(8 + $hoursOffset);

        if ($timestamp->greaterThan($now)) {
            return $now;
        }

        return $timestamp;
    }

    protected function seedTimezone(): string
    {
        return 'Asia/Singapore';
    }

    protected function createRecord(string $modelClass, array $attributes, bool $withoutEvents = false): Model
    {
        /** @var Model $model */
        $model = new $modelClass();
        if ($publicId = $this->derivedPublicId($modelClass, $attributes)) {
            $attributes['public_id'] = $publicId;
        }
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

    protected function filterColumns(Model $model, array $attributes): array
    {
        $columns = $this->tableColumns($model->getConnectionName(), $model->getTable());

        if ($columns === null) {
            return $attributes;
        }

        return array_filter(
            $attributes,
            fn (string $column) => in_array($column, $columns, true),
            ARRAY_FILTER_USE_KEY
        );
    }

    /**
     * Column listing for a table, cached for the life of the seeder. Seeders create
     * hundreds of records and every attribute check would otherwise be a schema query.
     *
     * @return string[]|null null when the table does not exist
     */
    protected function tableColumns(?string $connection, string $table): ?array
    {
        $cacheKey = $connection . '.' . $table;

        if (!array_key_exists($cacheKey, $this->tableColumnCache)) {
            $this->tableColumnCache[$cacheKey] = Schema::connection($connection)->hasTable($table)
                ? Schema::connection($connection)->getColumnListing($table)
                : null;
        }

        return $this->tableColumnCache[$cacheKey];
    }

    protected function hasColumn(?string $connection, string $table, string $column): bool
    {
        return in_array($column, $this->tableColumns($connection, $table) ?? [], true);
    }

    /**
     * Query builder scoped to records tagged by this seeder (or a legacy seed name).
     */
    protected function seededQuery(string $modelClass)
    {
        /** @var Model $model */
        $model      = new $modelClass();
        $table      = $model->getTable();
        $connection = $model->getConnectionName();
        $query      = $modelClass::query();
        $seedNames  = $this->seedNames();

        if ($this->hasColumn($connection, $table, 'meta')) {
            return $query->whereIn('meta->seed', $seedNames);
        }

        if ($this->hasColumn($connection, $table, 'options')) {
            return $query->whereIn('options->seed', $seedNames);
        }

        foreach (['_key', 'unique_identifier'] as $column) {
            if ($this->hasColumn($connection, $table, $column)) {
                return $query->where(function ($query) use ($column, $seedNames) {
                    foreach ($seedNames as $seedName) {
                        $query->orWhere($column, 'like', $seedName . ':%');
                    }
                });
            }
        }

        return $query->whereRaw('1 = 0');
    }

    protected function seededUuids(string $modelClass): array
    {
        return $this->seededQuery($modelClass)->pluck('uuid')->filter()->values()->all();
    }

    protected function seededModel(string $modelClass, string $seedId): ?Model
    {
        /** @var Model $model */
        $model      = new $modelClass();
        $table      = $model->getTable();
        $connection = $model->getConnectionName();

        if ($this->hasColumn($connection, $table, 'meta')) {
            return $modelClass::where('meta->seed', $this->seedName())->where('meta->seed_id', $seedId)->first();
        }

        if ($this->hasColumn($connection, $table, 'options')) {
            return $modelClass::where('options->seed', $this->seedName())->where('options->seed_id', $seedId)->first();
        }

        if ($this->hasColumn($connection, $table, '_key')) {
            return $modelClass::where('_key', $this->fixtureKey($seedId))->first();
        }

        if ($this->hasColumn($connection, $table, 'unique_identifier')) {
            return $modelClass::where('unique_identifier', $this->fixtureKey($seedId))->first();
        }

        return null;
    }

    protected function purgeModel(string $modelClass): void
    {
        /** @var Model $model */
        $model = new $modelClass();
        $query = $this->seededQuery($modelClass);

        if (in_array(SoftDeletes::class, class_uses_recursive($modelClass), true) || method_exists($model, 'bootSoftDeletes')) {
            $query->forceDelete();

            return;
        }

        $query->delete();
    }

    protected function deleteFrom(string $connection, string $table, callable $callback): void
    {
        if (!Schema::connection($connection)->hasTable($table)) {
            return;
        }

        $query = DB::connection($connection)->table($table);
        $callback($query);
        $query->delete();
    }

    /**
     * Run a callback with foreign key checks disabled on both Storefront connections.
     * Purging spans two databases with cross-database foreign keys, so deletes have to
     * happen with constraints off regardless of ordering.
     */
    protected function withoutForeignKeyConstraints(callable $callback): void
    {
        $connections = array_unique([$this->fleetbaseConnection(), $this->storefrontConnection()]);

        foreach ($connections as $connection) {
            Schema::connection($connection)->disableForeignKeyConstraints();
        }

        try {
            $callback();
        } finally {
            foreach (array_reverse($connections) as $connection) {
                Schema::connection($connection)->enableForeignKeyConstraints();
            }
        }
    }

    protected function storefrontConnection(): string
    {
        return config('storefront.connection.db');
    }

    protected function fleetbaseConnection(): string
    {
        return config('fleetbase.connection.db');
    }
}
