<?php

namespace App\Services;

use App\Models\User;
use App\Services\BranchDB as DB;

class ClinicAccess
{
    public function definition(string $module): array
    {
        $definition = config("clinic.modules.$module");
        abort_unless(is_array($definition), 404);
        return $definition;
    }

    public function authorize(User $user, string $module, string $action = 'view'): array
    {
        app(BranchContext::class)->requireBranch();
        $definition = $this->definition($module);
        abort_unless($user->allowed(($definition['permission'] ?? $module).'.'.$action), 403, 'Your role does not have access to this action.');
        return $definition;
    }

    public function menu(User $user): array
    {
        if (app(BranchContext::class)->aggregate) {
            return [['id'=>'org','label'=>'Organization','module'=>null,'icon'=>'building','children'=>[
                ['id'=>'org-dashboard','label'=>'Combined overview','module'=>'dashboard','icon'=>'th-large','children'=>[]],
                ['id'=>'org-reports','label'=>'Combined reports','module'=>'reports','icon'=>'chart-bar','children'=>[]],
                ['id'=>'org-branches','label'=>'Organization & branches','module'=>'branches','icon'=>'building','children'=>[]],
            ]]];
        }
        $items = DB::table('menu_items')->where('active', true)->orderBy('sort_order')->orderBy('id')->get();
        $build = function (?int $parent, array $visited = []) use (&$build, $items, $user): array {
            $result = [];
            foreach ($items->where('parent_id', $parent) as $item) {
                if (in_array($item->id, $visited, true)) { continue; }
                $children = $build($item->id, [...$visited, $item->id]);
                $permission = config("clinic.modules.{$item->module}.permission", $item->module);
                $canOpen = $item->module && $user->allowed($permission.'.view');
                if ($canOpen || count($children)) {
                    $result[] = ['id' => $item->id, 'label' => $item->label, 'icon' => $item->icon, 'module' => $canOpen ? $item->module : null, 'children' => $children];
                }
            }
            return $result;
        };
        $menu=$build(null);
        if ($user->is_org_owner) { $menu[]=['id'=>'organization','label'=>'Organization','module'=>null,'icon'=>'building','children'=>[['id'=>'branches','label'=>'Organization & branches','module'=>'branches','icon'=>'building','children'=>[]]]]; }
        return $menu;
    }

    public function audit(User $user, string $action, string $entity, ?int $id): void
    {
        DB::table('audit_logs')->insert(['user_id' => $user->id, 'action' => $action, 'entity' => $entity, 'entity_id' => $id, 'created_at' => now(), 'updated_at' => now()]);
    }
}
