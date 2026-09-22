<?php

namespace App\Http\Requests;

use App\Services\Monitoring\TrafficConnectionMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TrafficAnalyticsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $tenantId = $this->user()?->tenant_id;
        $rules = [
            'period' => ['required', Rule::in(['today', '7d', '30d'])],
            'router_id' => ['sometimes', 'integer', Rule::exists('routers', 'id')->where('tenant_id', $tenantId)],
            'customer_id' => ['sometimes', 'integer', Rule::exists('customers', 'id')->where('tenant_id', $tenantId)],
            'connection_id' => ['sometimes', 'integer', Rule::exists('customer_connections', 'id')->where('tenant_id', $tenantId)],
            'package_id' => ['sometimes', 'integer', Rule::exists('internet_packages', 'id')->where('tenant_id', $tenantId)],
        ];

        if (in_array($this->route()?->getName(), ['api.v1.traffic.rankings', 'api.v1.traffic.subscriber-history'], true)) {
            $rules['mode'] = ['required', Rule::in([
                TrafficConnectionMode::STATIC_SIMPLE_QUEUE,
                TrafficConnectionMode::HOTSPOT,
                TrafficConnectionMode::PPPOE,
            ])];
        }
        if ($this->route()?->getName() === 'api.v1.traffic.rankings') {
            $rules['metric'] = ['required', Rule::in(['total', 'download', 'upload', 'lowest'])];
            $rules['top'] = ['required', 'integer', Rule::in([10, 20])];
        }
        if ($this->route()?->getName() === 'api.v1.traffic.subscriber-history') {
            $rules['identity'] = ['required', 'string', 'max:255'];
        }

        return $rules;
    }

    public function filters(): array
    {
        return array_filter($this->safe()->only(['router_id', 'customer_id', 'connection_id', 'package_id']), fn ($value) => $value !== null);
    }
}
