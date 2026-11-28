<?php

namespace App\Models\Programs\Concerns;

use App\Support\ProgramSchedule;
use Illuminate\Support\Carbon;

/**
 * A model that opens and closes at exact moments (opening/closing *_at columns, UTC) and keeps the matching calendar
 * dates (older callers read and write those). Whichever side was written is the truth for that save:
 *   - a moment written: its calendar date (in the program's zone) is filled in;
 *   - only a date written (older callers, imports): the moment becomes 00:00:00 / 23:59:59 of that date in the zone.
 *
 * The using model declares:
 *   protected const OPENING_WINDOW = ['opens' => [dateColumn, momentColumn], 'closes' => [dateColumn, momentColumn]];
 *   public function scheduleTimezone(): string
 */
trait HasOpeningWindow
{
    public static function bootHasOpeningWindow(): void
    {
        static::saving(function (self $model): void {
            $model->syncOpeningWindow();
        });
    }

    public function initializeHasOpeningWindow(): void
    {
        foreach (static::OPENING_WINDOW as [, $moment]) {
            $this->mergeCasts([$moment => 'datetime:' . ProgramSchedule::JSON_FORMAT]);
        }
    }

    /**
     * A moment is always stored as UTC. "2026-12-01T08:00:00+03:00" (or a Carbon in any zone) is converted first;
     * a string without an offset is taken as UTC, the way the column is stored.
     */
    public function setAttribute($key, $value)
    {
        foreach (static::OPENING_WINDOW as [, $column]) {
            if ($key === $column) {
                $value = ProgramSchedule::parse($value, 'UTC');
                break;
            }
        }

        return parent::setAttribute($key, $value);
    }

    /** When it opens, exactly. Null when no opening is set. */
    public function opensAt(): ?Carbon
    {
        return $this->windowMoment('opens');
    }

    /** When it closes, exactly (the last second it still accepts). Null when no closing is set. */
    public function closesAt(): ?Carbon
    {
        return $this->windowMoment('closes');
    }

    public function hasOpened(): bool
    {
        $opens = $this->opensAt();

        return ! $opens || ! $opens->isFuture();
    }

    public function hasClosed(): bool
    {
        $closes = $this->closesAt();

        return $closes !== null && $closes->isPast();
    }

    /** Sets a moment and its calendar date together, so both read right before the model is saved. */
    public function setWindowMoment(string $side, mixed $moment): static
    {
        [$date, $column] = static::OPENING_WINDOW[$side];
        $zone = $this->scheduleTimezone();
        $at = ProgramSchedule::parse($moment, $zone);
        $this->setAttribute($column, $at);
        $at = $this->getAttribute($column);
        $this->setAttribute($date, ProgramSchedule::localDate($at, $zone));

        return $this;
    }

    private function windowMoment(string $side): ?Carbon
    {
        [$date, $column] = static::OPENING_WINDOW[$side];

        // A partial select that left the moment out falls back to the date, read the way it is stored
        if (array_key_exists($column, $this->attributes)) {
            return $this->getAttribute($column);
        }
        if (! array_key_exists($date, $this->attributes)) {
            return null;
        }

        return ProgramSchedule::dayBound($this->getAttribute($date), $this->scheduleTimezone(), $side === 'closes');
    }

    private function syncOpeningWindow(): void
    {
        $zone = null;
        foreach (static::OPENING_WINDOW as $side => [$date, $column]) {
            if ($this->isDirty($column)) {
                $zone ??= $this->scheduleTimezone();
                $this->setAttribute($date, ProgramSchedule::localDate($this->getAttribute($column), $zone));
            } elseif ($this->isDirty($date)) {
                $zone ??= $this->scheduleTimezone();
                $this->setAttribute($column, ProgramSchedule::dayBound($this->getAttribute($date), $zone, $side === 'closes'));
            }
        }
    }
}
