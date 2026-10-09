<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One message sent from the contact page's form (Lane CT). Read in the admin at
 * Store → Inquiries; never returned by anything under /api/*.
 *
 * Every column is text the visitor typed, so it is printed escaped everywhere —
 * the admin screen builds its markup through esc(), the alert email through
 * Blade's {{ }} — and it is never printed raw.
 */
class ContactInquiry extends Model
{
    protected $table = 'contact_inquiries';

    protected $fillable = ['name', 'email', 'phone', 'topic', 'message', 'locale', 'ip', 'read_at', 'mailed_at'];

    protected function casts(): array
    {
        return ['read_at' => 'datetime', 'mailed_at' => 'datetime'];
    }

    /** What the admin screen receives: an allowlist, not the model. */
    public function toAdmin(): array
    {
        return [
            'id' => $this->id,
            'name' => (string) $this->name,
            'email' => (string) $this->email,
            'phone' => (string) ($this->phone ?? ''),
            'topic' => (string) ($this->topic ?? ''),
            'message' => (string) $this->message,
            'locale' => (string) ($this->locale ?? 'en'),
            'read' => $this->read_at !== null,
            'mailed' => $this->mailed_at !== null,
            'at' => $this->created_at?->toIso8601String(),
        ];
    }
}
