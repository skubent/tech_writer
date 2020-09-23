<?php

namespace DvTest;

use Exception;

/**
 * Координаты в плоскости, замощенной шестиугольниками
 *
 * @see https://uber.github.io/h3/#/documentation/core-library/coordinate-systems
 *
 * Оно должно быть package-private, но php так не умеет
 */
class HexagonCoordinates {
    /** @var int */
    public $i;

    /** @var int */
    public $j;

    /** @var int */
    public $k;

    // В Н3 координаты не могут быть больше 2, поскольку это означает другое разрешение
    private const MAX_FACE_COORD = 2;

    public function __construct(int $i = 0, int $j = 0, int $k = 0) {
        $this->i = $i;
        $this->j = $j;
        $this->k = $k;
    }

    public function scale(int $factor): void {
        $this->i *= $factor;
        $this->j *= $factor;
        $this->k *= $factor;
    }

    public function add(HexagonCoordinates $addend): void {
        $this->i += $addend->i;
        $this->j += $addend->j;
        $this->k += $addend->k;
    }

    public function subNoNormalize(HexagonCoordinates $subtrahend): void {
        $this->i -= $subtrahend->i;
        $this->j -= $subtrahend->j;
        $this->k -= $subtrahend->k;
    }

    public function sub(HexagonCoordinates $subtrahend): void {
        $this->subNoNormalize($subtrahend);
        $this->normalize();
    }

    public function normalize(): void {
        // Удаляем отрицательные компоненты
        if ($this->i < 0) {
            $this->j -= $this->i;
            $this->k -= $this->i;
            $this->i = 0;
        }

        if ($this->j < 0) {
            $this->i -= $this->j;
            $this->k -= $this->j;
            $this->j = 0;
        }

        if ($this->k < 0) {
            $this->i -= $this->k;
            $this->j -= $this->k;
            $this->k = 0;
        }

        // Обнуляем одну из координат
        // В шестиугольниках коллинеарность вот так вот получается
        $min = min($this->i, $this->j, $this->k);
        if ($min > 0) {
            $this->i -= $min;
            $this->j -= $min;
            $this->k -= $min;
        }
    }

    // Пакует текущее значение в число
    public function toDigit(): int {
        $this->normalize();
        $index = $this->i * 4 + $this->j * 2 + $this->k;
        if ($index > 6) {
            throw new Exception('Invalid vector (' . $this->i . ', ' . $this->j . ', ' . $this->k . ')');
        }
        return $index;
    }

    public function isZero(): bool {
        return $this->i == 0 && $this->j = 0 && $this->k == 0;
    }

    public function isEqual(HexagonCoordinates $test): bool {
        return $this->i == $test->i && $this->j == $test->j && $this->k == $test->k;
    }

    public function isIncorrect(): bool {
        return $this->i > self::MAX_FACE_COORD || $this->j > self::MAX_FACE_COORD || $this->k > self::MAX_FACE_COORD;
    }

    // Координаты центра родительской ячейки для Class II ячейки
    public function ap7ParentCounterClockwise(): void {
        $newI = $this->i - $this->k;
        $newJ = $this->j - $this->k;

        $this->i = (int) round((3 * $newI - $newJ) / 7.0);
        $this->j = (int) round(($newI + 2 * $newJ) / 7.0);
        $this->k = 0;

        $this->normalize();
    }

    // Координаты центра родительской ячейки для Class III ячейки
    public function ap7ParentClockwise(): void {
        $newI = $this->i - $this->k;
        $newJ = $this->j - $this->k;

        $this->i = (int) round((2 * $newI + $newJ) / 7.0);
        $this->j = (int) round((3 * $newJ - $newI) / 7.0);
        $this->k = 0;

        $this->normalize();
    }

    public function doNeighborByDirectionDigit(int $digit): void {
        if ($digit > DirectionDigit::CENTER && $digit < 7) {
            $this->add(DirectionDigit::getCoordinates($digit));
            $this->normalize();
        }
    }

    /**
     * Выполняет конвертацию в привычные координаты относительно указанного faceId и разрешения
     */
    public function convertToGeoCoordinates(int $faceId, int $resolution): GeoCoordinates {
        $relativeCoordinates = $this->convertTo2D();
        $distanceFromFaceCenter = $relativeCoordinates->getMagnitude();
        if ($distanceFromFaceCenter < H3Utils::EPSILON) {
            return FaceDescriptions::getFaceCenterGeoCoordinates($faceId);
        }
        $theta = atan2($relativeCoordinates->longitude, $relativeCoordinates->latitude);
        for ($i = 0; $i < $resolution; $i++) {
            $distanceFromFaceCenter /= sqrt(7);
        }

        $distanceFromFaceCenter *= H3Utils::RES0_U_GNOMONIC;

        $distanceFromFaceCenter = atan($distanceFromFaceCenter);

        if (H3Utils::isResolutionClassIII($resolution)) {
            $theta = H3Utils::normalizeRadians($theta + H3Utils::ROTATION_ANGLE_BETWEEN_CLASSII_CLASSIII_AXES);
        }
        $theta = H3Utils::normalizeRadians(FaceDescriptions::getBasisAxesForFace($faceId)->x - $theta);

        $coordinates = FaceDescriptions::getFaceCenterGeoCoordinates($faceId);
        $coordinates->moveTo($theta, $distanceFromFaceCenter);
        $coordinates->toDegrees();
        return $coordinates;
    }

    /**
     * Творит не очень ясную магию с собой
     * Возвращает новый faceId для заданных параметров
     */
    public function adjustOverageClassIIAndGetFaceId(int $faceId, int $resolution, bool $onPentagon, bool $onLeading4Zeros): int {
        $hexagonCoordinates = new static($this->i, $this->j, $this->k);
        $isResolutionChanged = false;
        if (H3Utils::isResolutionClassIII($resolution)) {
            $hexagonCoordinates = $hexagonCoordinates->ap7ChildClockwise();
            $resolution++;
            $isResolutionChanged = true;
        }

        $faceId = $hexagonCoordinates->adjustOverageClassII($faceId, $resolution, $onPentagon && $onLeading4Zeros, 0);
        if ($hexagonCoordinates->lastOverage != 0) {
            if ($onPentagon) {
                while (1) {
                    $faceId = $hexagonCoordinates->adjustOverageClassII($faceId, $resolution, 0, 0);
                    if ($hexagonCoordinates->lastOverage == 0) {
                        break;
                    }
                }
            }
            if ($isResolutionChanged) {
                $hexagonCoordinates->ap7ParentClockwise();
            }
        } else {
            if ($isResolutionChanged) {
                $hexagonCoordinates = new static($this->i, $this->j, $this->k);
            }
        }
        $this->i = $hexagonCoordinates->i;
        $this->j = $hexagonCoordinates->j;
        $this->k = $hexagonCoordinates->k;
        return $faceId;
    }

    /** @var int */
    private $lastOverage = 0;

    private function adjustOverageClassII(int $faceId, int $resolution, bool $pentagonWithLeadeing4, bool $substrate): int {
        $this->lastOverage = 0;
        $newFaceId = $faceId;

        $maxDimension = self::MAXIMUM_CLASS_II_DIMENTIONS[$resolution] ?? -1;
        if ($substrate) {
            $maxDimension *= 3;
        }

        if ($substrate && $this->i + $this->j + $this->k == $maxDimension) {
            $this->lastOverage = 1;
        } else {
            if ($this->i + $this->j + $this->k > $maxDimension) {
                $this->lastOverage = 2;
                if ($this->k > 0) {
                    if ($this->j > 0) {
                        $neighborData = self::NEIGHBOR_FACES[$faceId][3];
                    } else {
                        $neighborData = self::NEIGHBOR_FACES[$faceId][2];
                        if ($pentagonWithLeadeing4) {
                            $origin = new HexagonCoordinates($maxDimension, 0, 0);
                            $this->subNoNormalize($origin);
                            $this->rotate60Clockwise();
                            $this->add($origin);
                        }
                    }
                } else {
                    $neighborData = self::NEIGHBOR_FACES[$faceId][1];
                }
                $newFaceId = $neighborData[0];
                for ($i = 0; $i < $neighborData[2]; $i++) {
                    $this->rotate60CounterClockwise();
                }

                $transVec = new HexagonCoordinates($neighborData[1][0], $neighborData[1][1], $neighborData[1][2]);
                $unitScale = self::UNIT_SCALE_BY_CLASS_II_RESOLUTION[$resolution];
                if ($substrate) {
                    $unitScale *= 3;
                }
                $transVec->scale($unitScale);
                $this->add($transVec);
                $this->normalize();

                if ($substrate && $this->i + $this->j + $this->k == $maxDimension) {
                    $this->lastOverage = 1;
                }
            }
        }

        return $newFaceId;
    }

    /**
     * Гексагональные координаты в плоские, относительно центра гексагона
     */
    public function convertTo2D(): GeoCoordinates {
        return new GeoCoordinates(
            ($this->i - $this->k) - 0.5 * ($this->j - $this->k),
            ($this->j - $this->k) * sqrt(3) / 2,
            false
        );
    }

    public function rotate60CounterClockwise(): void {
        $iVec = new HexagonCoordinates(1, 1, 0);
        $jVec = new HexagonCoordinates(0, 1, 1);
        $kVec = new HexagonCoordinates(1, 0, 1);

        $iVec->scale($this->i);
        $jVec->scale($this->j);
        $kVec->scale($this->k);

        $iVec->add($jVec);
        $iVec->add($kVec);

        $iVec->normalize();

        $this->i = $iVec->i;
        $this->j = $iVec->j;
        $this->k = $iVec->k;
    }

    public function rotate60Clockwise(): void {
        $iVec = new HexagonCoordinates(1, 0, 1);
        $jVec = new HexagonCoordinates(1, 1, 0);
        $kVec = new HexagonCoordinates(0, 1, 1);

        $iVec->scale($this->i);
        $jVec->scale($this->j);
        $kVec->scale($this->k);

        $iVec->add($jVec);
        $iVec->add($kVec);

        $iVec->normalize();

        $this->i = $iVec->i;
        $this->j = $iVec->j;
        $this->k = $iVec->k;
    }

    // Координаты центра ближайшей ячейки следующего разрешения для Class II ячейки
    public function ap7ChildCounterClockwise(): HexagonCoordinates {
        $iVec = new HexagonCoordinates(3, 0, 1);
        $jVec = new HexagonCoordinates(1, 3, 0);
        $kVec = new HexagonCoordinates(0, 1, 3);

        $iVec->scale($this->i);
        $jVec->scale($this->j);
        $kVec->scale($this->k);

        $iVec->add($jVec);
        $iVec->add($kVec);

        $iVec->normalize();
        return $iVec;
    }

    // Координаты центра ближайшей ячейки следующего разрешения для Class III ячейки
    public function ap7ChildClockwise(): HexagonCoordinates {
        $iVec = new HexagonCoordinates(3, 1, 0);
        $jVec = new HexagonCoordinates(0, 3, 1);
        $kVec = new HexagonCoordinates(1, 0, 3);

        $iVec->scale($this->i);
        $jVec->scale($this->j);
        $kVec->scale($this->k);

        $iVec->add($jVec);
        $iVec->add($kVec);
        $iVec->normalize();

        return $iVec;
    }

    // Переводит декартовы координаты в шестиугольный базис IJK
    public static function createFrom2dCoordinates(float $x, float $y): HexagonCoordinates {
        $a1 = abs($x);
        $a2 = abs($y);

        // Декартовы поворачиваем на 60 градусов
        $x2 = $a2 / sin(H3Utils::PI / 3);
        $x1 = $a1 + $x2 / 2.0;

        // Приводим к целочисленным координатам
        $i = (int) $x1;
        $j = (int) $x2;

        // Дробные части координат округляем нестандартно
        $r1 = $x1 - $i;
        $r2 = $x2 - $j;
        if ($r1 < 0.5) {
            if ($r1 < 1 / 3) {
                if ($r2 >= (1.0 + $r1) / 2.0) {
                    $j++;
                }
            } else {
                if ($r2 >= (1.0 - $r1)) {
                    $j++;
                }

                if ((1.0 - $r1) <= $r2 && $r2 < (2.0 * $r1)) {
                    $i++;
                }
            }
        } else {
            if ($r1 < 2 / 3) {
                if ($r2 >= (1.0 - $r1)) {
                    $j++;
                }

                if ((2.0 * $r1 - 1.0) >= $r2 || $r2 >= (1.0 - $r1)) {
                    $i++;
                }
            } else {
                $i++;
                if ($r2 >= ($r1 / 2.0)) {
                    $j++;
                }
            }
        }

        // Коррекция для отрицательных исходных координат
        if ($x < 0.0) {
            $diff = $i - (int) (($j + 1) / 2);
            $i    = $i - (2.0 * $diff + ($j % 2));
        }

        if ($y < 0.0) {
            $i = $i - (int) ((2 * $j + 1) / 2);
            $j = -1 * $j;
        }

        $hexagonCoordinates = new HexagonCoordinates($i, $j, 0);
        $hexagonCoordinates->normalize();
        return $hexagonCoordinates;
    }

    // Максимальные расстояния между гексагонами (в гексагонах) для соответствующего разрешения
    // Значения для разрешений 3-го класса не используются
    private const MAXIMUM_CLASS_II_DIMENTIONS = [
        0  => 2,
        2  => 14,
        4  => 98,
        6  => 686,
        8  => 4802,
        10 => 33614,
        12 => 235298,
        14 => 1647086,
        16 => 11529602,
    ];

    // Масштабный коэффициент относительно нулевого разрешения
    // Значения для разрешений 3-го класса не используются
    private const UNIT_SCALE_BY_CLASS_II_RESOLUTION = [
        0  => 1,
        2  => 7,
        4  => 49,
        6  => 343,
        8  => 2401,
        10 => 16807,
        12 => 117649,
        14 => 823543,
        16 => 5764801,
    ];

    // Определение соседних плоскостей в формате
    // [Id новой плоскости, [HexagonCoordinates для смещения], повороты (которые я так и не раскурил)]
    private const NEIGHBOR_FACES = [
        [
            [0, [0, 0, 0], 0],
            [4, [2, 0, 2], 1],
            [1, [2, 2, 0], 5],
            [5, [0, 2, 2], 3],
        ],
        [
            [1, [0, 0, 0], 0],
            [0, [2, 0, 2], 1],
            [2, [2, 2, 0], 5],
            [6, [0, 2, 2], 3],
        ],
        [
            [2, [0, 0, 0], 0],
            [1, [2, 0, 2], 1],
            [3, [2, 2, 0], 5],
            [7, [0, 2, 2], 3],
        ],
        [
            [3, [0, 0, 0], 0],
            [2, [2, 0, 2], 1],
            [4, [2, 2, 0], 5],
            [8, [0, 2, 2], 3],
        ],
        [
            [4, [0, 0, 0], 0],
            [3, [2, 0, 2], 1],
            [0, [2, 2, 0], 5],
            [9, [0, 2, 2], 3],
        ],
        [
            [5, [0, 0, 0], 0],
            [10, [2, 2, 0], 3],
            [14, [2, 0, 2], 3],
            [0, [0, 2, 2], 3],
        ],
        [
            [6, [0, 0, 0], 0],
            [11, [2, 2, 0], 3],
            [10, [2, 0, 2], 3],
            [1, [0, 2, 2], 3],
        ],
        [
            [7, [0, 0, 0], 0],
            [12, [2, 2, 0], 3],
            [11, [2, 0, 2], 3],
            [2, [0, 2, 2], 3],
        ],
        [
            [8, [0, 0, 0], 0],
            [13, [2, 2, 0], 3],
            [12, [2, 0, 2], 3],
            [3, [0, 2, 2], 3],
        ],
        [
            [9, [0, 0, 0], 0],
            [14, [2, 2, 0], 3],
            [13, [2, 0, 2], 3],
            [4, [0, 2, 2], 3],
        ],
        [
            [10, [0, 0, 0], 0],
            [5, [2, 2, 0], 3],
            [6, [2, 0, 2], 3],
            [15, [0, 2, 2], 3],
        ],
        [
            [11, [0, 0, 0], 0],
            [6, [2, 2, 0], 3],
            [7, [2, 0, 2], 3],
            [16, [0, 2, 2], 3],
        ],
        [
            [12, [0, 0, 0], 0],
            [7, [2, 2, 0], 3],
            [8, [2, 0, 2], 3],
            [17, [0, 2, 2], 3],
        ],
        [
            [13, [0, 0, 0], 0],
            [8, [2, 2, 0], 3],
            [9, [2, 0, 2], 3],
            [18, [0, 2, 2], 3],
        ],
        [
            [14, [0, 0, 0], 0],
            [9, [2, 2, 0], 3],
            [5, [2, 0, 2], 3],
            [19, [0, 2, 2], 3],
        ],
        [
            [15, [0, 0, 0], 0],
            [16, [2, 0, 2], 1],
            [19, [2, 2, 0], 5],
            [10, [0, 2, 2], 3],
        ],
        [
            [16, [0, 0, 0], 0],
            [17, [2, 0, 2], 1],
            [15, [2, 2, 0], 5],
            [11, [0, 2, 2], 3],
        ],
        [
            [17, [0, 0, 0], 0],
            [18, [2, 0, 2], 1],
            [16, [2, 2, 0], 5],
            [12, [0, 2, 2], 3],
        ],
        [
            [18, [0, 0, 0], 0],
            [19, [2, 0, 2], 1],
            [17, [2, 2, 0], 5],
            [13, [0, 2, 2], 3],
        ],
        [
            [19, [0, 0, 0], 0],
            [15, [2, 0, 2], 1],
            [18, [2, 2, 0], 5],
            [14, [0, 2, 2], 3],
        ],
    ];
}
