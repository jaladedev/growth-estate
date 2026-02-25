<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupportMessage extends Model {
    protected $fillable = ['ticket_id','sender_type','sender_id','body','attachment_path'];
    public function attachmentUrl(): ?string {
        return $this->attachment_path ? Storage::url($this->attachment_path) : null;
    }
}