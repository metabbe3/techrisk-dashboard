<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvestigationDocumentResource extends JsonResource
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
            'original_filename' => $this->original_filename,
            'description' => $this->description,
            'pic_status' => $this->pic_status,
            'markdown_conversion_status' => $this->markdown_conversion_status,
            'ai_summary' => $this->when($this->markdown_conversion_status === 'completed', $this->ai_summary),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
