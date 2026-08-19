<?php

namespace App\Http\Resources\Program;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProgramProfileResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'user_id' => $this->whenHas('user_id', $this->user_id),
            'program_owner_id' => $this->whenHas('program_owner_id', $this->program_owner_id),
            'org_type' => $this->whenHas('org_type', $this->org_type),
            'regions' => $this->whenHas('regions', $this->regions),
            'program_stage' => $this->whenHas('program_stage', $this->program_stage),
            'mission' => $this->whenHas('mission', $this->mission),
            'document' => $this->whenHas('document', $this->document),
            'role' => $this->whenHas('role', $this->role ? $this->role->name : 'super-admin'),
        ];
    }
}