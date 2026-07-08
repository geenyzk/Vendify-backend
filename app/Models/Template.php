<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Template extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'templates';

    protected $fillable = [
        'name',
        'slug',
        'type',
        'event',
        'subject',
        'content',
        'channels',
        'enabled',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'channels' => 'array',
        'enabled' => 'boolean',
    ];

    // Constants for code-level use
    public const TYPE_EVENT = 'event';
    public const TYPE_BROADCAST = 'broadcast';

    public const EVENTS = [
        'login',
        'register',
        'purchase',
        'wallet_credit',
        'wallet_debit',
    ];

    /**
     * Boot: ensure slug exists and is unique on create.
     */
    protected static function booted()
    {
        static::creating(function (Template $template) {
            if (empty($template->slug)) {
                $base = Str::slug($template->name ?: 'template');
                $slug = $base;
                $i = 1;
                while (Template::where('slug', $slug)->exists()) {
                    $slug = $base . '-' . $i++;
                }
                $template->slug = $slug;
            }
        });
    }

    /**
     * Scope helpers
     */
    public function scopeEnabled($query)
    {
        return $query->where('enabled', true);
    }

    public function scopeType($query, string $type)
    {
        return $query->where('type', $type);
    }

    /**
     * Extract variables used in content (and subject).
     *
     * @return string[] unique variable names without curly braces
     */
    public function getVariablesAttribute(): array
    {
        $content = ($this->subject ?? '') . "\n" . ($this->content ?? '');
        preg_match_all('/{{\s*([a-zA-Z0-9_-]+)\s*}}/', $content, $matches);
        if (empty($matches[1])) {
            return [];
        }
        return array_values(array_unique($matches[1]));
    }

    /**
     * Render the template with replacements.
     * Example: $template->render(['name'=>'John','amount'=>'1200'])
     *
     * Variables that are missing will remain in the returned string as {{var}}.
     *
     * @param  array  $replacements
     * @return array  ['subject' => string|null, 'content' => string]
     */
    public function render(array $replacements = []): array
    {
        $renderValue = function (?string $text) use ($replacements) {
            if ($text === null) {
                return null;
            }
            return preg_replace_callback('/{{\s*([a-zA-Z0-9_-]+)\s*}}/', function ($m) use ($replacements) {
                $key = $m[1];
                if (array_key_exists($key, $replacements)) {
                    return (string) $replacements[$key];
                }
                // keep the placeholder if not provided
                return $m[0];
            }, $text);
        };

        return [
            'subject' => $renderValue($this->subject),
            'content' => $renderValue($this->content) ?? '',
        ];
    }

    /**
     * Helper: check whether a channel is configured for this template.
     *
     * @param  string  $channel e.g. 'email','sms','in_app','push'
     */
    public function hasChannel(string $channel): bool
    {
        $channels = $this->channels ?? [];
        return in_array($channel, $channels, true);
    }
}

