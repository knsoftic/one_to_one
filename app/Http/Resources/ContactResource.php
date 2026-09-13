<?php

namespace App\Http\Resources;

use App\Models\Contact;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A saved phone-book contact who is registered on the app.
 *
 * @mixin Contact
 */
class ContactResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'phone' => $this->phone,
            'user' => (new UserResource($this->contactUser))->resolve($request),
        ];
    }
}
