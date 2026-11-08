<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;

class BranchQuery extends Builder
{
    public ?string $branchTable = null;

    private function stamp(array $values): array
    {
        if (! $this->branchTable || ! $values) { return $values; }
        $id = app(BranchContext::class)->requireBranch();
        if (isset($values[0]) && is_array($values[0])) {
            return array_map(function (array $row) use ($id): array { $row['branch_id']=$id; return $row; }, $values);
        }
        $values['branch_id']=$id; return $values;
    }

    public function insert(array $values): bool { return parent::insert($this->stamp($values)); }
    public function insertGetId(array $values, $sequence = null): int { return parent::insertGetId($this->stamp($values),$sequence); }
    public function insertOrIgnore(array $values): int { return parent::insertOrIgnore($this->stamp($values)); }
    public function update(array $values): int
    {
        if ($this->branchTable) { app(BranchContext::class)->requireBranch(); unset($values['branch_id']); }
        return parent::update($values);
    }
    public function delete($id = null): int
    {
        if ($this->branchTable) { app(BranchContext::class)->requireBranch(); }
        return parent::delete($id);
    }
}
