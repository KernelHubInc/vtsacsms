<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class MaintenanceWorkOrderResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->resource->getKey(),
            'work_order_number' => (string) $this->resource->work_order_number,
            'work_type' => (string) $this->resource->work_type,
            'site_id' => (string) $this->resource->site_id,
            'asset_type' => (string) $this->resource->asset_type,
            'asset_id' => (string) $this->resource->asset_id,
            'state' => $this->resource->state->value,
            'title' => (string) $this->resource->title,
            'description' => (string) $this->resource->description,
            'scope' => $this->resource->scope,
            'diagnosis' => $this->resource->diagnosis,
            'work_performed' => $this->resource->work_performed,
            'priority_id' => (string) $this->resource->priority_id,
            'sla_policy_id' => $this->resource->sla_policy_id,
            'acknowledge_target_at' => $this->resource->acknowledge_target_at?->toIso8601String(),
            'resolve_target_at' => $this->resource->resolve_target_at?->toIso8601String(),
            'started_at' => $this->resource->started_at?->toIso8601String(),
            'completed_at' => $this->resource->completed_at?->toIso8601String(),
            'verified_at' => $this->resource->verified_at?->toIso8601String(),
            'closed_at' => $this->resource->closed_at?->toIso8601String(),
            'safety_critical' => (bool) $this->resource->safety_critical,
            'verification_required' => (bool) $this->resource->verification_required,
            'currency' => (string) $this->resource->currency,
            'estimated_cost_minor' => (int) $this->resource->estimated_cost_minor,
            'actual_cost_minor' => (int) $this->resource->actual_cost_minor,
            'aggregate_version' => (int) $this->resource->aggregate_version,
            'reopened_from_id' => $this->resource->reopened_from_id,
            'assignments' => $this->whenLoaded('assignments'),
            'checklist_items' => $this->whenLoaded('checklistItems'),
            'part_requirements' => $this->whenLoaded('partRequirements'),
            'time_entries' => $this->whenLoaded('timeEntries'),
            'transitions' => $this->whenLoaded('transitions'),
            'attachments' => $this->whenLoaded('attachments'),
        ];
    }
}
