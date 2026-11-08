<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/** Branch-scoped query boundary for clinic repositories; global infrastructure uses Laravel DB explicitly. */
class BranchDB
{
    public static function table(string $table): BranchQuery
    {
        $context=app(BranchContext::class); $connection=DB::connection();
        $query=new BranchQuery($connection,$connection->getQueryGrammar(),$connection->getPostProcessor());
        $query->from($table);
        if (in_array($table,config('tenancy.tables'),true)) {
            $query->branchTable=$table;
            $query->whereIn($table.'.branch_id',$context->branchIds);
        } elseif ($table === 'users') {
            $query->where('users.organization_id',$context->organizationId ?? 0)->whereIn('users.id',DB::table('branch_user')->whereIn('branch_id',$context->branchIds)->select('user_id'));
        }
        return $query;
    }

    public static function transaction(callable $callback, int $attempts = 1): mixed { return DB::transaction($callback,$attempts); }
}
