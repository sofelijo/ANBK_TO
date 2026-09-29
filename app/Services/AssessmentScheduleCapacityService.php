<?php

namespace App\Services;

use App\Models\ApplicationSetting;
use Carbon\CarbonImmutable;

class AssessmentScheduleCapacityService
{
    public const SETTINGS_KEY = 'assessment_session_student_capacity';

    public const DEFAULT_CAPACITY = 1000;

    public const DURATION_SETTINGS_KEY = 'assessment_session_duration_minutes';

    public const DEFAULT_DURATION_MINUTES = 150;

    public const GREEN_THRESHOLD_SETTINGS_KEY = 'assessment_session_green_threshold';

    public const YELLOW_THRESHOLD_SETTINGS_KEY = 'assessment_session_yellow_threshold';

    public const DEFAULT_GREEN_THRESHOLD = 70;

    public const DEFAULT_YELLOW_THRESHOLD = 90;

    public const FIRST_SESSION_START = '06:30';

    public const OPERATIONAL_END = '16:30';

    public function capacity(): int
    {
        $value = ApplicationSetting::query()
            ->where('key', self::SETTINGS_KEY)
            ->value('value');

        return is_numeric($value) && (int) $value >= 1
            ? (int) $value
            : self::DEFAULT_CAPACITY;
    }

    public function durationMinutes(): int
    {
        $value = ApplicationSetting::query()
            ->where('key', self::DURATION_SETTINGS_KEY)
            ->value('value');

        return is_numeric($value) && (int) $value >= 1
            ? (int) $value
            : self::DEFAULT_DURATION_MINUTES;
    }

    /** @return array{green: int, yellow: int} */
    public function availabilityThresholds(): array
    {
        $settings = ApplicationSetting::query()
            ->whereIn('key', [self::GREEN_THRESHOLD_SETTINGS_KEY, self::YELLOW_THRESHOLD_SETTINGS_KEY])
            ->pluck('value', 'key');
        $green = (int) ($settings[self::GREEN_THRESHOLD_SETTINGS_KEY] ?? self::DEFAULT_GREEN_THRESHOLD);
        $yellow = (int) ($settings[self::YELLOW_THRESHOLD_SETTINGS_KEY] ?? self::DEFAULT_YELLOW_THRESHOLD);

        if ($green < 1 || $green >= $yellow || $yellow > 99) {
            return ['green' => self::DEFAULT_GREEN_THRESHOLD, 'yellow' => self::DEFAULT_YELLOW_THRESHOLD];
        }

        return ['green' => $green, 'yellow' => $yellow];
    }

    /** @return array<int, array{start: string, end: string}> */
    public function slots(?int $durationMinutes = null): array
    {
        $duration = $durationMinutes ?? $this->durationMinutes();
        $start = CarbonImmutable::createFromFormat('H:i', self::FIRST_SESSION_START);
        $operationalEnd = CarbonImmutable::createFromFormat('H:i', self::OPERATIONAL_END);
        $slots = [];
        $number = 1;

        while ($start->addMinutes($duration)->lessThanOrEqualTo($operationalEnd)) {
            $end = $start->addMinutes($duration);
            $slots[$number] = [
                'start' => $start->format('H:i'),
                'end' => $end->format('H:i'),
            ];
            $start = $end;
            $number++;
        }

        return $slots;
    }
}
