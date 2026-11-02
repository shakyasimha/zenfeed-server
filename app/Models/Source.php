<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Casts\Attribute;

class Source extends Model
{
    //
    protected $guarded = [];

    /** @var list<string> */
    protected $appends = ['icon_url'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'selector_rules' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /**
     * A stored icon_url (if you add that column) wins; otherwise use the site's favicon.
     *
     * @return Attribute<string|null, never>
     */
    protected function iconUrl(): Attribute
    {
        return Attribute::get(function (?string $value, array $attributes): ?string {
            if (filled($value)) {
                return $value;
            }

            $host = parse_url($attributes['url'] ?? '', PHP_URL_HOST);

            return $host
                ? 'https://www.google.com/s2/favicons?sz=128&domain=' . preg_replace('/^www\./i', '', $host)
                : null;
        });
    }

    public function articles(): HasMany
    {
        return $this->hasMany(Article::class);
    }
}
