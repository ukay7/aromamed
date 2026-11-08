<?php

namespace App\Http\Requests;

use App\Services\ClinicAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ModuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        app(ClinicAccess::class)->authorize($this->user(), $this->route('module'), $this->route('id') ? 'edit' : 'create');
        return true;
    }

    public function rules(): array
    {
        $module = $this->route('module');
        $definition = app(ClinicAccess::class)->definition($module);
        $rules = collect($definition['fields'])->map(fn (array $field): string => $field['rules'])->all();
        foreach (['clients' => 'email', 'users' => 'email', 'roles' => 'name', 'products' => 'sku', 'website_pages' => 'slug', 'service_categories' => 'name', 'treatment_records' => 'appointment_id'] as $table => $column) {
            if ($module === $table) { $rules[$column] = [...explode('|', $rules[$column]), \App\Services\BranchValidation::unique($table, $column, $this->route('id'))]; }
        }
        if ($module === 'users' && ! $this->route('id')) { $rules['password'] = 'required|string|min:12|max:100'; }
        if ($module === 'services') { $rules['staff_ids.*'] = 'integer|exists:staff,id|distinct'; }
        if ($module === 'staff') { $rules['working_days.*'] = 'integer|between:0,6|distinct'; }
        if ($module === 'appointments') { $rules['waitlist_id'] = 'nullable|integer|exists:waitlist,id'; }
        if (in_array($module, ['stock_movements', 'reward_entries'], true)) { $rules['request_id'] = 'required|uuid'; }
        foreach ($definition['fields'] as $name => $field) {
            if (! empty($field['source'])) {
                $key = $field['type'] === 'multireference' ? $name.'.*' : $name;
                $existing = $rules[$key] ?? 'integer';
                $rules[$key] = [...(is_array($existing) ? $existing : explode('|',$existing)), \App\Services\BranchValidation::exists($field['source'])];
            }
        }
        return $rules;
    }
}
