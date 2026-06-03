<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class AdminSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'key',
        'value',
        'type',
        'group',
        'description',
        'is_editable',
    ];

    protected $casts = [
        'is_editable' => 'boolean',
    ];

    protected static function boot()
    {
        parent::boot();
        static::saved(function ($setting) {
            Cache::forget("admin_setting:{$setting->key}");
            Cache::forget("admin_settings:{$setting->group}");
        });
        static::deleted(function ($setting) {
            Cache::forget("admin_setting:{$setting->key}");
            Cache::forget("admin_settings:{$setting->group}");
        });
    }

    public function scopeByGroup($query, $group)
    {
        return $query->where('group', $group);
    }

    public function scopeEditable($query)
    {
        return $query->where('is_editable', true);
    }

    public function getTypedValue()
    {
        return match ($this->type) {
            'integer' => (int) $this->value,
            'boolean' => filter_var($this->value, FILTER_VALIDATE_BOOLEAN),
            'json' => json_decode($this->value, true),
            'array' => explode(',', $this->value),
            default => $this->value,
        };
    }

    public static function get(string $key, $default = null)
    {
        return Cache::rememberForever("admin_setting:{$key}", function () use ($key, $default) {
            $setting = static::where('key', $key)->first();
            return $setting?->getTypedValue() ?? $default;
        });
    }

    public static function set(string $key, $value, string $type = 'string'): bool
    {
        $setting = static::where('key', $key)->first();
        
        if (!$setting) {
            return false;
        }

        if (!$setting->is_editable) {
            return false;
        }

        $stringValue = match ($type) {
            'json' => json_encode($value),
            'array' => implode(',', $value),
            'boolean' => $value ? 'true' : 'false',
            default => (string) $value,
        };

        return $setting->update([
            'value' => $stringValue,
            'type' => $type,
        ]);
    }

    public static function getGroup(string $group): array
    {
        return Cache::rememberForever("admin_settings:{$group}", function () use ($group) {
            return static::byGroup($group)
                ->get()
                ->mapWithKeys(function ($setting) {
                    return [$setting->key => $setting->getTypedValue()];
                })
                ->toArray();
        });
    }

    public static function allSettings(): array
    {
        return Cache::rememberForever('admin_settings:all', function () {
            return static::all()
                ->groupBy('group')
                ->map(function ($settings) {
                    return $settings->mapWithKeys(function ($setting) {
                        return [$setting->key => $setting->getTypedValue()];
                    });
                })
                ->toArray();
        });
    }
}
