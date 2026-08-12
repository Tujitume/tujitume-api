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

        return [
            'id' => $this->id,
            'fname' => $this->whenHas('fname', $this->fname),
            'lname' => $this->whenHas('lname', $this->lname),
            'email' => $this->whenHas('email', $this->email),
            'phone' => $this->whenHas('phone', $this->phone),
            'gender' => $this->whenHas('gender', $this->gender),
            'dob' => $this->whenHas('dob', $this->dob),
            'image' => $this->whenHas('image', $this->image),
            'connect_id' => $this->whenHas('connect_id', $this->connect_id),
            'completed_onboarding' => $this->whenHas('completed_onboarding', $this->completed_onboarding),
            'lipr_wallet' => $this->whenHas('lipr_wallet', $this->lipr_wallet),
            

            'id_passport' => $this->when($isInvestor, $this->id_passport),
            'pin' => $this->when($isInvestor, $this->pin),
            'inv_range' => $this->when($isInvestor, $this->inv_range),
            'turnover_range' => $this->when($isInvestor, $this->turnover_range),
            'past_investment' => $this->when($isInvestor, $this->past_investment),
            
            //Relationships
            'user_type' => $this->user_type ? $this->user_type->name : null,
            'grant_profile' => new GrantProfileResource($this->whenLoaded('grant_profile')),
            'capital_profile' => new CapitalProfileResource($this->whenLoaded('capital_profile')),
        ];
    }
}
