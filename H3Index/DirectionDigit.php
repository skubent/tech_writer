<?php

namespace DvTest;

/**
 * Внутреннее представление координат
 * @see https://uber.github.io/h3/#/documentation/core-library/coordinate-systems
 */
class DirectionDigit {

    // Текущая ячейка
    const CENTER  = 0;
    // Соседние ячейки
    const K_AXES  = 1;
    const J_AXES  = 2;
    const JK_AXES = 3;
    const I_AXES  = 4;
    const IK_AXES = 5;
    const IJ_AXES = 6;

    public static function rotateDigit60DegreeCounterClockwise(int $digit): int {
        switch ($digit) {
            case static::K_AXES:
                return static::IK_AXES;
            case static::IK_AXES:
                return static::I_AXES;
            case static::I_AXES:
                return static::IJ_AXES;
            case static::IJ_AXES:
                return static::J_AXES;
            case static::J_AXES:
                return static::JK_AXES;
            case static::JK_AXES:
                return static::K_AXES;
        }
        return $digit;
    }

    public static function rotateDigit60DegreeClockwise(int $digit): int {
        switch ($digit) {
            case static::K_AXES:
                return static::JK_AXES;
            case static::JK_AXES:
                return static::J_AXES;
            case static::J_AXES:
                return static::IJ_AXES;
            case static::IJ_AXES:
                return static::I_AXES;
            case static::I_AXES:
                return static::IK_AXES;
            case static::IK_AXES:
                return static::K_AXES;
        }
        return $digit;
    }

    private const NEW_DIGIT_CLASS_II = [
        self::CENTER => [
            self::CENTER, self::K_AXES, self::J_AXES, self::JK_AXES, self::I_AXES, self::IK_AXES, self::IJ_AXES,
        ],
        self::K_AXES => [
            self::K_AXES, self::I_AXES, self::JK_AXES, self::IJ_AXES, self::IK_AXES, self::J_AXES, self::CENTER,
        ],
        self::J_AXES => [
            self::J_AXES, self::JK_AXES, self::K_AXES, self::I_AXES, self::IJ_AXES, self::CENTER, self::IK_AXES,
        ],
        self::JK_AXES => [
            self::JK_AXES, self::IJ_AXES, self::I_AXES, self::IK_AXES, self::CENTER, self::K_AXES, self::J_AXES,
        ],
        self::I_AXES => [
            self::I_AXES, self::IK_AXES, self::IJ_AXES, self::CENTER, self::J_AXES, self::JK_AXES, self::K_AXES,
        ],
        self::IK_AXES => [
            self::IK_AXES, self::J_AXES, self::CENTER, self::K_AXES, self::JK_AXES, self::IJ_AXES, self::I_AXES,
        ],
        self::IJ_AXES => [
            self::IJ_AXES, self::CENTER, self::IK_AXES, self::J_AXES, self::K_AXES, self::I_AXES, self::JK_AXES,
        ],
    ];

    public static function getNewDigitClassII(int $digit, int $direction): int {
        return self::NEW_DIGIT_CLASS_II[$digit][$direction];
    }

    private const NEW_DIGIT_CLASS_III = [
        self::CENTER => [
            self::CENTER, self::K_AXES, self::J_AXES, self::JK_AXES, self::I_AXES, self::IK_AXES, self::IJ_AXES,
        ],
        self::K_AXES => [
            self::K_AXES, self::J_AXES, self::JK_AXES, self::I_AXES, self::IK_AXES, self::IJ_AXES, self::CENTER,
        ],
        self::J_AXES => [
            self::J_AXES, self::JK_AXES, self::I_AXES, self::IK_AXES, self::IJ_AXES, self::CENTER, self::K_AXES,
        ],
        self::JK_AXES => [
            self::JK_AXES, self::I_AXES, self::IK_AXES, self::IJ_AXES, self::CENTER, self::K_AXES, self::J_AXES,
        ],
        self::I_AXES => [
            self::I_AXES, self::IK_AXES, self::IJ_AXES, self::CENTER, self::K_AXES, self::J_AXES, self::JK_AXES,
        ],
        self::IK_AXES => [
            self::IK_AXES, self::IJ_AXES, self::CENTER, self::K_AXES, self::J_AXES, self::JK_AXES, self::I_AXES,
        ],
        self::IJ_AXES => [
            self::IJ_AXES, self::CENTER, self::K_AXES, self::J_AXES, self::JK_AXES, self::I_AXES, self::IK_AXES,
        ],
    ];

    public static function getNewDigitClassIII(int $digit, int $direction): int {
        return self::NEW_DIGIT_CLASS_III[$digit][$direction];
    }

    private const NEW_ADJUSTMENT_II = [
        self::CENTER => [
            self::CENTER, self::CENTER, self::CENTER, self::CENTER, self::CENTER, self::CENTER, self::CENTER,
        ],
        self::K_AXES => [
            self::CENTER, self::K_AXES, self::CENTER, self::K_AXES, self::CENTER, self::IK_AXES, self::CENTER ,
        ],
        self::J_AXES => [
            self::CENTER, self::CENTER, self::J_AXES, self::JK_AXES, self::CENTER, self::CENTER, self::J_AXES,
        ],
        self::JK_AXES => [
            self::CENTER, self::K_AXES, self::JK_AXES, self::JK_AXES, self::CENTER, self::CENTER, self::CENTER,
        ],
        self::I_AXES => [
            self::CENTER, self::CENTER, self::CENTER, self::CENTER, self::I_AXES, self::I_AXES, self::IJ_AXES,
        ],
        self::IK_AXES => [
            self::CENTER, self::IK_AXES, self::CENTER, self::CENTER, self::I_AXES, self::IK_AXES, self::CENTER,
        ],
        self::IJ_AXES => [
            self::CENTER, self::CENTER, self::J_AXES, self::CENTER, self::IJ_AXES, self::CENTER, self::IJ_AXES,
        ],

    ];

    public static function getNewAdjustmentII(int $digit, int $direction): int {
        return self::NEW_ADJUSTMENT_II[$digit][$direction];
    }

    private const NEW_ADJUSTMENT_III = [
        self::CENTER => [
            self::CENTER, self::CENTER, self::CENTER, self::CENTER, self::CENTER, self::CENTER, self::CENTER,
        ],
        self::K_AXES => [
            self::CENTER, self::K_AXES, self::CENTER, self::JK_AXES, self::CENTER, self::K_AXES, self::CENTER,
        ],
        self::J_AXES => [
            self::CENTER, self::CENTER, self::J_AXES, self::J_AXES, self::CENTER, self::CENTER, self::IJ_AXES,
        ],
        self::JK_AXES => [
            self::CENTER, self::JK_AXES, self::J_AXES, self::JK_AXES, self::CENTER, self::CENTER, self::CENTER,
        ],
        self::I_AXES => [
            self::CENTER, self::CENTER, self::CENTER, self::CENTER, self::I_AXES, self::IK_AXES, self::I_AXES,
        ],
        self::IK_AXES => [
            self::CENTER, self::K_AXES, self::CENTER, self::CENTER, self::IK_AXES, self::IK_AXES, self::CENTER,
        ],
        self::IJ_AXES => [
            self::CENTER, self::CENTER, self::IJ_AXES, self::CENTER, self::I_AXES, self::CENTER, self::IJ_AXES,
        ],
    ];

    public static function getNewAdjustmentIII(int $digit, int $direction): int {
        return self::NEW_ADJUSTMENT_III[$digit][$direction];
    }

    private const DIGIT_TO_COORDINATES = [
        self::CENTER  => [0, 0, 0],
        self::K_AXES  => [0, 0, 1],
        self::J_AXES  => [0, 1, 0],
        self::JK_AXES => [0, 1, 1],
        self::I_AXES  => [1, 0, 0],
        self::IK_AXES => [1, 0, 1],
        self::IJ_AXES => [1, 1, 0],
    ];

    public static function getCoordinates(int $digit): HexagonCoordinates {
        return new HexagonCoordinates(self::DIGIT_TO_COORDINATES[$digit][0], self::DIGIT_TO_COORDINATES[$digit][1], self::DIGIT_TO_COORDINATES[$digit][2]);
    }
}
