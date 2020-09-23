<?php

namespace DvTest;

/**
 * Оно должно быть package-private, но php так не умеет
 */
class GeoCoordinates {
    /** @var float */
    public $latitude;

    /** @var float */
    public $longitude;

    public function __construct(float $latitude, float $longitude, bool $castToRadians = false) {
        $this->latitude  = $latitude;
        $this->longitude = $longitude;
        if ($castToRadians) {
            $this->latitude  = H3Utils::degreesToRadians($this->latitude);
            $this->longitude = H3Utils::degreesToRadians($this->longitude);
        }
    }

    public function getAzimuthToPoint(GeoCoordinates $point): float {
        return atan2(
            cos($point->latitude) * sin($point->longitude - $this->longitude),
            cos($this->latitude) * sin($point->latitude) - sin($this->latitude) * cos($point->latitude) * cos($point->longitude - $this->longitude)
        );
    }

    public function getMagnitude(): float {
        return sqrt($this->latitude * $this->latitude + $this->longitude * $this->longitude);
    }

    private function normalizeLongitude(): void {
        while ($this->longitude > H3Utils::PI) {
            $this->longitude -= H3Utils::PI * 2;
        }
        while ($this->longitude < - H3Utils::PI) {
            $this->longitude += H3Utils::PI * 2;
        }
    }

    public function toDegrees(): void {
        $this->latitude  = H3Utils::radiansToDegrees($this->latitude);
        $this->longitude = H3Utils::radiansToDegrees($this->longitude);
    }

    /**
     * Сдвигает себя в направлении $azimuth на дистанцию $distance
     */
    public function moveTo(float $azimuth, float $distance): void {
        if ($distance < H3Utils::EPSILON) {
            return;
        }
        $normalizedAzimuth = H3Utils::normalizeRadians($azimuth);
        if ($normalizedAzimuth < H3Utils::EPSILON || abs($normalizedAzimuth - 2 * H3Utils::PI) < H3Utils::EPSILON) {
            // Чистое движение на север или на юг
            if ($normalizedAzimuth < H3Utils::EPSILON) {
                $this->latitude += $distance;
            } else {
                $this->latitude -= $distance;
            }
            if (abs($this->latitude - 2 * H3Utils::PI) < H3Utils::EPSILON) {
                // Северный полюс
                $this->latitude  = H3Utils::PI / 2;
                $this->longitude = 0;
            } elseif (abs($this->latitude + 2 * H3Utils::PI) < H3Utils::EPSILON) {
                // Южный полюс
                $this->latitude  = -H3Utils::PI / 2;
                $this->longitude = 0;
            } else {
                $this->normalizeLongitude();
            }
        } else {
            // Наиболее распространенный случай
            $sinLatitude = max(-1, min(1, sin($this->latitude) * cos($distance) + cos($this->latitude) * sin($distance) * cos($normalizedAzimuth)));
            $newLatitude = asin($sinLatitude);
            if (abs($newLatitude - H3Utils::PI / 2) < H3Utils::EPSILON) {
                // Северный полюс
                $this->latitude  = H3Utils::PI / 2;
                $this->longitude = 0;
            } elseif (abs($newLatitude + H3Utils::PI / 2) < H3Utils::EPSILON) {
                // Южный полюс
                $this->latitude  = -H3Utils::PI / 2;
                $this->longitude = 0;
            } else {
                // Самый обычный вариант
                $sinLongitude = max(-1, min(1, sin($normalizedAzimuth) * sin($distance) / cos($newLatitude)));
                $cosLongitude = max(-1, min(1, (cos($distance) - sin($this->latitude) * sin($newLatitude)) / cos($this->latitude) / cos($newLatitude)));
                $this->latitude  = $newLatitude;
                $this->longitude += atan2($sinLongitude, $cosLongitude);
                $this->normalizeLongitude();
            }
        }
    }

}
