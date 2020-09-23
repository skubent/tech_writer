<?php

namespace DvTest;

class H3Utils {
    // Ну да, оно
    const PI = 3.14159265358979323846;

    // Максимальное разрешение (выше разрядность не дает, да и незачем)
    const MAX_RESOLUTION = 15;

    // Точность вычислений
    const EPSILON = 0.0000000000001;

    // 1 / (Расстояние между центрами соседних гексов в гномонической проекции)
    // Оно же численно равно scale-factor'у
    const RES0_U_GNOMONIC = 0.38196601125010500003;

    // Угол между осями 2-го и 3-го классов гексагонов
    // asin(sqrt(3.0 / 28.0))
    const ROTATION_ANGLE_BETWEEN_CLASSII_CLASSIII_AXES = 0.33347317225183211533;

    /**
     * Возвращает угол в радианах, не выходящий за интервал 0..2pi
     */
    public static function normalizeRadians(float $angle): float {
        if ($angle < 0.0) {
            return $angle + 2 * H3Utils::PI;
        }
        if ($angle >= 2 * H3Utils::PI) {
            return $angle - 2 * H3Utils::PI;
        }
        return $angle;
    }

    public static function radiansToDegrees(float $radians): float {
        return 180 * $radians / static::PI;
    }

    public static function degreesToRadians(float $degrees): float {
        return $degrees * static::PI / 180;
    }

    public static function isResolutionClassIII(int $resolution): bool {
        return (bool) ($resolution % 2);
    }
}
