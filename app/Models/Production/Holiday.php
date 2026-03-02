<?php

namespace App\Models\Production;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Holiday extends Model
{
    use HasFactory;

    protected $fillable = [
        'date',
        'name',
        'is_recurring',
        'year',
    ];

    protected $casts = [
        'date' => 'date',
        'is_recurring' => 'boolean',
    ];

    /**
     * Scope to get holidays for a specific date
     */
    public function scopeForDate($query, Carbon $date)
    {
        return $query->where(function ($q) use ($date) {
            // Exact date match
            $q->whereDate('date', $date->format('Y-m-d'));

            // Or recurring holiday (same month/day)
            $q->orWhere(function ($q2) use ($date) {
                $q2->where('is_recurring', true)
                    ->whereMonth('date', $date->month)
                    ->whereDay('date', $date->day);
            });
        });
    }

    /**
     * Check if a given date is a holiday
     */
    public static function isHoliday(Carbon $date): bool
    {
        return static::forDate($date)->exists();
    }

    /**
     * Get all holiday dates between two dates
     */
    public static function getHolidayDatesBetween(Carbon $startDate, Carbon $endDate): array
    {
        $holidays = [];

        // Get specific date holidays in range
        $specificHolidays = static::whereBetween('date', [$startDate, $endDate])
            ->where('is_recurring', false)
            ->pluck('date')
            ->map(fn ($date) => Carbon::parse($date)->format('Y-m-d'))
            ->toArray();

        $holidays = array_merge($holidays, $specificHolidays);

        // Get recurring holidays
        $recurringHolidays = static::where('is_recurring', true)->get();

        foreach ($recurringHolidays as $holiday) {
            $holidayDate = Carbon::parse($holiday->date);

            // Check if this recurring holiday falls within the date range
            $currentYear = $startDate->year;
            while ($currentYear <= $endDate->year) {
                $thisYearHoliday = Carbon::create($currentYear, $holidayDate->month, $holidayDate->day);

                if ($thisYearHoliday->between($startDate, $endDate)) {
                    $holidays[] = $thisYearHoliday->format('Y-m-d');
                }

                $currentYear++;
            }
        }

        return array_unique($holidays);
    }
}
