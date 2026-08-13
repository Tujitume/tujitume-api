<?php

namespace App\Http\Resources\User;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Http\Resources\Grant\GrantProfileResource;
use App\Http\Resources\Capital\CapitalProfileResource;

class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $isInvestor = $this->user_type_id === 1;
        $isIndustry = $this->user_type_id === 2;

        return [
            'id' => $this->id,
            $this->mergeWhen($this->user_type_id !== 2, [
                'fname' => $this->whenHas('fname', $this->fname),
                'lname' => $this->whenHas('lname', $this->lname),
                'gender' => $this->whenHas('gender', $this->gender),
                'dob' => $this->whenHas('dob', $this->dob),
            ]),

            $this->mergeWhen($isIndustry, [
                'org_name' => $this->whenHas('fname', $this->fname),
            ]),

            'email' => $this->whenHas('email', $this->email),
            'phone' => $this->whenHas('phone', $this->phone),
            'image' => $this->whenHas('image', $this->image),
            'connect_id' => $this->whenHas('connect_id', $this->connect_id),
            'completed_onboarding' => $this->whenHas('completed_onboarding', $this->completed_onboarding),
            'lipr_wallet' => $this->whenHas('lipr_wallet', $this->lipr_wallet),
            

            $this->mergeWhen($isInvestor, [
                'id_passport' => $this->whenHas('id_passport', $this->id_passport),
                'pin' => $this->whenHas('pin', $this->pin),
                'inv_range' => $this->whenHas('inv_range', $this->inv_range),
                'turnover_range' => $this->whenHas('turnover_range', $this->turnover_range),
                'past_investment' => $this->whenHas('past_investment', $this->past_investment),
            ]),
            
            //Relationships
            'user_type' => $this->user_type ? $this->user_type->name : null,
            'grant_profile' => new GrantProfileResource($this->whenLoaded('grant_profile')),
            'capital_profile' => new CapitalProfileResource($this->whenLoaded('capital_profile')),
        ];
    }
}
