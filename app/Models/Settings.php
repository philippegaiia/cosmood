<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Application settings stored in database.
 *
 * Provides get/set methods for configurable values with caching.
 */
class Settings extends Model
{
    protected $guarded = [];

    protected static ?Settings $cachedInstance = null;

    /**
     * Get a setting value by key.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        return static::instance()->getValue($key, $default);
    }

    /**
     * Set a setting value.
     */
    public static function set(string $key, mixed $value): void
    {
        static::instance()->setValue($key, $value);
    }

    /**
     * Get all settings as array.
     */
    public static function allSettings(): array
    {
        return static::instance()->getAllValues();
    }

    /**
     * Get internal supplier label.
     */
    public static function internalSupplierLabel(): string
    {
        return static::get('internal_supplier_label', 'INT');
    }

    /**
     * Get date format.
     */
    public static function dateFormat(): string
    {
        return static::get('date_format', 'Y-m-d');
    }

    /**
     * Get a single setting value.
     */
    public function getValue(string $key, mixed $default = null): mixed
    {
        $setting = static::query()->where('key', $key)->first();

        if (! $setting) {
            return $default;
        }

        $value = $setting->value;

        if (str_starts_with($value, 'json:')) {
            return json_decode(substr($value, 5), true);
        }

        if (is_numeric($value)) {
            return str_contains($value, '.') ? (float) $value : (int) $value;
        }

        if (in_array(strtolower($value), ['true', 'false'])) {
            return strtolower($value) === 'true';
        }

        return $value;
    }

    /**
     * Set a single setting value.
     */
    public function setValue(string $key, mixed $value): void
    {
        if (is_array($value)) {
            $value = 'json:'.json_encode($value);
        } elseif (is_bool($value)) {
            $value = $value ? 'true' : 'false';
        }

        static::query()->updateOrCreate(
            ['key' => $key],
            ['key' => $key, 'value' => (string) $value],
        );

        static::clearCachedInstance();
    }

    /**
     * Get all settings as key-value array.
     */
    public function getAllValues(): array
    {
        return static::query()
            ->get()
            ->mapWithKeys(fn (Settings $setting) => $this->parseValue($setting->value))
            ->all();
    }

    /**
     * Parse a setting value.
     */
    private function parseValue(string $value): mixed
    {
        if (str_starts_with($value, 'json:')) {
            return json_decode(substr($value, 5), true);
        }

        if (is_numeric($value)) {
            return str_contains($value, '.') ? (float) $value : (int) $value;
        }

        if (in_array(strtolower($value), ['true', 'false'])) {
            return strtolower($value) === 'true';
        }

        return $value;
    }

    /**
     * Get or create cached instance.
     */
    protected static function instance(): static
    {
        if (static::$cachedInstance === null) {
            static::$cachedInstance = new static;
        }

        return static::$cachedInstance;
    }

    /**
     * Clear cached instance.
     */
    protected static function clearCachedInstance(): void
    {
        static::$cachedInstance = null;
    }
}
