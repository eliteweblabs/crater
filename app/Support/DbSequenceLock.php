<?php

namespace Crater\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Sequence-number allocation helpers — MySQL vs Postgres.
 *
 * Postgres rejects FOR UPDATE on queries that use aggregate functions (e.g. MAX()).
 * Use row locks on MySQL; advisory xact locks on Postgres inside the same transaction.
 */
class DbSequenceLock
{
    /** Namespace for pg_advisory_xact_lock(classid, objid) — arbitrary stable id. */
    private const PG_ADVISORY_CLASS = 738291;

    /**
     * @param  Builder|\Illuminate\Database\Eloquent\Builder  $query
     * @return Builder|\Illuminate\Database\Eloquent\Builder
     */
    public static function forUpdate($query)
    {
        if (DB::getDriverName() === 'pgsql') {
            return $query;
        }

        return $query->lockForUpdate();
    }

    /** Serialize per-company sequence allocation for the current transaction (Postgres). */
    public static function advisoryLockCompany(int $companyId): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::select('SELECT pg_advisory_xact_lock(?, ?)', [self::PG_ADVISORY_CLASS, $companyId]);
    }
}
