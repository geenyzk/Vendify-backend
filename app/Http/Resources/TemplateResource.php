<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TemplateResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'type' => $this->type,
            'event' => $this->event,
            'subject' => $this->subject,
            'content' => $this->content,
            'channels' => $this->channels,
            'enabled' => $this->enabled,
            'variables' => $this->variables,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
