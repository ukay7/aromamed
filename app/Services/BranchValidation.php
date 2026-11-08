<?php

namespace App\Services;

use Closure;
use Illuminate\Validation\Rule;

class BranchValidation
{
    public static function exists(string $table): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail) use ($table): void {
            if ($value !== null && ! BranchDB::table($table)->where('id',$value)->exists()) { $fail('Choose a record belonging to the active branch.'); }
        };
    }

    public static function unique(string $table, string $column, mixed $ignore = null): mixed
    {
        $rule = Rule::unique($table,$column)->ignore($ignore);
        if (in_array($table,config('tenancy.tables'),true)) { $rule->where('branch_id',app(BranchContext::class)->requireBranch()); }
        return $rule;
    }
}
