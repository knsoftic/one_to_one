<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

/**
 * Title, description and image of a web page, fetched once and shared by
 * every message that contains the link.
 */
class LinkPreview extends Model
{
    use Prunable;

    /** Successful previews are fetched again after this many days. */
    public const FRESH_DAYS = 7;

    /** Pages that could not be previewed are retried after this many minutes. */
    public const RETRY_FAILED_MINUTES = 60;

    protected $fillable = [
        'url_hash',
        'url',
        'title',
        'description',
        'site_name',
        'image',
        'image_width',
        'image_height',
        'failed',
        'fetched_at',
    ];

    protected function casts(): array
    {
        return [
            'image_width' => 'integer',
            'image_height' => 'integer',
            'failed' => 'boolean',
            'fetched_at' => 'datetime',
        ];
    }

    public static function hashUrl(string $url): string
    {
        return hash('sha256', $url);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function isUsable(): bool
    {
        return ! $this->failed && ($this->title !== null || $this->description !== null);
    }

    public function isFresh(): bool
    {
        if (! $this->fetched_at) {
            return false;
        }

        return $this->failed
            ? $this->fetched_at->gt(now()->subMinutes(self::RETRY_FAILED_MINUTES))
            : $this->fetched_at->gt(now()->subDays(self::FRESH_DAYS));
    }

    public function domain(): string
    {
        $host = (string) parse_url($this->url, PHP_URL_HOST);

        return function_exists('idn_to_utf8') ? (idn_to_utf8(preg_replace('/^www\./i', '', $host)) ?: $host) : preg_replace('/^www\./i', '', $host);
    }

    /**
     * @return array{url:string, title:?string, description:?string, site_name:string, domain:string, image_url:?string, image_width:?int, image_height:?int}
     */
    public function toPayload(): array
    {
        return [
            'url' => $this->url,
            'title' => $this->title,
            'description' => $this->description,
            'site_name' => $this->site_name ?: $this->domain(),
            'domain' => $this->domain(),
            'image_url' => $this->image ? route('link-previews.image', $this, false) : null,
            'image_width' => $this->image_width,
            'image_height' => $this->image_height,
        ];
    }

    /**
     * Previews no message uses anymore (daily `model:prune`).
     */
    public function prunable(): Builder
    {
        return static::query()
            ->where('updated_at', '<', now()->subDays(30))
            ->whereDoesntHave('messages');
    }

    protected function pruning(): void
    {
        if ($this->image) {
            Storage::disk(config('chat.uploads.disk'))->delete($this->image);
        }
    }
}
